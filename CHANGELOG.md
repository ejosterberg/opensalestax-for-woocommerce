# Changelog

All notable changes documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [SemVer](https://semver.org).

## [Unreleased]

## [0.1.2] — 2026-05-05

### Added
- **SSRF mitigation** (`src/UrlValidator.php`): the engine base URL is now validated to reject private/loopback/link-local/CGNAT IP ranges by default. Admins running the engine on the same LAN can opt in via the `opensalestax_allow_private_nets` WP option or the `OPENSALESTAX_ALLOW_PRIVATE_NETS` constant in `wp-config.php`. Closes the medium-severity SSRF finding from the v0.1.1 security review (CWE-918).
- 17 new unit tests covering UrlValidator scenarios (loopback, all RFC1918 ranges, link-local, CGNAT, public IPs, schemes, opt-in path).

### Changed
- `ClientFactory::build()` now runs the URL through `UrlValidator::validate()` and returns null with a logged error message if the URL is rejected. Callers see the same null-Client behavior as if the URL was unset.
- `docs/SECURITY-REVIEW.md` updated to reflect the SSRF finding as resolved.

## [0.1.1] — 2026-05-04

### Added
- `OpenSalesTax\WooCommerce\PlaceholderRate` — manages a single row in `wp_woocommerce_tax_rates` named "OpenSalesTax". TaxHandler now uses this row's tax_rate_id as the synthetic rate-id, so `WC_Cart::get_tax_totals()` properly labels the tax line as "OpenSalesTax" in the order summary. Fixes the v0.1.0 cosmetic gap where the breakdown showed `Tax lines (0)`.
- WP-CLI commands: `wp opensalestax test-connection`, `cache-flush`, `calc <zip> <amount>`, `placeholder-rate`.
- Comprehensive `docs/SECURITY-REVIEW.md` (OWASP Top 10 audit + CWE mapping + composer audit).
- Defense-in-depth direct-access guards (`defined('ABSPATH') || exit`) on all 6 src class files.

### Changed
- `composer.json` and `.github/workflows/ci.yml` updated for the public-repo state — VCS repo entry pointing at the public SDK; dropped the broken sibling-checkout step from CI.
- README and CONTRIBUTING.md cleaned up to remove "while the SDK is private" wording.

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
