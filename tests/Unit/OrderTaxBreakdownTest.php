<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenSalesTax\Client as OpenSalesTaxClient;
use OpenSalesTax\WooCommerce\ClientFactory;
use OpenSalesTax\WooCommerce\OrderTaxBreakdown;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as Psr18Client;
use WP_Mock;

final class OrderTaxBreakdownTest extends TestCase
{
    protected function setUp(): void
    {
        WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
    }

    public function testCaptureSerializesEngineResultIntoMeta(): void
    {
        $engineResponse = [
            'subtotal' => '100.00',
            'tax_total' => '9.025',
            'disclaimer' => 'Calculation only',
            'lines' => [
                [
                    'amount' => '100.00',
                    'category' => 'general',
                    'tax' => '9.025',
                    'rate_pct' => '9.025',
                    'jurisdictions' => [
                        ['type' => 'state', 'name' => 'Minnesota', 'rate_pct' => '6.875', 'tax' => '6.875'],
                        ['type' => 'county', 'name' => 'Hennepin County', 'rate_pct' => '0.150', 'tax' => '0.150'],
                        ['type' => 'city', 'name' => 'Minneapolis', 'rate_pct' => '0.500', 'tax' => '0.500'],
                    ],
                ],
            ],
        ];
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_tax_class_map', ''],
            'return' => '',
        ]);

        $capture = (object) ['value' => null];
        $order = $this->stubOrder('55401', '', [['total' => 100.0, 'tax_class' => '']], $capture);

        $breakdown = new OrderTaxBreakdown($this->factoryReturning($engineResponse));
        $breakdown->captureOnOrderCreate($order, []);

        self::assertNotNull($capture->value);
        $decoded = json_decode($capture->value, true);
        self::assertIsArray($decoded);
        self::assertSame('100.00', $decoded['subtotal']);
        self::assertSame('9.025', $decoded['tax_total']);
        self::assertCount(1, $decoded['lines']);
        self::assertSame('general', $decoded['lines'][0]['category']);
        self::assertCount(3, $decoded['lines'][0]['jurisdictions']);
        self::assertSame('Minnesota', $decoded['lines'][0]['jurisdictions'][0]['name']);
        self::assertSame('6.875', $decoded['lines'][0]['jurisdictions'][0]['rate_pct']);
    }

    public function testCaptureSkipsWhenNoUSZip(): void
    {
        $capture = (object) ['value' => null];
        $order = $this->stubOrder('', '', [['total' => 50.0, 'tax_class' => '']], $capture);

        $breakdown = new OrderTaxBreakdown($this->factoryThatShouldNotBeCalled());
        $breakdown->captureOnOrderCreate($order, []);

        self::assertNull($capture->value);
    }

    public function testCaptureFallsBackToBillingZip(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_tax_class_map', ''],
            'return' => '',
        ]);

        $engineResponse = [
            'subtotal' => '50.00',
            'tax_total' => '0',
            'disclaimer' => '',
            'lines' => [
                [
                    'amount' => '50.00',
                    'category' => 'general',
                    'tax' => '0',
                    'rate_pct' => '0',
                    'jurisdictions' => [],
                ],
            ],
        ];

        $capture = (object) ['value' => null];
        $order = $this->stubOrder('', '90210', [['total' => 50.0, 'tax_class' => '']], $capture);

        $breakdown = new OrderTaxBreakdown($this->factoryReturning($engineResponse));
        $breakdown->captureOnOrderCreate($order, []);

        self::assertNotNull($capture->value);
    }

    public function testCaptureSkipsZeroRateLines(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_tax_class_map', ''],
            'return' => '',
        ]);

        $capture = (object) ['value' => null];
        // All lines are zero-rate → no taxable lines → no engine call → no meta written.
        $order = $this->stubOrder('55401', '', [
            ['total' => 100.0, 'tax_class' => 'zero-rate'],
        ], $capture);

        $breakdown = new OrderTaxBreakdown($this->factoryThatShouldNotBeCalled());
        $breakdown->captureOnOrderCreate($order, []);

        self::assertNull($capture->value);
    }

    public function testCaptureNonFatalOnEngineError(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_tax_class_map', ''],
            'return' => '',
        ]);

        $capture = (object) ['value' => null];
        $order = $this->stubOrder('55401', '', [['total' => 100.0, 'tax_class' => '']], $capture);

        $breakdown = new OrderTaxBreakdown($this->factoryReturningHttpStatus(500));

        // Should not throw, even though the engine returned 500.
        $breakdown->captureOnOrderCreate($order, []);
        self::assertNull($capture->value);
    }

    public function testGetReturnsNullForOrderWithoutBreakdown(): void
    {
        $order = $this->stubOrderWithMeta('');
        self::assertNull(OrderTaxBreakdown::get($order));
    }

    public function testGetReturnsNullForMalformedJson(): void
    {
        $order = $this->stubOrderWithMeta('this is not json');
        self::assertNull(OrderTaxBreakdown::get($order));
    }

    public function testGetReturnsParsedArrayForValidMeta(): void
    {
        $payload = json_encode([
            'subtotal' => '100.00',
            'tax_total' => '9.025',
            'lines' => [
                ['category' => 'general', 'amount' => '100.00', 'tax' => '9.025', 'note' => null, 'jurisdictions' => []],
            ],
        ]);
        $order = $this->stubOrderWithMeta($payload);

        $result = OrderTaxBreakdown::get($order);
        self::assertIsArray($result);
        self::assertSame('9.025', $result['tax_total']);
        self::assertCount(1, $result['lines']);
    }

    public function testRenderHtmlIncludesAllJurisdictions(): void
    {
        $breakdown = [
            'subtotal' => '100.00',
            'tax_total' => '9.025',
            'lines' => [
                [
                    'category' => 'general',
                    'amount' => '100.00',
                    'tax' => '9.025',
                    'note' => null,
                    'jurisdictions' => [
                        ['type' => 'state', 'name' => 'Minnesota', 'rate_pct' => '6.875', 'tax' => '6.875'],
                        ['type' => 'city', 'name' => 'Minneapolis', 'rate_pct' => '0.500', 'tax' => '0.500'],
                    ],
                ],
            ],
        ];

        $html = OrderTaxBreakdown::renderHtml($breakdown);
        self::assertStringContainsString('Minnesota', $html);
        self::assertStringContainsString('Minneapolis', $html);
        self::assertStringContainsString('6.875', $html);
        self::assertStringContainsString('opensalestax-breakdown', $html);
    }

    public function testRenderHtmlIncludesNoteWhenPresent(): void
    {
        $breakdown = [
            'subtotal' => '100.00',
            'tax_total' => '0',
            'lines' => [
                [
                    'category' => 'clothing',
                    'amount' => '100.00',
                    'tax' => '0',
                    'note' => 'Clothing is non-taxable in Minnesota (Minn. Stat. 297A.67 subd 8).',
                    'jurisdictions' => [],
                ],
            ],
        ];

        $html = OrderTaxBreakdown::renderHtml($breakdown);
        self::assertStringContainsString('Clothing is non-taxable in Minnesota', $html);
    }

    public function testRenderHtmlEmptyForEmptyLines(): void
    {
        $html = OrderTaxBreakdown::renderHtml(['subtotal' => '0', 'tax_total' => '0', 'lines' => []]);
        self::assertSame('', $html);
    }

    public function testRefundCaptureProratesParentBreakdown(): void
    {
        $parentBreakdown = [
            'subtotal' => '100.00',
            'tax_total' => '9.0250',
            'lines' => [
                [
                    'category' => 'general',
                    'amount' => '100.00',
                    'tax' => '9.0250',
                    'note' => null,
                    'jurisdictions' => [
                        ['type' => 'state', 'name' => 'Minnesota', 'rate_pct' => '6.875', 'tax' => '6.8750'],
                        ['type' => 'city', 'name' => 'Minneapolis', 'rate_pct' => '0.500', 'tax' => '0.5000'],
                    ],
                ],
            ],
        ];

        // Refund is for $50 of a $109.025 parent total — ratio ≈ 0.4587.
        $parent = $this->stubRefundParent(json_encode($parentBreakdown), 109.025);
        $refund = $this->stubRefund(50.0, 999);

        $this->mockWcGetOrder([
            999 => $parent,
            555 => $refund,
        ]);

        $breakdown = new OrderTaxBreakdown($this->factoryThatShouldNotBeCalled());
        $breakdown->captureOnRefundCreate(555, []);

        self::assertNotNull($refund->captured);
        $stored = json_decode($refund->captured, true);
        self::assertIsArray($stored);
        // Refund tax_total should be NEGATIVE and proportional.
        self::assertLessThan(0, (float) $stored['tax_total']);
        // -9.025 * (50 / 109.025) ≈ -4.14
        self::assertEqualsWithDelta(-4.14, (float) $stored['tax_total'], 0.05);
        // Per-jurisdiction values should also be negative + proportional.
        self::assertLessThan(0, (float) $stored['lines'][0]['jurisdictions'][0]['tax']);
        // The note should mention the parent order.
        self::assertStringContainsString('Prorated from parent order #999', $stored['lines'][0]['note']);
    }

    public function testRefundCaptureNoOpWhenParentHasNoBreakdown(): void
    {
        // Parent order has no stored breakdown (pre-v0.3.0 order).
        $parent = $this->stubRefundParent('', 109.025);
        $refund = $this->stubRefund(50.0, 999);

        $this->mockWcGetOrder([
            999 => $parent,
            555 => $refund,
        ]);

        $breakdown = new OrderTaxBreakdown($this->factoryThatShouldNotBeCalled());
        $breakdown->captureOnRefundCreate(555, []);

        self::assertNull($refund->captured);
    }

    public function testRefundCaptureSkipsWhenParentTotalIsZero(): void
    {
        $parentBreakdown = ['subtotal' => '0.00', 'tax_total' => '0.0000', 'lines' => []];
        $parent = $this->stubRefundParent(json_encode($parentBreakdown), 0.0);
        $refund = $this->stubRefund(0.0, 999);

        $this->mockWcGetOrder([
            999 => $parent,
            555 => $refund,
        ]);

        $breakdown = new OrderTaxBreakdown($this->factoryThatShouldNotBeCalled());
        $breakdown->captureOnRefundCreate(555, []);

        self::assertNull($refund->captured);
    }

    public function testRenderHtmlEscapesUserContent(): void
    {
        // WP_Mock's default esc_html / esc_html__ are pass-through, which would
        // mask real escaping. Replace them with the production behavior so we
        // actually verify the rendering path calls them on user-controlled
        // values like jurisdiction name and engine note.
        $escape = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        WP_Mock::userFunction('esc_html', ['return' => $escape]);
        WP_Mock::userFunction('esc_html__', ['return' => $escape]);

        $breakdown = [
            'subtotal' => '100.00',
            'tax_total' => '9.025',
            'lines' => [
                [
                    'category' => 'general',
                    'amount' => '100.00',
                    'tax' => '9.025',
                    'note' => '<script>alert(1)</script>',
                    'jurisdictions' => [
                        ['type' => 'state', 'name' => '<b>Mn</b>', 'rate_pct' => '6.875', 'tax' => '6.875'],
                    ],
                ],
            ],
        ];

        $html = OrderTaxBreakdown::renderHtml($breakdown);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<b>Mn</b>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Build an order stub. The $capture object's `value` is set when
     * update_meta_data('_opensalestax_breakdown', ...) is called.
     *
     * @param array<int, array{total: float, tax_class: string}> $items
     */
    private function stubOrder(string $shippingZip, string $billingZip, array $items, object $capture)
    {
        $itemObjects = array_map(function (array $i) {
            return new class ($i) {
                public function __construct(private readonly array $i)
                {
                }
                public function get_total(): float
                {
                    return (float) $this->i['total'];
                }
                public function get_tax_class(): string
                {
                    return (string) $this->i['tax_class'];
                }
            };
        }, $items);

        return new class ($shippingZip, $billingZip, $itemObjects, $capture) {
            public function __construct(
                private readonly string $shippingZip,
                private readonly string $billingZip,
                private readonly array $items,
                private readonly object $capture,
            ) {
            }
            public function get_shipping_postcode(): string
            {
                return $this->shippingZip;
            }
            public function get_billing_postcode(): string
            {
                return $this->billingZip;
            }
            public function get_items(): array
            {
                return $this->items;
            }
            public function update_meta_data(string $key, string $value): void
            {
                if ($key === OrderTaxBreakdown::META_KEY) {
                    $this->capture->value = $value;
                }
            }
            public function get_meta(string $key, bool $single = true): string
            {
                return '';
            }
        };
    }

    private function stubOrderWithMeta(string $metaValue)
    {
        return new class ($metaValue) {
            public function __construct(private readonly string $metaValue)
            {
            }
            public function get_meta(string $key, bool $single = true): string
            {
                return $key === OrderTaxBreakdown::META_KEY ? $this->metaValue : '';
            }
        };
    }

    /**
     * Stub a parent order: returns the given JSON breakdown via get_meta and
     * the given numeric total via get_total. Used by the refund-prorating
     * tests.
     */
    private function stubRefundParent(string $metaValue, float $total)
    {
        return new class ($metaValue, $total) {
            public function __construct(
                private readonly string $metaValue,
                private readonly float $total,
            ) {
            }
            public function get_meta(string $key, bool $single = true): string
            {
                return $key === OrderTaxBreakdown::META_KEY ? $this->metaValue : '';
            }
            public function get_total(): float
            {
                return $this->total;
            }
        };
    }

    /**
     * Stub a refund object: WC_Order_Refund-shaped. The `captured` public
     * property records whatever JSON was passed to update_meta_data so the
     * test can assert on it.
     */
    private function stubRefund(float $refundTotal, int $parentId)
    {
        return new class ($refundTotal, $parentId) {
            public ?string $captured = null;
            public function __construct(
                private readonly float $refundTotal,
                private readonly int $parentId,
            ) {
            }
            public function get_total(): float
            {
                return -$this->refundTotal; // WC stores refund totals as negative
            }
            public function get_parent_id(): int
            {
                return $this->parentId;
            }
            public function update_meta_data(string $key, string $value): void
            {
                if ($key === OrderTaxBreakdown::META_KEY) {
                    $this->captured = $value;
                }
            }
            public function save(): void
            {
            }
        };
    }

    /**
     * Wire up `wc_get_order` to a static lookup table so the refund handler's
     * `wc_get_order($refundId)` and `wc_get_order($parentId)` calls return
     * our stubs.
     *
     * @param array<int, object> $idToOrder
     */
    private function mockWcGetOrder(array $idToOrder): void
    {
        WP_Mock::userFunction('wc_get_order', [
            'return' => function ($id) use ($idToOrder) {
                $key = (int) $id;
                return $idToOrder[$key] ?? false;
            },
        ]);
    }

    /**
     * @param array<string, mixed> $responseBody
     */
    private function factoryReturning(array $responseBody): ClientFactory
    {
        $http = $this->createMock(Psr18Client::class);
        $http->method('sendRequest')->willReturn(
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($responseBody, JSON_THROW_ON_ERROR)),
        );
        $client = new OpenSalesTaxClient(baseUrl: 'http://mock', httpClient: $http);
        return new class ($client) extends ClientFactory {
            public function __construct(private readonly OpenSalesTaxClient $client)
            {
            }
            public function build(): ?OpenSalesTaxClient
            {
                return $this->client;
            }
        };
    }

    private function factoryReturningHttpStatus(int $status): ClientFactory
    {
        $http = $this->createMock(Psr18Client::class);
        $http->method('sendRequest')->willReturn(
            new Response($status, ['Content-Type' => 'text/plain'], 'engine-side error'),
        );
        $client = new OpenSalesTaxClient(baseUrl: 'http://mock', httpClient: $http);
        return new class ($client) extends ClientFactory {
            public function __construct(private readonly OpenSalesTaxClient $client)
            {
            }
            public function build(): ?OpenSalesTaxClient
            {
                return $this->client;
            }
        };
    }

    private function factoryThatShouldNotBeCalled(): ClientFactory
    {
        return new class () extends ClientFactory {
            public function build(): ?OpenSalesTaxClient
            {
                throw new \RuntimeException('Factory should not have been called for this test');
            }
        };
    }
}
