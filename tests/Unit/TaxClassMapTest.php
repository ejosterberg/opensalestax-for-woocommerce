<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Tests\Unit;

use OpenSalesTax\WooCommerce\TaxClassMap;
use PHPUnit\Framework\TestCase;
use WP_Mock;

final class TaxClassMapTest extends TestCase
{
    protected function setUp(): void
    {
        WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
    }

    public function testStandardClassMapsToGeneralByDefault(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [TaxClassMap::OPTION_KEY, ''],
            'return' => '',
        ]);

        self::assertSame('general', TaxClassMap::mapClassToCategory(''));
        self::assertSame('general', TaxClassMap::mapClassToCategory('standard'));
    }

    public function testReducedRateClassMapsToGeneralByDefault(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [TaxClassMap::OPTION_KEY, ''],
            'return' => '',
        ]);

        // Engine handles state-specific reduction; we still pass 'general'
        // and let the engine decide.
        self::assertSame('general', TaxClassMap::mapClassToCategory('reduced-rate'));
    }

    public function testZeroRateClassReturnsNullByDefault(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [TaxClassMap::OPTION_KEY, ''],
            'return' => '',
        ]);

        self::assertNull(TaxClassMap::mapClassToCategory('zero-rate'));
    }

    public function testCustomClassDefaultsToGeneral(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [TaxClassMap::OPTION_KEY, ''],
            'return' => '',
        ]);

        // No mapping configured for "clothing" yet → falls through to general.
        self::assertSame('general', TaxClassMap::mapClassToCategory('clothing'));
        self::assertSame('general', TaxClassMap::mapClassToCategory('alcohol'));
    }

    public function testCustomMappingOverridesDefault(): void
    {
        $customJson = json_encode(['clothing' => 'clothing', 'groceries' => 'groceries']);
        WP_Mock::userFunction('get_option', [
            'args' => [TaxClassMap::OPTION_KEY, ''],
            'return' => $customJson,
        ]);

        self::assertSame('clothing', TaxClassMap::mapClassToCategory('clothing'));
        self::assertSame('groceries', TaxClassMap::mapClassToCategory('groceries'));
    }

    public function testCustomSkipMapping(): void
    {
        $customJson = json_encode(['gift-cards' => '']);
        WP_Mock::userFunction('get_option', [
            'args' => [TaxClassMap::OPTION_KEY, ''],
            'return' => $customJson,
        ]);

        // Empty string → null (skip)
        self::assertNull(TaxClassMap::mapClassToCategory('gift-cards'));
    }

    public function testCustomMappingCanOverrideZeroRate(): void
    {
        // A merchant could legally re-map zero-rate to a real category if
        // their business requires it (rare but possible).
        $customJson = json_encode(['zero-rate' => 'general']);
        WP_Mock::userFunction('get_option', [
            'args' => [TaxClassMap::OPTION_KEY, ''],
            'return' => $customJson,
        ]);

        self::assertSame('general', TaxClassMap::mapClassToCategory('zero-rate'));
    }

    public function testMalformedOptionFallsBackToDefaults(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [TaxClassMap::OPTION_KEY, ''],
            'return' => 'this is not json',
        ]);

        // Malformed JSON → loadCustomMap returns []; defaults still apply.
        self::assertSame('general', TaxClassMap::mapClassToCategory('clothing'));
        self::assertNull(TaxClassMap::mapClassToCategory('zero-rate'));
    }

    public function testNonStringOptionFallsBackToDefaults(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [TaxClassMap::OPTION_KEY, ''],
            'return' => false,
        ]);

        self::assertSame('general', TaxClassMap::mapClassToCategory(''));
    }

    public function testSetWithValidCategory(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [TaxClassMap::OPTION_KEY, ''],
            'return' => '',
        ]);
        WP_Mock::userFunction('update_option', [
            'times' => 1,
            'return' => true,
        ]);

        TaxClassMap::set('clothing', 'clothing');
        $this->addToAssertionCount(1);  // mock expectations are the assertion
    }

    public function testSetWithSkipCategory(): void
    {
        WP_Mock::userFunction('get_option', [
            'args' => [TaxClassMap::OPTION_KEY, ''],
            'return' => '',
        ]);
        WP_Mock::userFunction('update_option', [
            'times' => 1,
            'return' => true,
        ]);

        TaxClassMap::set('gift-cards', '');
        $this->addToAssertionCount(1);
    }

    public function testSetWithInvalidCategoryThrows(): void
    {
        // esc_html() is called defensively on the user-supplied category in
        // the exception message; stub it pass-through for the test.
        WP_Mock::userFunction('esc_html', ['return_arg' => 0]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid OST category/');

        TaxClassMap::set('clothing', 'not-a-real-category');
    }

    public function testReset(): void
    {
        WP_Mock::userFunction('delete_option', [
            'times' => 1,
            'args' => [TaxClassMap::OPTION_KEY],
            'return' => true,
        ]);

        TaxClassMap::reset();
        $this->addToAssertionCount(1);
    }

    public function testLoadEffectiveMapMergesDefaultsWithCustom(): void
    {
        $customJson = json_encode(['clothing' => 'clothing', '' => 'digital_goods']);
        WP_Mock::userFunction('get_option', [
            'args' => [TaxClassMap::OPTION_KEY, ''],
            'return' => $customJson,
        ]);

        $effective = TaxClassMap::loadEffectiveMap();

        // Custom override wins for 'standard' (empty slug) and 'clothing'
        self::assertSame('digital_goods', $effective['']);
        self::assertSame('clothing', $effective['clothing']);
        // Defaults still present for unmodified entries
        self::assertSame('', $effective['zero-rate']);
        self::assertSame('general', $effective['reduced-rate']);
    }

    public function testValidCategoriesConstant(): void
    {
        // Sanity: the constant matches the engine's documented categories.
        self::assertContains('general', TaxClassMap::VALID_CATEGORIES);
        self::assertContains('clothing', TaxClassMap::VALID_CATEGORIES);
        self::assertContains('groceries', TaxClassMap::VALID_CATEGORIES);
        self::assertContains('prescription_drugs', TaxClassMap::VALID_CATEGORIES);
        self::assertContains('prepared_food', TaxClassMap::VALID_CATEGORIES);
        self::assertContains('digital_goods', TaxClassMap::VALID_CATEGORIES);
        self::assertCount(6, TaxClassMap::VALID_CATEGORIES);
    }
}
