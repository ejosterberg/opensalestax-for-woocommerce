<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Maps WooCommerce tax-class slugs to OpenSalesTax categories.
 *
 * WC ships with three built-in tax classes:
 *   - "Standard" (slug: ``)
 *   - "Reduced rate" (slug: `reduced-rate`)
 *   - "Zero rate" (slug: `zero-rate`)
 *
 * Merchants can also define custom classes (e.g. "Clothing", "Groceries").
 * Until v0.1.x we hardcoded those — every taxable line resolved to the
 * `general` OST category, which produces incorrect results for real
 * stores: clothing in Minnesota IS non-taxable, prepared food has its own
 * rate stack in some states, etc.
 *
 * v0.2.0 introduces a dynamic mapping. Defaults cover WC's built-in
 * classes; custom classes default to `general` until the merchant
 * configures them (via `wp opensalestax tax-class-set <class> <category>`
 * or by editing the `opensalestax_tax_class_map` option).
 *
 * Valid OST categories per engine v0.24+:
 *   `general`, `clothing`, `groceries`, `prescription_drugs`,
 *   `prepared_food`, `digital_goods`
 *
 * Special: empty string (`''`) means "skip this line — explicitly
 * non-taxable" (mirrors WC's zero-rate semantics).
 */
final class TaxClassMap
{
    public const OPTION_KEY = 'opensalestax_tax_class_map';

    public const SKIP_CATEGORY = '';
    public const FALLBACK_CATEGORY = 'general';

    /**
     * @var array<int, string> The OST categories the engine accepts.
     */
    public const VALID_CATEGORIES = [
        'general',
        'clothing',
        'groceries',
        'prescription_drugs',
        'prepared_food',
        'digital_goods',
    ];

    /**
     * Built-in defaults for WC's three standard classes. Custom classes
     * fall through to FALLBACK_CATEGORY (`general`).
     *
     * @var array<string, string>
     */
    private const DEFAULT_MAP = [
        ''             => 'general',         // WC's "Standard" — empty slug
        'standard'     => 'general',         // alternate slug some installs use
        'reduced-rate' => 'general',         // engine handles state-specific reductions
        'zero-rate'    => self::SKIP_CATEGORY,
    ];

    /**
     * Resolve a WC tax-class slug to an OST category.
     *
     * Returns null to signal "skip this line — no tax". Returns a string
     * (one of VALID_CATEGORIES) when there's a positive mapping or the
     * default fallback applies.
     */
    public static function mapClassToCategory(string $wcClassSlug): ?string
    {
        $effective = self::loadEffectiveMap();
        $cat = $effective[$wcClassSlug] ?? self::FALLBACK_CATEGORY;
        return $cat === self::SKIP_CATEGORY ? null : $cat;
    }

    /**
     * Persist a single mapping for one WC tax-class slug.
     *
     * Pass an empty string for `$ostCategory` to mark the class as
     * "non-taxable, skip" (same effect as WC's zero-rate built-in).
     *
     * @throws \InvalidArgumentException if the OST category isn't valid.
     */
    public static function set(string $wcClassSlug, string $ostCategory): void
    {
        if ($ostCategory !== self::SKIP_CATEGORY && !in_array($ostCategory, self::VALID_CATEGORIES, true)) {
            throw new \InvalidArgumentException(
                "Invalid OST category: '{$ostCategory}'. Valid: "
                    . implode(', ', self::VALID_CATEGORIES)
                    . ", or '' to skip (non-taxable).",
            );
        }
        $custom = self::loadCustomMap();
        $custom[$wcClassSlug] = $ostCategory;
        $encoded = wp_json_encode($custom);
        update_option(self::OPTION_KEY, $encoded === false ? '' : $encoded);
    }

    /**
     * Reset all custom mappings; subsequent lookups use built-in defaults.
     */
    public static function reset(): void
    {
        delete_option(self::OPTION_KEY);
    }

    /**
     * Built-in defaults merged with merchant overrides. Useful for the
     * `wp opensalestax tax-class-list` admin command.
     *
     * @return array<string, string>
     */
    public static function loadEffectiveMap(): array
    {
        return array_merge(self::DEFAULT_MAP, self::loadCustomMap());
    }

    /**
     * Just the merchant's custom overrides (no defaults).
     *
     * @return array<string, string>
     */
    public static function loadCustomMap(): array
    {
        $raw = get_option(self::OPTION_KEY, '');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $clean = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $clean[$k] = $v;
            }
        }
        return $clean;
    }
}
