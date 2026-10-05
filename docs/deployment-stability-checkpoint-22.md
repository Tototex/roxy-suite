# Checkpoint 22 — transactional Will Call groups and attendance summaries

2026-10-05. Live Suite 1.0.21. Selective deployment of transaction service, Ticket API, Will Call integration and Suite version. No schema change, gateway/provider call, stock/vendor order or public Social action.

## Correction

- A Will Call customer group now uses one transaction for all captured ticket changes and its summary row. A later ticket failure or summary failure rolls back the entire operation. Existing QR/manual identity and source remain protected; reductions require explicit undo, including legacy summary-only orders.
- Deterministically sorted locks cover every captured order plus the context/customer summary scope. Single-ticket/issuance callers retain the same order lock names. Partial lock acquisition failure releases earlier acquired locks; group wait uses a shared deadline. Groups spanning over 64 orders fail closed with an individual-check-in instruction.
- Connection ownership and every required lock are included in guarded mutation SQL. Summary reads/writes check InnoDB, persistence errors, and readback. The existing attendance table is already InnoDB; no migration was needed.
- Browser baseline is rechecked under the locks against actual ticket admissions (or the legacy stored summary). The route revalidates the paid customer quantity and captured ticket set inside the transaction. Two concurrent original-baseline group saves cannot both succeed.
- Private fixtures now explicitly evaluate the chosen staged/live helper and Ticket source under separate class names, so a previously loaded production class cannot mask staged changes.

## Verification

- All changed PHP files lint. Standalone eligibility 19, Will Call 16 (including legacy explicit undo), and member Door Mode 7 assertions pass against deployed source. Standalone service is a test double, not evidence of real database atomicity.
- Actual disposable-table MySQL fixture: **16 assertions** pass, including ordered group locks, contention, release of partially acquired locks, safe retry, original rollback/connection guard, and preservation of an outer transaction. Disposable tables removed.
- Actual Woo private fixture: **42 assertions** pass against staged and deployed source. Later ticket failure rolls back the group; failed summary write rolls back all admissions. Two independent staff processes produce exactly one original-baseline group transition. Stale group undo fails; explicit group undo succeeds; mixed QR/Will Call undo preserves the QR identity. A group spanning two Woo orders commits together. Previous issuance, individual admission, refund and cancellation checks also pass. Private orders/tickets/refund/showing/product and summary rows removed; emails suppressed and no gateway called.
- Authorized live browser order **30752**, tickets **30753–30755**, `tototest`, total $0, explicit test note. All three QR images load. Read-only preflight verifies these are the customer's only eligible tickets for the selected showing before any staff save.
- Live checkbox check-in commits all three tickets with Will Call source and summary quantity 3. An older staff tab attempts quantity 1 from baseline 0: explicit conflict displayed, all three admissions and summary remain unchanged. Native numeric keyboard interaction triggers the actual change handler; programmatic field filling alone did not trigger a save and was not counted as a successful check-in.
- Explicit deployed server group undo clears all four admission fields for all three test tickets. Test order then canceled; prior summary row restored exactly. Browser cart is empty without manual removal. This verifies server group undo, not the browser confirmation dialog.
- Full ordered-row digests of all **1,227 pre-existing ticket posts and metadata** and **391 original Will Call summary rows** match the pre-deployment baseline after cleanup. No historical ticket/summary backfill attempted.
- Staff page loads without fatal error and showing 30579 loads its data table. One navigation exceeded the browser command's ten-second timeout; follow-up confirms the expected page loaded. No latency benchmark or outage claim.

## Recovery

Original four files and evidence retained outside the web root under `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-group`. Live originals match the Git baseline after LF normalization (Ticket file has CRLF); original server copies preserve exact bytes.

Before deployment, created `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint22-rollback-from-git.zip` from Git checkpoint `cdeb9de`. All four archived sources match the verified live baseline after line-ending normalization. ZIP SHA-256: `63fa47dfec537c73226cd348ad3d0d787f05aa6c392d31f6a78483409244388c`.

After testing, copied the exact server originals/evidence archive to `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint22-rollback-files.tar.gz`. Local/server SHA-256 matches: `5e4cd083fb8f7fed604dab74098d7915cc2c89e975ee72b0d8e852e006564626`. SSH was closed before the single SFTP download; no parallel connections or rapid retries.

## Remaining scope

T4/T9/T15 remain in progress: this protects a captured Will Call group and summary, not a shared showing-wide reservation/entitlement ledger. New orders/refunds outside the common lock, reserved member ticket/log coordination, member walk-up capacity, the unused generic order-item quantity helper, and indexed Will Call queries remain open. The admitted-refund attendance policy question is still pending; no silent policy change. No new revenue features added.
