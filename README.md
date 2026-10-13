# Veridis Fraud Guard

Stop repeat problematic orders and uncollected Cash on Delivery checkouts with predictable, merchant-controlled protection for WooCommerce.

Veridis Fraud Guard lets you maintain a blocklist of known email addresses, phone numbers, IP addresses, and email domains. When checkout details match, the plugin can block the checkout, hold a Cash on Delivery order for review, or warn your fulfillment team. All protection checks run locally on your WordPress server—no external account, subscription, fraud-scoring API, or customer-data telemetry is required.

[Product](https://veridis.dev/products/fraud-guard/) · [Documentation](https://veridis.dev/products/fraud-guard/docs/) · [Changelog](https://veridis.dev/products/fraud-guard/changelog/) · [Support](https://veridis.dev/products/fraud-guard/support/)

> **Current release:** `1.0.0` is the first public Veridis Fraud Guard Free release. The links above are launch targets and may not yet be publicly available.

## Features

- **One-Click Block Customer:** Add all usable identifiers from a WooCommerce order to the Blocklist, or block and unblock individual identifiers.
- **Exact-match protection:** Match complete email addresses, phone numbers, individual IPv4 or IPv6 addresses, and email domains.
- **Four operating modes:** Keep protection Off, Block a matching checkout, Hold a matching COD order, or Warn your fulfillment team.
- **COD-aware handling:** Hold mode uses WooCommerce's native On Hold status for matching Cash on Delivery orders. Non-COD orders keep their existing payment flow and order status and receive a private warning note.
- **Checkout coverage:** Supports both Classic Checkout and WooCommerce Checkout Blocks.
- **Order visibility:** See match indicators and manage identifiers from WooCommerce order screens and the orders list.
- **Dashboard and Activity history:** Review intervention totals, recent events, Blocklist distribution, storage health, checkout source, and the exact identifier that matched while it remains within retention.
- **CSV transfer:** Import and export Blocklist entries and merchant notes in a portable, validated format.
- **Local-first operation:** Blocklist lookups and checkout decisions use indexed tables on your own WordPress database, without remote decision services.
- **Modern order storage:** Declares compatibility with WooCommerce High-Performance Order Storage (HPOS) and also supports legacy order storage through WooCommerce order APIs.

Fraud Guard Free applies rules you choose. It does not automatically identify unknown fraudsters, calculate fraud scores, use AI predictions, detect disposable-email providers, support wildcard or CIDR matching, or guarantee that every uncollected delivery will be prevented.

## How protection works

At checkout, Fraud Guard normalizes the customer's billing email, billing phone, customer IP address, and the domain from the billing email, then checks them against the merchant-managed Blocklist.

If any identifier matches, the selected mode determines the result:

| Mode | Matching checkout behavior |
| --- | --- |
| **Off** | Keeps the Blocklist available for management but pauses checkout interventions. |
| **Block** | Rejects the checkout with a merchant-configurable customer message. |
| **Hold** | Places a native Cash on Delivery order On Hold for manual verification. For non-COD methods, it preserves the payment flow and order status and adds a private warning note. |
| **Warn** | Allows the order to continue normally, adds an internal warning note, and records the event. |

Blocked, held, and warned attempts are recorded in Activity. When an order exists, the event can include an order reference and link. Fraud Guard also records the checkout source and the matched identifier types.

## Requirements and compatibility

- WordPress 6.5 or later
- PHP 8.1 or later
- WooCommerce 8.2 or later, installed and active
- Node.js 22.12 or later only when building assets from source
- Classic Checkout and WooCommerce Checkout Blocks
- WooCommerce HPOS and legacy order storage

The plugin has no Composer runtime dependency. PHP classes are loaded by the plugin's namespaced autoloader.

## Installation

1. Confirm that the store meets the WordPress, PHP, and WooCommerce requirements above.
2. Upload the `veridis-fraud-guard` directory to `/wp-content/plugins/`, or upload the plugin ZIP from **Plugins > Add New > Upload Plugin**.
3. Activate **Veridis Fraud Guard**.
4. Open **WooCommerce > Fraud Guard**.
5. Add known problematic identifiers to the **Blocklist**, import a CSV, or block identifiers from an existing WooCommerce order.
6. Open **Settings**, choose Off, Block, Hold, or Warn, and save the configuration.
7. If using Block mode, review the customer-facing rejection message.

## Development and testing

Install the locked JavaScript dependencies, run the TypeScript check, and create a production build:

```sh
npm ci
npm run typecheck
npm run build
```

`npm run build` already runs `tsc --noEmit` before Vite. Use `npm run dev` for the opt-in Vite development server; the local WordPress environment must define `VERIDIS_FRAUD_GUARD_DEV_SERVER` with that server's URL.

## Distribution packaging

Create the versioned WordPress.org package from the explicit distribution allowlist:

```sh
npm ci
npm run package
```

The command rebuilds production assets, validates version metadata and every manifest reference, writes `dist/veridis-fraud-guard-<version>.zip`, and verifies the finished archive. The ZIP always contains one top-level `veridis-fraud-guard/` directory. Its file order, timestamps, permissions, and stored bytes are normalized so identical inputs produce an identical archive.

To verify an existing package independently, run:

```sh
npm run verify:package -- dist/veridis-fraud-guard-<version>.zip
```

The allowlist includes the PHP runtime, compiled assets, licensing and WordPress.org metadata, and the readable JavaScript/TypeScript sources and build configuration required to reproduce the bundles. It excludes tests, repository metadata, local configuration, caches, `node_modules`, product-planning documents, and other files not required for distribution. Packaging fails on symlinks, unsafe paths, unexpected files inside guarded runtime/source directories, missing runtime files, inconsistent version metadata, or invalid manifest references.

The repository does not define an npm test script. Its standalone PHP regression harnesses can be run directly with PHP 8.1 or later:

```sh
php tests/php/activity-identifiers-privacy-harness.php
php tests/php/activity-personal-data-exporter-harness.php
php tests/php/activity-rest-harness.php
php tests/php/blocklist-activity-harness.php
php tests/php/blocklist-csv-harness.php
php tests/php/blocklist-storage-health-harness.php
php tests/php/checkout-activity-harness.php
php tests/php/dashboard-activity-harness.php
php tests/php/i18n-harness.php
php tests/php/installer-retry-harness.php
php tests/php/uninstall-harness.php
```

The script-translation loading harness uses a real WordPress installation and a generated locale catalog. After building the production assets and creating the test catalog, provide its environment explicitly:

```sh
VFG_WP_ROOT=/path/to/wordpress \
VFG_TRANSLATIONS_DIR=/path/to/languages \
VFG_TEST_LOCALE=fr_FR \
VFG_EXPECTED_TRANSLATION='Expected translated text' \
php tests/php/script-translation-loading-harness.php
```

## Internationalization

English is the source language. PHP uses WordPress gettext functions, and the TypeScript interfaces use `@wordpress/i18n`. WP-CLI with the `i18n` command is required to extract a POT file.

Build first, then extract:

```sh
npm run build
npm run i18n:pot -- /tmp/veridis-fraud-guard.pot
```

The extraction script reads `build/manifest.json`, finds the current hashed production admin bundle, and passes that bundle together with the plugin PHP sources to `wp i18n make-pot`. This keeps JavaScript source references aligned with the asset WordPress actually enqueues. When WP-CLI is a PHAR rather than a global `wp` command, set `WP_CLI_BIN=/path/to/wp-cli.phar`; `PHP_BINARY` may also be set when a specific PHP executable is required.

WordPress loads JavaScript translations by associating the stable `veridis-fraud-guard-app` script handle with the `veridis-fraud-guard` text domain through `wp_set_script_translations()`. WordPress.org language packs generate locale JSON catalogs whose MD5 filenames correspond to the hashed bundle path. For local catalogs, use:

```sh
wp i18n make-json <po-file> languages --no-purge
```

The repository does not bundle PO, MO, or translated JSON catalogs; those are generated translation-platform artifacts.

## Data storage, privacy, and retention

Fraud Guard creates two versioned tables for each WordPress site:

- `${wpdb->prefix}veridis_fraud_guard_blocklist` stores each identifier's type, display value, normalized value, optional merchant note, and UTC timestamps. A unique key on `(type, normalized_value)` prevents normalized duplicates.
- `${wpdb->prefix}veridis_fraud_guard_activity` stores checkout and administrative events, outcomes, checkout sources, matched types, timestamps, optional order references, and—where applicable—the exact identifiers responsible for an intervention.

Exact identifier snapshots in checkout Activity are retained for 90 days by default. After the retention boundary, Fraud Guard interfaces stop returning them. A scheduled WordPress task physically clears expired snapshots, with a throttled fallback cleanup during normal requests; physical cleanup can occur later when WP-Cron is delayed or disabled. Non-identifying Activity metadata remains for operational reporting while the plugin is installed.

Fraud Guard adds suggested text to the WordPress privacy-policy guide and registers a personal-data eraser. The eraser finds Activity events by an exact matching email address and clears the complete stored identifier snapshot for those events. It cannot locate events that stored only a phone number, IP address, or email domain, and it does not remove Blocklist entries, Activity metadata, WooCommerce orders, order notes, or technical order markers.

Deactivation is non-destructive: settings, Blocklist entries, and Activity records remain. Deactivation does clear the scheduled retention event; it is scheduled again when the plugin is active. Deleting the plugin through WordPress removes the plugin's two custom tables, allowlisted options, and scheduled retention event on each site in a multisite network. WooCommerce orders, statuses, payments, refunds, customers, private order notes, and Fraud Guard order markers remain as historical order data.

## Identifier normalization

Normalization is deliberately conservative and predictable:

- **Email:** Trimmed, validated as a complete address, and compared without letter-case differences.
- **Domain:** Compared as an exact hostname without letter-case differences or a trailing dot. A leading `www.` remains significant. Internationalized domains require server IDN support and normalize to ASCII for comparison.
- **Phone:** Accepts 7 to 15 digits; formatting characters are removed for comparison, while an explicit leading `+` remains significant. Letters and extensions are not accepted.
- **IP address:** Accepts one valid, exact IPv4 or IPv6 address and normalizes its standard textual representation.

There is no wildcard, range, CIDR, substring, or fuzzy matching.

## CSV import and export

CSV files must be UTF-8 comma-separated data with the canonical columns:

```csv
type,value,note
email,problem@example.com,Repeated uncollected order
phone,+40712345678,
domain,example.test,Review manually
ip,203.0.113.7,
```

`note` is optional. Supported type tokens are `email`, `phone`, `ip`, and `domain`. Each import is limited to 10 MB and 1,000 non-blank data rows.

The importer validates the file before changing the Blocklist, imports valid rows, reports row-level failures, and skips normalized duplicates without overwriting existing entries or notes. Export includes every current Blocklist entry and note in a re-importable CSV and escapes formula-like values for safer use in spreadsheet software. Activity, settings, order data, internal IDs, and timestamps are not exported.

Blocklist exports contain customer identifiers. Store them securely and share them only with authorized people.

## Documentation and support

- [Product overview](https://veridis.dev/products/fraud-guard/)
- [Documentation](https://veridis.dev/products/fraud-guard/docs/)
- [Changelog](https://veridis.dev/products/fraud-guard/changelog/)
- [Support](https://veridis.dev/products/fraud-guard/support/)

The repository's [`readme.txt`](readme.txt) contains the approved WordPress.org plugin-directory description and user-facing metadata. The official links above are launch targets and may not yet be publicly deployed; this repository does not claim an existing WordPress.org listing.

## Licensing and third-party notices

Veridis Fraud Guard is free software licensed under the GNU General Public License, version 2 or later. See [`LICENSE.txt`](LICENSE.txt).

Production runtime and third-party asset notes:

- Bundled Geist font files are licensed under the SIL Open Font License 1.1; the complete font license is in [`assets/fonts/geist/OFL.txt`](assets/fonts/geist/OFL.txt).
- React, React DOM, and Scheduler implementations are not bundled; the administration app uses the `wp-element` runtime supplied by WordPress.
- `@wordpress/i18n` is provided at runtime by WordPress and is not embedded from npm in the production bundles.

See [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md) for the complete production-asset inventory and notices.
