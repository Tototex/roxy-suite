# Checkpoint 20 — atomic individual ticket admission and undo

2026-10-05. Live Suite 1.0.19. Selective deployment of ticket implementation, Will Call integration, and Suite version. No schema migration, gateway call, real vendor order, or public Social action.

## Correction

- Individual check-in and undo now use the same order-scoped, connection-owned lock and checked transaction as ticket issuance. Eligibility is reread inside the lock; admission flag, timestamp, staff actor, source and state commit together or roll back together.
- A repeated check-in/undo returns failure rather than a phantom successful transition. Will Call passes its source into the transaction, and its undo requires that source inside the lock; it can no longer perform an unchecked follow-up source overwrite/delete.
- Manual and Door Mode undo handlers inspect the result and report unsuccessful persistence honestly. Failed admissions are never scheduled for later replay without staff present.
- This is not a group admission transaction or a shared showing-capacity ledger. Member log coordination, multi-ticket/customer batch saves, saved Will Call maps, and external payment/refund races remain open.

## Evidence

- Changed live files matched the Git baseline before deployment; originals retained under `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-admission/original`. Changed PHP files linted before atomic replacement.
- Thirty-two real WooCommerce private fixture assertions pass against deployed source. Two independent staff worker processes contend for the same ticket: exactly one succeeds, with matching actor/source preserved. Later-write fault injection rolls back partial check-in and partial undo; explicit staff retries succeed. Repeated issuance, lost-link recovery, refunds, cancellation and cross-order reference guards also pass. Private fixture data removed; emails suppressed and no payment provider called.
- Twelve actual disposable-table MySQL assertions pass, including lock contention, rollback, guarded replay and refusal to implicitly commit an existing external transaction. Test tables removed.
- Standalone eligibility (19), Will Call (15), and member Door Mode (7) assertions pass. Their admission service is a test double, not evidence of database concurrency; actual persistence/concurrency evidence is above.
- Authorized live browser order **30710**, ticket **30711**, coupon `tototest`, total **$0.00**: QR loads at 220 pixels; staff search/check-in saves actor 2 and ticket source; replay of the old check-in link displays the explicit failure message. Staff Undo clears all admission fields and restores valid state. Guarded cleanup cancels the test order and ticket; no admission remains and browser cart is empty.
- All **1,223** pre-existing ticket posts and their complete metadata retain their original ordered-row digests. An initial comparison used JSON instead of the baseline's PHP serialization; the correct same-format comparison passes. No historical backfill performed.
- Door Mode renders without fatal error. Will Call actually loads October 30 showing 30579 and its data table without changing customer admissions. Cart retention after checkout reproduces and remains a separate open issue.

## Recovery

Rollback archive SHA-256: `a06d78e3b56b77aa0eccbdf0b62e656169751f2d570a8cc0c2d419350e174867`. Archive contains the three original changed files and private baseline evidence. Copied to `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint20-rollback-files.tar.gz`; local SHA-256 matches the server archive. Server copy remains outside the public web root.

T4 and T15 remain in progress. The policy question about already-admitted refunded tickets and occupied seats remains pending; attendance policy has not been silently changed.
