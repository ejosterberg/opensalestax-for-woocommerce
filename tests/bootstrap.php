<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Initialize WP_Mock for unit tests. Each test class calls
// WP_Mock::setUp() / ::tearDown() in its lifecycle methods.
\WP_Mock::bootstrap();

// A few constants WordPress code expects.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('PHP_VERSION_ID')) {
    define('PHP_VERSION_ID', 80200);
}

// WP global helper used by TaxClassMap::set(); WP_Mock doesn't provide it.
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

// WordPress's parse_url() wrapper. Real WP wraps PHP's parse_url() with
// a couple of cross-version normalizations; for unit tests it's safe to
// delegate to the underlying built-in.
if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): array|string|int|null|false
    {
        return parse_url($url, $component);
    }
}

// esc_js() — escape a string for use inside a JavaScript single- or
// double-quoted string literal. Real WP version is more careful about
// Unicode; pass-through is fine for tests that only check rendered HTML.
if (!function_exists('esc_js')) {
    function esc_js(string $text): string
    {
        return addslashes($text);
    }
}

// wc_get_logger() — WC's standard logger entry point. Returns null in
// unit tests so the plugin's logWarning() helper falls through to
// error_log (which we don't actually capture, but it won't crash).
if (!function_exists('wc_get_logger')) {
    // Intentionally NOT defined as a function — TaxHandler and friends
    // use function_exists('wc_get_logger') to gate the call. Leaving it
    // undefined here keeps tests in the fall-through branch.
}

// Note: WP_Mock provides pass-through stubs for esc_html / esc_html__ / etc.
// For the OrderTaxBreakdown XSS-defense test, we override them via
// WP_Mock::userFunction within the test itself with a real htmlspecialchars
// callback so the escaping behavior is actually verified.
