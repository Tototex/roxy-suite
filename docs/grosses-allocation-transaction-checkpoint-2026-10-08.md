# Grosses allocation transaction checkpoint — 2026-10-08

## Change

Daily concessions rebalancing now holds a MySQL advisory lock scoped to the report date and allocation table. It verifies that the movie, live, and rental entry tables all use InnoDB and that no transaction is already active before it starts writing. The multi-row allocation is committed as one unit; exceptions or loss of the lock roll the writes back on the original database connection. A replacement connection is never used to commit or roll back work owned by the prior session. Unsupported table engines, unknown transaction state, lock contention, and unconfirmed transaction boundaries fail closed before the callback can proceed.

This makes the rebalancer's writes serialized and all-or-nothing. It does not cover every possible writer to these tables, reconcile source concession sales that have no eligible reporting row, or change historical report contents. No live report data was changed and no deployment was performed.

A qualifying Square concession line that has no matching report row within the show-time window now aborts the rebalance before writes and reports the unmatched line count and dollar amount. This prevents a successful-looking partial reconciliation, but it is an error surfaced to the operator rather than a durable reconciliation queue.

## Verification

- Seven isolated transaction fixtures cover successful commit/lock release, rollback after an injected later failure, non-InnoDB refusal, lock contention, preserving an existing outer transaction, loss of lock with rollback, and connection replacement without cross-session rollback.
- The private-MySQL regression now injects a failure after the first allocation row has been updated and verifies the entire fixture returns to its pre-transaction values.
- The reporter's multi-row failure fixture verifies the earlier update is restored when a later write fails.
- An actual Reporter fixture with a $12.50 eligible Square line outside all show windows verifies the line is identified and no allocation rows are changed.
- All 77 isolated cross-module regression scripts pass locally under PHP 8.3.35 with the mbstring and OpenSSL extensions enabled.
- Hosted PHP compatibility run [37768619404](https://github.com/Tototex/roxy-suite/actions/runs/37768619404) passes PHP 8.0–8.4 syntax and the complete PHP 8.3 isolated suite.

## Remaining

The transaction path has not yet been exercised against the production database or verified on the live site. Arbitrary direct writers remain outside this guard. Unmatched-sales details are not yet persisted in a dedicated reconciliation queue. Production deployment remains a separate, backup-gated task.
