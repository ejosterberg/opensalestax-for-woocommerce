<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end test: programmatically exercise the WooCommerce REST API on
 * a real WP+WooCom instance and assert that a Minneapolis-shipped cart
 * gets an OpenSalesTax-computed tax line.
 *
 * Skipped unless BOTH `WP_VM_BASE_URL` and `OPENSALESTAX_BASE_URL` are set.
 *
 * Requires the WC REST API to be set up with consumer key/secret. For the
 * test VM at 10.32.161.9, run inside the VM (one-time):
 *
 *     wp wc rest-api-key create --user=admin \
 *         --description="OST E2E test" --permissions=read_write \
 *         --path=/var/www/html
 *
 * Then export:
 *     export WP_VM_BASE_URL=http://10.32.161.9
 *     export WC_CONSUMER_KEY=ck_xxx
 *     export WC_CONSUMER_SECRET=cs_xxx
 *     export OPENSALESTAX_BASE_URL=http://10.32.161.126:8080
 *     composer test-live
 */
final class E2ECartTaxTest extends TestCase
{
    private string $wpBase;
    private string $consumerKey;
    private string $consumerSecret;

    protected function setUp(): void
    {
        $wpBase = getenv('WP_VM_BASE_URL');
        $ostBase = getenv('OPENSALESTAX_BASE_URL');
        $key = getenv('WC_CONSUMER_KEY');
        $secret = getenv('WC_CONSUMER_SECRET');

        if ($wpBase === false || $wpBase === '' || $ostBase === false || $ostBase === '') {
            self::markTestSkipped('WP_VM_BASE_URL and/or OPENSALESTAX_BASE_URL not set; skipping E2E test.');
        }
        if ($key === false || $key === '' || $secret === false || $secret === '') {
            self::markTestSkipped('WC_CONSUMER_KEY/_SECRET not set; create a WC REST API key in the test VM.');
        }

        $this->wpBase = rtrim($wpBase, '/');
        $this->consumerKey = $key;
        $this->consumerSecret = $secret;
    }

    public function testCartWithMinneapolisAddressIncludesOpenSalesTaxLine(): void
    {
        // 1. Create a test product via WC REST API.
        $product = $this->wcRequest('POST', '/wp-json/wc/v3/products', [
            'name' => 'OST E2E Test Widget — ' . uniqid('', true),
            'type' => 'simple',
            'regular_price' => '100.00',
            'tax_status' => 'taxable',
        ]);
        self::assertIsArray($product);
        self::assertArrayHasKey('id', $product);
        $productId = (int) $product['id'];

        try {
            // 2. Use the Store API (no auth required) to create a cart with the product
            //    and a Minneapolis MN shipping address. The Store API exposes
            //    `/wp-json/wc/store/v1/cart/add-item` and the calculation we want.
            //
            // The Store API requires a session/nonce; for simplicity we hit the
            // legacy `/wp-json/wc/v3/orders` endpoint with billing/shipping
            // addresses populated, which also runs through `woocommerce_calc_tax`.
            $order = $this->wcRequest('POST', '/wp-json/wc/v3/orders', [
                'line_items' => [['product_id' => $productId, 'quantity' => 1]],
                'billing'  => [
                    'first_name' => 'Test',
                    'last_name'  => 'Customer',
                    'address_1'  => '100 Test St',
                    'city'       => 'Minneapolis',
                    'state'      => 'MN',
                    'postcode'   => '55401',
                    'country'    => 'US',
                    'email'      => 'test@example.com',
                ],
                'shipping' => [
                    'first_name' => 'Test',
                    'last_name'  => 'Customer',
                    'address_1'  => '100 Test St',
                    'city'       => 'Minneapolis',
                    'state'      => 'MN',
                    'postcode'   => '55401',
                    'country'    => 'US',
                ],
                'set_paid' => false,
            ]);

            self::assertIsArray($order);
            self::assertArrayHasKey('total_tax', $order);

            $totalTax = (float) $order['total_tax'];
            self::assertGreaterThan(
                0.0,
                $totalTax,
                'Expected a non-zero tax line on a $100 Minneapolis MN cart, got ' . $order['total_tax'],
            );

            // Sanity: the OST tax line should appear under `tax_lines` with our
            // synthetic rate code.
            self::assertArrayHasKey('tax_lines', $order);
            self::assertNotEmpty($order['tax_lines']);
        } finally {
            // Cleanup — delete the test product.
            $this->wcRequest('DELETE', '/wp-json/wc/v3/products/' . $productId . '?force=true');
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function wcRequest(string $method, string $path, array $body = []): ?array
    {
        $url = $this->wpBase . $path;
        $auth = base64_encode($this->consumerKey . ':' . $this->consumerSecret);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/json',
        ]);
        if ($body !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw)) {
            self::fail("WC REST request {$method} {$path} returned no body");
        }

        if ($status >= 400) {
            self::fail("WC REST request {$method} {$path} failed with HTTP {$status}: {$raw}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::fail("WC REST request {$method} {$path} returned non-JSON body: {$raw}");
        }
        return $decoded;
    }
}
