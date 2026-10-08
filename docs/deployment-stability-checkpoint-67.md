# Stability checkpoint 67 — complete Grosses allocation-day reads

G4 remains in progress. Concession rebalancing previously read at most 1,000 rows per dataset and date via admin-list methods. It now uses a dedicated fixed-boundary keyset reader for movie, live-show and rental rows: first capture the day's maximum row ID, then read 500 IDs per page through that boundary. Dataset/date validation, malformed/repeated rows, query failures, stalled pagination and a 100,000-row safety ceiling all fail explicitly rather than silently allocating a partial set.

The allocator now uses this full-day reader before matching Square sales to report rows. An isolated 1,001-movie-row fixture verifies all rows are updated and all 1,001 cents remain conserved. Existing two-day movie/live/rental conservation and locked-row/failure checks remain. Hosted lint and tests are pending.

No production rows, financial totals, reports, emails, or live site were changed. The keyset query has a fixed upper ID boundary but is not a multi-table transactional snapshot. Remaining G4 work includes protected/unmatched allocation reconciliation and atomic multirow recovery. This does not close G4 or claim broader Grosses integration verification.
