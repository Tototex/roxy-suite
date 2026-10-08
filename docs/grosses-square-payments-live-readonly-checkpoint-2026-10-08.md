# Square payments permission probe — 2026-10-08

## Scope

Read-only verification of the candidate `Square::list_payments_created_between()` against the connected production Square account. This tests the `PAYMENTS_READ` permission and request/parser path without deploying candidate code or changing reports.

## Probe safeguards

- WP-CLI bootstrapped WordPress with plugins, themes, and packages skipped. The fixture loads only the installed Grosses Settings class and evaluates the candidate Square class under a randomized class name.
- Only HTTPS `GET` requests to `connect.squareup.com/v2/payments` were allowed. Each request was constrained to one explicitly selected past Pacific calendar day, configured production location IDs, the expected pagination fields, no body, and no redirects. All other HTTP requests were rejected.
- WordPress database writes/locking queries and `wp_mail` were blocked after fixture initialization. No blocked attempt occurred. The candidate method only reads configured settings and calls Square.
- Output contains aggregate counts only. Payment identifiers, amounts, raw responses, and credentials are not printed.

## Result

The October 7, 2026 Pacific-date request completed successfully with one permitted Square GET and zero payment records. The probe observed zero blocked external requests, zero mail attempts, and zero database-write attempts. This verifies endpoint access but the empty date did not exercise populated parsing. The isolated regression covers populated, malformed, duplicated, out-of-window, and paginated responses.

Follow-up on October 8: the fixture now accepts one explicitly selected past Pacific calendar day and runs returned objects through the candidate `SquarePaymentEvents` normalizer. The October 3 request returned seven real payment records, all `COMPLETED`; the candidate produced seven collection events after validating USD amount shape and payment-created timestamps. Exactly one permitted GET occurred, with zero blocked external requests, mail attempts, or database-write attempts. Output contained counts only, not identifiers or amounts. This exercises populated live parsing, but does not reconcile the daily total to a Square dashboard/settlement report or establish the WooCommerce gateway allow-list, webhook setup, refund attribution, or deployed Cashflow behavior.

Fixture: `tests/grosses-square-payments-live-readonly.php`. Follow-up SHA-256: `A97E9CC1C193EA4804A31295008ABD10186AFEE629E7239FEE025C20459645D9`.

This does not verify cashflow totals, WooCommerce gateway attribution, webhook configuration, the live Cashflow screen, or deployment. No orders, financial records, settings, or production code were changed.
