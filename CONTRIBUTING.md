# Contributing

## Developer Certificate of Origin (DCO)

Every commit must be signed off:

```bash
git commit -s -m "Your message"
```

CI enforces this on every PR. See https://developercertificate.org for the full text.

## License

By contributing you agree your contribution is licensed under Apache 2.0 (the project's LICENSE). Apache 2.0 is GPLv2-compatible and approved for the WordPress.org plugin directory.

Every source file must carry an `SPDX-License-Identifier: Apache-2.0` header.

## Dev install (while the SDK is still private)

The plugin consumes `ejosterberg/opensalestax` (the OpenSalesTax PHP SDK), currently a private repo. Install via Composer **path repository** alongside the SDK:

```
~/projects/
├── opensalestax-php/                 ← the SDK
└── opensalestax-woocommerce/         ← this plugin
```

`composer.json` here points at `../opensalestax-php` via a path repo. `composer install` will junction it into `vendor/ejosterberg/opensalestax`.

When the SDK eventually publishes to Packagist, the path repo can be removed.

## Running tests

Unit tests (no WP/WC bootstrap; uses `10up/wp_mock`):

```bash
composer test
```

Integration tests against a real WP+WooCom instance:

```bash
export WP_VM_BASE_URL=http://10.32.161.9          # the WP+WooCom test VM
export OPENSALESTAX_BASE_URL=http://10.32.161.126:8080
composer test-live
```

Integration tests are **skipped** when `WP_VM_BASE_URL` is unset.

## Static analysis + lint

```bash
composer stan      # phpstan --level=max
composer lint      # php-cs-fixer dry-run
composer lint-fix  # php-cs-fixer apply
```

CI runs all three on every push.

## Reporting issues

GitHub issues. Include:

- WordPress + WooCommerce versions (`wp core version` and `wp plugin get woocommerce --field=version`)
- The OpenSalesTax engine version (`curl http://your-engine/v1/health`)
- Your PHP version (`php --version`)
- A minimal reproducer (cart contents + shipping address ZIP)

Security issues: email ejosterberg@gmail.com directly rather than opening a public issue.
