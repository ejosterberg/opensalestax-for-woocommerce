<?php

// SPDX-License-Identifier: Apache-2.0

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

// Note: WP_Mock provides pass-through stubs for esc_html / esc_html__ / etc.
// For the OrderTaxBreakdown XSS-defense test, we override them via
// WP_Mock::userFunction within the test itself with a real htmlspecialchars
// callback so the escaping behavior is actually verified.
