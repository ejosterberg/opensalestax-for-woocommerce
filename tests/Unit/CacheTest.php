<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Tests\Unit;

use OpenSalesTax\WooCommerce\Cache;
use PHPUnit\Framework\TestCase;
use WP_Mock;

final class CacheTest extends TestCase
{
    protected function setUp(): void
    {
        WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
    }

    public function testGetReturnsNullOnTransientMiss(): void
    {
        WP_Mock::userFunction('get_transient', [
            'times' => 1,
            'return' => false,
        ]);

        $cache = new Cache();
        self::assertNull($cache->get('55401|general|10000'));
    }

    public function testGetReturnsCachedTaxArrayOnHit(): void
    {
        WP_Mock::userFunction('get_transient', [
            'times' => 1,
            'return' => ['opensalestax' => 8.025],
        ]);

        $cache = new Cache();
        $hit = $cache->get('55401|general|10000');

        self::assertSame(['opensalestax' => 8.025], $hit);
    }

    public function testGetReturnsNullForCorruptCachePayload(): void
    {
        // If the transient exists but isn't an associative array, treat as miss.
        WP_Mock::userFunction('get_transient', [
            'times' => 1,
            'return' => 'this is not an array',
        ]);

        $cache = new Cache();
        self::assertNull($cache->get('55401|general|10000'));
    }

    public function testSetCallsTransientWithDefaultTtl(): void
    {
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'args' => ['opensalestax_cache_ttl_minutes', 60],
            'return' => 60,
        ]);
        WP_Mock::userFunction('set_transient', [
            'times' => 1,
            'args' => [WP_Mock\Functions::type('string'), ['opensalestax' => 8.025], 3600],
            'return' => true,
        ]);

        $cache = new Cache();
        $cache->set('55401|general|10000', ['opensalestax' => 8.025]);
        $this->addToAssertionCount(1);  // mock expectations are the assertion
    }

    public function testSetSkipsWhenTtlIsZero(): void
    {
        WP_Mock::userFunction('get_option', [
            'times' => 1,
            'return' => 0,  // caching disabled
        ]);
        // Crucially, set_transient should NOT be called.
        WP_Mock::userFunction('set_transient', ['times' => 0]);

        $cache = new Cache();
        $cache->set('55401|general|10000', ['opensalestax' => 8.025]);
        $this->addToAssertionCount(1);
    }

    public function testSetUsesExplicitTtlOverride(): void
    {
        // No get_option call when an explicit TTL is passed.
        WP_Mock::userFunction('get_option', ['times' => 0]);
        WP_Mock::userFunction('set_transient', [
            'times' => 1,
            'args' => [WP_Mock\Functions::type('string'), ['opensalestax' => 1.0], 120],
            'return' => true,
        ]);

        $cache = new Cache();
        $cache->set('55401|general|10000', ['opensalestax' => 1.0], 120);
        $this->addToAssertionCount(1);
    }
}
