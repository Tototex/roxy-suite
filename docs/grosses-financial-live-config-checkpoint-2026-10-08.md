# Grosses Cashflow live configuration checkpoint — 2026-10-08

## Read-only production verification

Compared the three components previously reported missing from production against this branch. All are present and byte-identical:

- `class-roxy-grosses-cashflow-report.php`
- `class-roxy-grosses-email-outbox.php`
- `class-roxy-grosses-refund-webhook.php`

A sanitized WP-CLI diagnostic then verified that the Cashflow class loads, the Square refund webhook route is registered, and the refund-event table exists. It printed no secret values, transaction data, or event identifiers. The WooCommerce gateways enabled on the site are `paypal`, `stripe`, `stripe_blik`, and `stripe_link`; the Cashflow allow-list count is zero. The Square webhook signature key is not configured. Invoking the actual production `CashflowReport::for_day()` path with the empty allow-list returned the intended configuration error before making provider reads.

## Effect and boundary

Cashflow therefore correctly fails closed before attempting Square or WooCommerce financial reads until an explicit Woo gateway allow-list is selected. The registered refund webhook responds as not configured until a signing key is set. These settings were not changed: selecting financial sources and configuring the provider webhook requires explicit owner action. No financial totals, orders, emails, provider state, or historical records were changed.

This supersedes tracker wording that the Cashflow files were missing/not deployed. It does not verify combined live totals, Square settlement reconciliation, webhook delivery/configuration in Square Developer settings, bank-posting dates, or historical refund backfill.

## Later update — 2026-10-08

The owner explicitly approved the four currently enabled non-Square WooCommerce gateway IDs. `cashflow_woo_gateways` is now set to `paypal,stripe,stripe_blik,stripe_link`; the other settings were preserved. The Square webhook signing key remains unconfigured, so webhook ingestion still requires setup and is not claimed active.

The first guarded Cashflow read exposed a real WooCommerce response-shape mismatch (`max_num_pages` rather than `total_pages`). After correcting that, a second guarded read revealed one paid Woo order with no gateway identity. The report correctly failed closed. The reader now queries each explicitly approved gateway separately using WooCommerce's documented `payment_method` argument, and keeps strict page/identity validation; it does not include unknown or Square-backed gateways. Production's unfiltered aggregate diagnostic showed two Stripe records and one record without a gateway identity for 2026-10-03; it printed no order IDs or amounts.

Only `includes/modules/grosses/includes/class-roxy-grosses-cashflow-report.php` was atomically updated. The pre-pagination version is preserved in the private server recovery directory and at `I:\My Drive\Roxy Site Recovery\2026-10-08\Selective file backups\class-roxy-grosses-cashflow-report.php.before-pagination-fix` (SHA-256 `e82ed36af66ec6163b9560fad5f828fbb9c75419ec219ccdeb5deb1474467137`). The intermediate pagination-only production version is also backed up privately and on I: as `class-roxy-grosses-cashflow-report.php.before-gateway-query-fix` (SHA-256 `2f00903d8da20f318891a7086c47e248f2c0b17eebfb5f6980f0ebf93970fc15`). Deployed source SHA-256 is `20067243200d9e3acd3a618366c7c3c643a87f96191fa34db23ecd1bcfc95fb9`; production PHP 8.5.11 lint passes.

The focused staged production-host regression passes 26 checks. Hosted workflow [37851125884](https://github.com/Tototex/roxy-suite/actions/runs/37851125884) passes PHP 8.0–8.4 syntax, the isolated cross-module regression suite, and both private WordPress/Woo lifecycle fixtures. The guarded live Cashflow read-only path succeeded for seven consecutive dates, 2026-09-27 through 2026-10-03. Each run fetched Square payment/refund feeds and Woo records, returned aggregate totals only, and reported zero blocked HTTP requests, database writes, or mail attempts. No refunds appeared in that date range, so this does not validate live refund reconciliation. It also does not reconcile against settlement statements/bank deposits or establish all-day coverage. No orders, payments, financial records, emails, webhook settings, or historical reports were changed.
