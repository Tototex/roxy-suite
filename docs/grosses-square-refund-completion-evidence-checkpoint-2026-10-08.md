# Square refund completion evidence checkpoint — 2026-10-08

## Change

- The read-only completed-refund projection now looks for a persisted Square `refund.updated` event with status `COMPLETED` matching the current refund ID, payment ID, location ID, exact USD cents, and normalized Square `updated_at`.
- Only an exact match substitutes the provider event's `created_at` for the existing `updated_at`-derived refund date. The event ID and timestamp are preserved, and the basis is labeled `square_refund_completed_event`.
- If no exact event matches, the existing `square_updated_at_proxy` date remains. If the evidence query fails or a matched timestamp is malformed, the projection fails closed. This is provider status-transition evidence, not proof of when a cardholder's bank posts the funds.
- The on-demand Cashflow tab consumes the exact-matched event evidence for its read-only refund date. It remains separate from Hollywood's nominal studio-gross reports; no persisted financial ledger, historical rewrite, or live payment record is changed.

## Verification

- Extended the Grosses refund snapshot regression to verify exact identity/timestamp lookup constraints, event-date precedence, provenance, and proxy fallback.
- Added a direct real-`Store` regression for the parameterized evidence query, completed/USD constraints, missing-event fallback, malformed records, and database failures.
- Hosted run [37765673549](https://github.com/Tototex/roxy-suite/actions/runs/37765673549) passed all PHP 8.0–8.4 syntax jobs and the PHP 8.3 full isolated cross-module suite, including the new direct Store lookup fixture.

## Follow-up verification — 2026-10-08

- Combined-Cashflow regression now verifies that an exact matching provider event supplies the refund date and that evidence-read errors fail closed.
- Hosted run [37810925976](https://github.com/Tototex/roxy-suite/actions/runs/37810925976) passed PHP 8.0–8.4 syntax and the full PHP 8.3 isolated cross-module suite.

## Remaining

The Cashflow tab and event integration are branch-only and not deployed. No Square webhook subscription has been configured or verified live, `PAYMENTS_READ` scope is unverified, and no provider-day reconciliation or durable financial ledger/backfill has been performed. The event timestamp is not a bank-settlement timestamp.
