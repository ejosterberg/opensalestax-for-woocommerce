<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Tests\Unit;

use OpenSalesTax\WooCommerce\ClientFactory;
use OpenSalesTax\WooCommerce\OrderTaxBreakdown;
use OpenSalesTax\WooCommerce\SubscriptionsBridge;
use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * The "WC Subscriptions not installed" tests must run before any test that
 * eval-declares the WC_Subscriptions stub class — once defined, the class
 * persists for the rest of the process. Test methods are sorted alphabetically
 * by PHPUnit, so the `*NotInstalled*` and `*WithoutSubscriptions*` tests must
 * sort before everything else. The `t1` / `t2` / ... prefix makes the order
 * explicit and keeps later edits from accidentally breaking it.
 */
final class SubscriptionsBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
    }

    public function testA01IsSubscriptionsActiveFalseWhenNotInstalled(): void
    {
        self::assertFalse(SubscriptionsBridge::isSubscriptionsActive());
    }

    public function testA02RegisterIsNoOpWithoutSubscriptions(): void
    {
        // No WC_Subscriptions class loaded → register() should not call add_action.
        // We don't mock add_action; if it tried to fire, WP_Mock would fail.
        $bridge = new SubscriptionsBridge(
            new OrderTaxBreakdown($this->factoryThatShouldNotBeCalled()),
        );
        $bridge->register();
        $this->addToAssertionCount(1);
    }

    public function testB01IsSubscriptionsActiveTrueWhenStubLoaded(): void
    {
        if (!class_exists('WC_Subscriptions')) {
            eval('class WC_Subscriptions {}');
        }
        self::assertTrue(SubscriptionsBridge::isSubscriptionsActive());
    }

    public function testB02RegisterHooksRenewalActionWhenSubscriptionsActive(): void
    {
        if (!class_exists('WC_Subscriptions')) {
            eval('class WC_Subscriptions {}');
        }
        // We just want to verify add_action gets called for our hook —
        // exact callable matching is fragile, so accept any args.
        WP_Mock::userFunction('add_action', [
            'times' => '>=1',
        ]);

        $bridge = new SubscriptionsBridge(
            new OrderTaxBreakdown($this->factoryThatShouldNotBeCalled()),
        );
        $bridge->register();
        $this->addToAssertionCount(1);
    }

    public function testRecalcCallsCalculateTaxesAndSaves(): void
    {
        // Stub a renewal order that records each method call.
        $calls = (object) ['calls' => []];
        $renewalOrder = new class ($calls) {
            public function __construct(private readonly object $calls)
            {
            }
            public function calculate_taxes(): void
            {
                $this->calls->calls[] = 'calculate_taxes';
            }
            public function calculate_totals(): void
            {
                $this->calls->calls[] = 'calculate_totals';
            }
            public function save(): void
            {
                $this->calls->calls[] = 'save';
            }
            public function get_shipping_postcode(): string
            {
                return '';  // forces buildPayload to bail before engine call
            }
            public function get_billing_postcode(): string
            {
                return '';
            }
            public function get_meta(string $key, bool $single = true): string
            {
                return '';
            }
        };

        $bridge = new SubscriptionsBridge(
            new OrderTaxBreakdown($this->factoryThatShouldNotBeCalled()),
        );
        $bridge->recalcRenewalTax($renewalOrder, null);

        self::assertContains('calculate_taxes', $calls->calls);
        self::assertContains('calculate_totals', $calls->calls);
        self::assertContains('save', $calls->calls);
    }

    public function testRecalcSurvivesCalculateThrows(): void
    {
        $renewalOrder = new class () {
            public function calculate_taxes(): void
            {
                throw new \RuntimeException('engine down');
            }
            public function get_shipping_postcode(): string
            {
                return '';
            }
            public function get_billing_postcode(): string
            {
                return '';
            }
            public function get_meta(string $key, bool $single = true): string
            {
                return '';
            }
        };

        $bridge = new SubscriptionsBridge(
            new OrderTaxBreakdown($this->factoryThatShouldNotBeCalled()),
        );
        // Should not throw despite calculate_taxes blowing up.
        $bridge->recalcRenewalTax($renewalOrder, null);
        $this->addToAssertionCount(1);
    }

    public function testRecalcSkipsNonOrderObject(): void
    {
        $bridge = new SubscriptionsBridge(
            new OrderTaxBreakdown($this->factoryThatShouldNotBeCalled()),
        );
        // Non-object first arg → silent return.
        $bridge->recalcRenewalTax('not-an-order', null);
        $this->addToAssertionCount(1);
    }

    private function factoryThatShouldNotBeCalled(): ClientFactory
    {
        return new class () extends ClientFactory {
            public function build(): ?\OpenSalesTax\Client
            {
                throw new \RuntimeException('Factory should not have been called for this test');
            }
        };
    }
}
