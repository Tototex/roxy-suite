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
