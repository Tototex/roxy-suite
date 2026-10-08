# Square payments permission probe — 2026-10-08

## Scope

Read-only verification of the candidate `Square::list_payments_created_between()` against the connected production Square account. This tests the `PAYMENTS_READ` permission and request/parser path without deploying candidate code or changing reports.

## Probe safeguards

- WP-CLI bootstrapped WordPress with plugins, themes, and packages skipped. The fixture loads only the installed Grosses Settings class and evaluates the candidate Square class under a randomized class name.
- Only one HTTPS `GET` to `connect.squareup.com/v2/payments` was allowed. The request was constrained to the prior Pacific calendar day, configured production location IDs, the expected pagination fields, no body, and no redirects. All other HTTP requests were rejected.
- WordPress database writes/locking queries and `wp_mail` were blocked after fixture initialization. No blocked attempt occurred. The candidate method only reads configured settings and calls Square.
- Output contains aggregate counts only. Payment identifiers, amounts, raw responses, and credentials are not printed.

## Result

The October 7, 2026 Pacific-date request completed successfully with one permitted Square GET and zero payment records. The probe observed zero blocked external requests, zero mail attempts, and zero database-write attempts. This verifies the configured token can access the payments endpoint, but the empty date means a real payment object did not exercise candidate parsing. The existing isolated regression still covers populated, malformed, duplicated, out-of-window, and paginated responses. The candidate fixture passed PHP 8.5 syntax validation.

Fixture: `tests/grosses-square-payments-live-readonly.php`. SHA-256: `53b1e36a6f9a5eaa7816554529095451f9eb4539e526ae0863dfe8d0af2f5aae`.

This does not verify cashflow totals, WooCommerce gateway attribution, webhook configuration, the live Cashflow screen, or deployment. No orders, financial records, settings, or production code were changed.
