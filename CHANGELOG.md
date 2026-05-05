# Changelog

All notable changes documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [SemVer](https://semver.org).

## [Unreleased]

## [0.1.0] — 2026-05-04

### Added
- Initial v0.1 alpha against engine v0.24 + `ejosterberg/opensalestax` v0.1.0
- WordPress plugin compliant with the WP Plugin Handbook (canonical header, `readme.txt`)
- `OpenSalesTax\WooCommerce\TaxHandler` — overrides `woocommerce_calc_tax` filter to compute destination-based US sales tax via OpenSalesTax
- `OpenSalesTax\WooCommerce\Settings` — adds an "OpenSalesTax" subtab under WooCommerce → Settings → Tax
- `OpenSalesTax\WooCommerce\ConnectionTester` — AJAX "Test Connection" button on the settings page
- `OpenSalesTax\WooCommerce\Cache` — WP transient-based caching of tax lookups (default 60-min TTL)
- `OpenSalesTax\WooCommerce\ClientFactory` — builds the OST SDK client from saved settings
- Tax-exempt customers honored via `WC()->customer->is_vat_exempt()` short-circuit
- Calculation-only disclaimer on settings page (per project constitution §10)
- Configurable error fallback: `block` (no tax line) or `zero` (charge $0)
- PHPUnit unit-test suite + integration test against a real WP+WooCom instance
- GitHub Actions CI on PHP 8.2 / 8.3 / 8.4
