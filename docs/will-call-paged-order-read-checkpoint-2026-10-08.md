# Will Call bounded order-read checkpoint

## Change

Will Call now reads eligible order IDs in stable, ascending-ID pages of 200 and processes each page immediately; it no longer retains the full 18-month order-ID population in memory. Only the final customer aggregates remain resident. Each page is validated for database/API errors, pagination metadata, stable totals, duplicate/invalid IDs, and completeness, and each order is hydrated/aggregated before advancing. A missing order or changing/incomplete page aborts the request before list caching; the UI cannot present a partial attendance list as complete.

## Verification

The Will Call regression reconciles 401 fixture orders across multiple pages while preserving refunded quantities, collected revenue, customer aggregation, and status/date exclusions. Later-page query failure and an order disappearing during hydration both reject the complete list without caching partial results. Hosted run [37751618241](https://github.com/Tototex/roxy-suite/actions/runs/37751618241) passed the PHP 8.0–8.4 syntax matrix and the full PHP 8.3 isolated suite before this streaming refinement; its tests are included in the next hosted run. No live order, ticket, or attendance record was changed.

## Remaining

Pagination bounds IDs loaded per query but still scans the existing 18-month eligible-order population; query-level narrowing/indexing and per-customer ticket lookup optimization remain open. This does not certify snapshot consistency against every possible concurrent order mutation, nor HPOS support (which remains explicitly unsupported).
