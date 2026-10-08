# Checkpoint 24 — member walk-up capacity and entitlement

2026-10-05. Live Suite 1.0.23; Member Check 1.3.10. Five production files selectively deployed. No schema migration, real customer admission, payment, vendor order or public publishing.

## Corrections

- Member walk-ups serialize by showing, recheck active membership and reservations, and commit the actual arrival delta through the canonical transactional member log. Full shows, oversized groups and failed writes cannot report a successful admission. Requested quantity is the total arrived, not an increment; the staff form explains this.
- Reserved-member admission also takes the showing walk-up lock and includes earlier walk-ups in its arrived total. A later reservation cannot duplicate an earlier arrival.
- Public capacity includes walk-ups once. Subscriber entitlement includes the user's walk-ups. Cart quantity updates no longer add the current line back into remaining entitlement or ignore other subscriber cart lines. Failed arrival reads fail closed without crashing the public page.
- Fresh walk-up reservation reads bypass Sales cache. They deliberately retain the existing conservative original-quantity reservation/refund policy. HPOS is explicitly refused for this path; HPOS migration remains open. Blank capacity remains unlimited; explicit zero remains full.

## Verification

- Changed production and fixture PHP lint; final diff whitespace checks pass. Selected staged sources are tested independently of loaded production classes, then critical fixtures rerun against deployed source.
- Capacity regression: **14 assertions**. Actual private Woo/WCS/MySQL walk-up fixture: **15 assertions**, including stale cache, held reservations, full/oversized groups, failed-log rollback, retry, repeated/increased totals, two independent workers competing for the last seat, blank capacity and later-reservation hybrid admission. Disposable users/subscriptions/orders/tickets/showing/product/logs removed; mail suppressed and no gateway used.
- Retained actual reserved-member fixture **15**, transaction/ownership MySQL fixture **16**, and Woo ticket fixture **42** pass against deployed source. Staged member Door Mode **11**, eligibility **19**, Will Call **16**, and membership lookup/history **13** pass.
- Live signed-in public checkout: one October 30 comedy ticket, coupon `tototest`, **$0 order 30851 / ticket 30861**. Receipt and loaded QR verified; cart emptied automatically. Explicitly labeled test order canceled afterward; ticket is canceled, ineligible and unadmitted. An initial command-line verification mistakenly called a private eligibility method after successful cancellation; read-only reflection verification corrected the helper error. No production browser request failed from it.
- Manual Check-in loads with the new total-arrived instruction. Public private-event booking page renders its calendar and Book now control; no new booking submitted. No camera/NFC hardware test, latency benchmark, or full booking workflow claim.
- All **1,230 original tickets**, **18,898 original ticket metadata rows**, **128 member logs**, and **391 Will Call summaries** retain their baseline digests. Zero private fixture posts remain. The canceled public checkout is retained as an audit record, outside the original-row baseline.
- Predeployment live files match Git checkpoint be5113f after line-ending normalization. Final five deployed file bytes match local source; fresh WordPress request reports 1.0.23. Derived door-stat cache cleared only.

## Recovery

Server originals/evidence/stage: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-walkup`, outside the web root. Before deployment, exact rollback archive copied through the single SSH session to `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint24-rollback-files.tar.gz`. Local/server SHA-256: `eb9511aa36c40712a52726f42c8799e88404be78d4c75b3d02fdf9f2450e6e08`. Local copy verified; cloud synchronization is not asserted.

Restore Ticket/Capacity before removing their newer Issuance/Member Check dependencies, then restore the remaining originals and clear derived door-stat cache. No historical backfill or schema conversion to reverse.

## Still open

This coordinates member admissions, not online checkout reservation writes. A shared showing seat/hold ledger and new-order/refund coordination remain necessary. Undo needs explicit ticket-to-visit links; historical aggregate visits cannot safely be guessed. Generic item helper, indexing, HPOS, publication/cutoff policy, physical scanners and already-admitted refund attendance policy remain open. Advertising contract/renewal improvements remain deferred until stability work is complete.
