<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Tests\Unit;

use OpenSalesTax\WooCommerce\Settings;
use OpenSalesTax\WooCommerce\TaxClassMap;
use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * Tests Settings::saveTaxClassMap — the form-submit handler that
 * persists merchant overrides from the new tax-class table UI.
 *
 * The renderer itself echoes HTML and is best validated by smoke-test
 * on a live site; the save logic is the riskier surface (capability +
 * input validation), so the tests focus there.
 */
final class SettingsTaxClassSaveTest extends TestCase
{
    protected function setUp(): void
    {
        WP_Mock::setUp();
        $_POST = [];
        $_REQUEST = [];
        // Default: every test mocks wp_verify_nonce as passing unless it
        // expects a rejection. wp_unslash + sanitize_text_field are stubs
        // — they just return the input.
        WP_Mock::userFunction('wp_verify_nonce', ['return' => 1]);
        WP_Mock::userFunction('wp_unslash', ['return_arg' => 0]);
        WP_Mock::userFunction('sanitize_text_field', ['return_arg' => 0]);
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
        $_POST = [];
        $_REQUEST = [];
    }

    /**
     * Helper — set the standard pass-through nonce in $_REQUEST so the
     * handler's defense-in-depth nonce check passes.
     */
    private function setValidNonce(): void
    {
        $_REQUEST['_wpnonce'] = 'pass';
    }

    public function testSaveRejectsBadNonce(): void
    {
        WP_Mock::userFunction('current_user_can', [
            'args' => ['manage_woocommerce'],
            'return' => true,
        ]);
        // Re-define wp_verify_nonce to fail for this test.
        WP_Mock::userFunction('wp_verify_nonce', ['return' => false]);
        // No update_option / delete_option mock — we expect neither.

        $_REQUEST['_wpnonce'] = 'forged';
        $_POST = [
            'opensalestax_tax_class_map' => ['clothing' => 'clothing'],
        ];

        $settings = new Settings();
        $settings->saveTaxClassMap();
        $this->addToAssertionCount(1);
    }

    public function testSaveRejectsWithoutCapability(): void
    {
        WP_Mock::userFunction('current_user_can', [
            'args' => ['manage_woocommerce'],
            'return' => false,
        ]);
        // No update_option / delete_option mock — we expect neither.

        $this->setValidNonce();
        $settings = new Settings();
        $settings->saveTaxClassMap();
        $this->addToAssertionCount(1);
    }

    public function testSaveResetsWhenCheckboxChecked(): void
    {
        WP_Mock::userFunction('current_user_can', [
            'args' => ['manage_woocommerce'],
            'return' => true,
        ]);
        WP_Mock::userFunction('delete_option', [
            'times' => 1,
            'args' => [TaxClassMap::OPTION_KEY],
            'return' => true,
        ]);

        $this->setValidNonce();
        $_POST = ['opensalestax_tax_class_map_reset' => '1'];

        $settings = new Settings();
        $settings->saveTaxClassMap();
        $this->addToAssertionCount(1);
    }

    public function testSaveNoOpsWhenNoMapPosted(): void
    {
        WP_Mock::userFunction('current_user_can', [
            'args' => ['manage_woocommerce'],
            'return' => true,
        ]);

        $this->setValidNonce();
        $settings = new Settings();
        $settings->saveTaxClassMap();
        $this->addToAssertionCount(1);
    }

    public function testSavePersistsValidEntries(): void
    {
        WP_Mock::userFunction('current_user_can', [
            'args' => ['manage_woocommerce'],
            'return' => true,
        ]);

        $captured = null;
        WP_Mock::userFunction('update_option', [
            'times' => 1,
            'return' => function (string $name, mixed $value) use (&$captured) {
                if ($name === TaxClassMap::OPTION_KEY) {
                    $captured = $value;
                }
                return true;
            },
        ]);

        $this->setValidNonce();
        $_POST = [
            'opensalestax_tax_class_map' => [
                'clothing' => 'clothing',
                'gift-cards' => '',  // skip
                '' => 'general',
            ],
        ];

        $settings = new Settings();
        $settings->saveTaxClassMap();

        self::assertIsString($captured);
        $decoded = json_decode($captured, true);
        self::assertSame('clothing', $decoded['clothing']);
        self::assertSame('', $decoded['gift-cards']);
        self::assertSame('general', $decoded['']);
    }

    public function testSaveDropsInvalidCategoriesSilently(): void
    {
        WP_Mock::userFunction('current_user_can', [
            'args' => ['manage_woocommerce'],
            'return' => true,
        ]);

        $captured = null;
        WP_Mock::userFunction('update_option', [
            'times' => 1,
            'return' => function (string $name, mixed $value) use (&$captured) {
                if ($name === TaxClassMap::OPTION_KEY) {
                    $captured = $value;
                }
                return true;
            },
        ]);

        $this->setValidNonce();
        $_POST = [
            'opensalestax_tax_class_map' => [
                'clothing' => 'clothing',     // valid
                'evil' => 'not-a-category',   // invalid → dropped
                'fine' => 'groceries',        // valid
            ],
        ];

        $settings = new Settings();
        $settings->saveTaxClassMap();

        $decoded = json_decode($captured, true);
        self::assertSame('clothing', $decoded['clothing']);
        self::assertSame('groceries', $decoded['fine']);
        self::assertArrayNotHasKey('evil', $decoded);
    }

    public function testSaveAcceptsAllValidCategories(): void
    {
        WP_Mock::userFunction('current_user_can', [
            'args' => ['manage_woocommerce'],
            'return' => true,
        ]);

        $captured = null;
        WP_Mock::userFunction('update_option', [
            'times' => 1,
            'return' => function (string $name, mixed $value) use (&$captured) {
                if ($name === TaxClassMap::OPTION_KEY) {
                    $captured = $value;
                }
                return true;
            },
        ]);

        $map = [];
        foreach (TaxClassMap::VALID_CATEGORIES as $i => $cat) {
            $map['class' . $i] = $cat;
        }
        $this->setValidNonce();
        $_POST = ['opensalestax_tax_class_map' => $map];

        $settings = new Settings();
        $settings->saveTaxClassMap();

        $decoded = json_decode($captured, true);
        self::assertCount(count(TaxClassMap::VALID_CATEGORIES), $decoded);
        foreach (TaxClassMap::VALID_CATEGORIES as $cat) {
            self::assertContains($cat, array_values($decoded));
        }
    }
}
