# Grosses allocation transaction checkpoint — 2026-10-08

## Change

Daily concessions rebalancing now holds a MySQL advisory lock scoped to the report date and allocation table. It verifies that the movie, live, and rental entry tables all use InnoDB and that no transaction is already active before it starts writing. The multi-row allocation is committed as one unit; exceptions or loss of the lock roll the writes back on the original database connection. A replacement connection is never used to commit or roll back work owned by the prior session. Unsupported table engines, unknown transaction state, lock contention, and unconfirmed transaction boundaries fail closed before the callback can proceed.

This makes the rebalancer's writes serialized and all-or-nothing. It does not cover every possible writer to these tables or change historical report contents. No live report data was changed and no deployment was performed.

A qualifying Square concession line that has no matching report row within the show-time window aborts the rebalance before writes and is recorded in a durable Grosses Logs review queue. Queue identity is idempotent by Square order/line IDs (with a private hash fallback); repeat observations update the saved facts and occurrence count, and a still-unmatched line automatically reopens after resolution. A manager can resolve an entry after review; this acknowledges the queue item only and never changes report rows or Square data. If the queue write fails, allocation still aborts before touching report rows.

## Verification

- Seven isolated transaction fixtures cover successful commit/lock release, rollback after an injected later failure, non-InnoDB refusal, lock contention, preserving an existing outer transaction, loss of lock with rollback, and connection replacement without cross-session rollback.
- The private-MySQL regression now injects a failure after the first allocation row has been updated and verifies the entire fixture returns to its pre-transaction values.
- The reporter's multi-row failure fixture verifies the earlier update is restored when a later write fails.
- Actual Store and Reporter fixtures cover queue insert/upsert/list/resolve/reopen, schema-upgrade retry, queue-write failure, and no allocation when a concession cannot be matched.
- Hosted validation for the durable queue addition is pending.

## Remaining

The transaction path has not yet been exercised against the production database or verified on the live site. Arbitrary direct writers remain outside this guard. Production deployment remains a separate, backup-gated task.
