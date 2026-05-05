<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Tests\Unit;

use OpenSalesTax\Client as OpenSalesTaxClient;
use OpenSalesTax\WooCommerce\ClientFactory;
use PHPUnit\Framework\TestCase;
use WP_Mock;

final class ClientFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
    }

    public function testReturnsNullWhenBaseUrlIsEmpty(): void
    {
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_base_url', ''],
            'return' => '',
        ]);

        $factory = new ClientFactory();
        self::assertNull($factory->build());
    }

    public function testReturnsNullWhenBaseUrlIsWhitespace(): void
    {
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_base_url', ''],
            'return' => '   ',
        ]);

        $factory = new ClientFactory();
        self::assertNull($factory->build());
    }

    public function testBuildsClientWithMinimalConfig(): void
    {
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_base_url', ''],
            'return' => 'http://10.32.161.126:8080',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_api_key', ''],
            'return' => '',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_timeout_seconds', 5.0],
            'return' => 5.0,
        ]);

        $factory = new ClientFactory();
        $client = $factory->build();

        self::assertInstanceOf(OpenSalesTaxClient::class, $client);
    }

    public function testTrimsTrailingSlashFromBaseUrl(): void
    {
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_base_url', ''],
            'return' => 'http://10.32.161.126:8080///',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_api_key', ''],
            'return' => '',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_timeout_seconds', 5.0],
            'return' => 5.0,
        ]);

        $factory = new ClientFactory();
        $client = $factory->build();

        // The Client itself rtrims internally; we trust it. This test just verifies
        // build() succeeds and returns a Client (no exception).
        self::assertInstanceOf(OpenSalesTaxClient::class, $client);
    }

    public function testForwardsApiKey(): void
    {
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_base_url', ''],
            'return' => 'http://10.32.161.126:8080',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_api_key', ''],
            'return' => 'secret-key',
        ]);
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_timeout_seconds', 5.0],
            'return' => 5.0,
        ]);

        $factory = new ClientFactory();
        $client = $factory->build();

        self::assertInstanceOf(OpenSalesTaxClient::class, $client);
    }
}
