# Grosses financial refund projection — 2026-10-08

## Change

- Added a read-only constructor for the complete Square payment-refund feed, independently of the itemized return-order reconciliation used to correct original sale-day ticket counts.
- The financial projection includes only COMPLETED refunds; pending, rejected, and failed records are not cash-out events.
- Completed records require a stable refund, payment, and location identity; exact nonnegative USD cents; and a valid RFC 3339 updated_at. order_id is retained when present but is not required, so a refund without an order link is still represented.
- The Pacific refund date is derived from updated_at. This is explicitly a timestamp proxy, not a verified provider completion timestamp.
- Retained the previous completed_return_financial_refunds() method as a compatibility alias. No historical records, reports, dashboard totals, email snapshots, or payment-provider state are changed.

## Verification

- Added regression coverage for an unlinked completed refund, pending exclusion, stable identity, exact cents, Pacific date conversion, and duplicate identity rejection. Existing malformed timestamp and unsupported currency checks remain.
- Hosted GitHub Actions run [37741258256](https://github.com/Tototex/roxy-suite/actions/runs/37741258256) passed PHP syntax on 8.0–8.4 and all configured isolated regressions.

## Remaining

This is still a normalization seam only. No refund ledger is persisted and no financial dashboard consumes it. WooCommerce refund events, verified completion timestamps, sale/collection event attribution, historical backfill, provenance, and custom/exchange/manual review remain open under G2.
