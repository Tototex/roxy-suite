# WooCommerce refund projection — 2026-10-08

## Change

- Added a read-only normalization projection for WooCommerce refund objects. It accepts only records with the Woo payment API flag set, a stable refund and parent-order ID, USD currency, exact cents, and a valid creation timestamp.
- Manually recorded refunds are excluded because their record alone does not prove that money was returned. Those require a separate review/reconciliation path rather than being silently counted as cash outflow.
- Events preserve exact amount cents and IDs; Pacific date is derived from the refund object's creation timestamp. This is not a distinct gateway-settlement completion timestamp.
- This change does not persist events, alter Grosses summaries, send mail, change orders, or rewrite historical records.

## Verification

- Regression cases cover gateway-processed inclusion, manual exclusion, negative Woo amount normalization, exact cents, USD-only validation, stable identities, and missing timestamps.
- Hosted GitHub Actions run [37741700205](https://github.com/Tototex/roxy-suite/actions/runs/37741700205) passed PHP syntax on 8.0–8.4 and all configured isolated regressions.

## Remaining

The projection is not yet consumed. A complete financial view still needs a dated collection-event source, cross-source duplicate detection, persistence/idempotency, closed-period reconciliation, provenance, and a reviewed historical backfill. The existing Grosses ticket/studio and concessions totals are unchanged.
