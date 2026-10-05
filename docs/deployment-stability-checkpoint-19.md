# Checkpoint 19 — serialized, transactional ticket issuance

2026-10-05. Live Suite 1.0.18. Selective deployment of Suite version, Show Tickets loader, ticket implementation, and a new private `Issuance` service. No schema migration, HPOS switch, gateway change, vendor order/email, or public Social action.

## Scope

- Order-scoped, connection-owned named lock surrounds ticket creation/reconciliation. Each complete ticket set, its identity/state metadata, and order-item links commit together using the verified InnoDB core tables.
- Explicit checked writes and reads. SQL mutations include original connection/lock ownership predicates, so automatic reconnect/replay cannot autocommit a stray ticket or metadata write. Failure rolls back instead of reporting tickets saved. No foreign transaction is implicitly committed; an external transaction causes deferred reconciliation.
- Stable order-item sequence metadata reuses existing identities on retry, recovers lost links for sequence-bearing tickets, and invalidates excess identities when quantity decreases. Mismatched order/item references and duplicate sequences fail closed. Unlinked legacy tickets without a provable sequence require review, rather than silently generating replacements.
- Transactional ticket reads bypass shared WordPress metadata caches; committed/rolled-back cache entries are invalidated. Private ticket projection insertion deliberately avoids public post-insert callbacks/network effects within the transaction. Check-in/undo writes are **not** changed by this checkpoint.
- Unsuccessful reconciliation logs a non-PII order identifier and gets at most three scheduled retries (one, five, fifteen minutes); payment is never retried. Empty ticket output for a ticket order now gives an honest pending/preparation message instead of disappearing silently.

## Recovery and verification

- Original live file hashes matched the Git baseline before deployment. Rollback copies retained at `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-issuance/original`. Helper installed before loader/ticket activation; files replaced by atomic rename.
- Four disposable-table MySQL fixture: twelve assertions pass, including cross-connection invisibility, complete commit, metadata no-op, later-write rollback, competing order lock, guarded replay by another connection, and preservation of an existing outer transaction. Test tables removed.
- Real WooCommerce private disposable $0 fixture: seventeen assertions pass, including three distinct identities, repeated reconciliation, lost-link recovery, quantity decrease/increase, processing status, actual zero-dollar line refund, cancellation, cross-order rejection and repaired retry. Fixture order/refund/products/showing/tickets and its retry events removed; emails suppressed and no provider called.
- Both suites rerun against deployed source. Existing nineteen payment/refund eligibility assertions pass. Read-only live fifty-ticket sample has zero unexpected paid-ticket rejection.
- Baseline recorded 1,220 existing ticket posts and all their metadata. Full ordered row digests remain unchanged after private fixtures, deployment, and live checkout/refund testing. No historical ticket backfill attempted.
- Authorized live website checkout order **30683**, three general-admission tickets **30684–30686**, `tototest`, total **$0.00**, explicit test note. Thank-you page renders all three QR tickets; subsequent account order view confirms all three QR images successfully load at 220 pixels.
- Actual live zero-dollar status/refund test: nine assertions pass. On-hold blocks admission; processing restores eligibility; two cumulative single-ticket refunds leave two then one eligible ticket; repeated reconciliation preserves allocations; refunded ticket cannot admit or revive through undo. No gateway refund/restock. Test order canceled, no admissions remain, and test cart item removed; browser confirms empty cart.
- Door Mode, manual check-in, Will Call selection controls, Requested Showings, Event Booking, and Inventory render without fatal error. Will Call's current October 30 showing loads its data table without saving any admissions. Page loads alone do not prove concurrency or external billing/provider behavior.
- Previously observed cart retention after checkout reproduces and remains open. One browser navigation exceeded its ten-second command timeout; subsequent inspection confirmed the expected cart page loaded. This is not an established site outage or a latency benchmark.

## Remaining audit scope

T4 is **in progress**, not resolved: issuance is protected, but atomic shared check-in/undo and member reserved-ticket/log coordination remain. T1/T6 showing-level seat/entitlement reservations still need a common authority covering checkout, payment transitions, refunds, and member walk-ups. A policy question about attendance/occupied seats after an already-admitted ticket is refunded remains pending. Financial/attendance historical definitions and all other open tracker findings are unchanged.
