# Ticket-sales query failure checkpoint

## Change

Show-ticket sales summaries now distinguish a complete empty order result from a failed or malformed read. Invalid order-query results, missing order objects, malformed line-item collections, and database read errors prevent a refresh from overwriting saved totals. The last known-good totals remain available for display with an in-request unavailable marker, and sold-quantity checks return a fail-closed maximum while that marker is active. Legacy order tagging now validates all order identities and line items before setting the legacy-scan-complete marker; failed reads remain retryable.

## Verification

The isolated Sales refund regression now simulates a failed legacy order query, verifies that prior totals remain unchanged, verifies that capacity-facing sold quantity fails closed, then retries successfully and confirms the scan marker is set only after recovery. Existing payment eligibility, itemized refund, refund deletion, and cache invalidation checks remain in the same suite. Hosted PHP matrix/full-suite verification is pending this checkpoint's CI run. No production records or checkout settings were changed.

## Remaining

This does not complete historical bulk reconciliation, establish transactional snapshots against concurrent Woo order mutations, or settle the broader attendance-after-refund reporting policy. It addresses false-empty refreshes and the legacy scan completion marker only.
