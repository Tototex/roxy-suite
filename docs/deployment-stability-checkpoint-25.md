# Checkpoint 25 — fresh reservation authority

2026-10-05. Suite 1.0.24 deployed selectively: new read-only Reservations helper, Show Tickets loader, Capacity, Issuance and Suite version. No schema migration, financial recalculation, real admission, payment, vendor email/order or public publishing.

## Correction and scope

Public add-to-cart, cart update, checkout validation and remaining-seat displays now use a fresh order-line reservation count instead of the cached Sales projection. Transactional member walk-ups use the same reader while retaining connection/lock ownership checks before and after it. Current product mappings, product-to-showing identities and historical product IDs are deduplicated; untagged orders are counted. Failed product-map or quantity reads deny availability without a page fatal. Integer overflow fails closed.

This preserves the current reservation statuses (processing, completed, on-hold), original line quantities and blank/unlimited capacity rule. Pending unpaid orders still do not reserve seats: the shared checkout hold/expiry/payment lifecycle is **not resolved**. Partial refunds retain the conservative original-quantity seat policy; studio/financial report caches are not rewritten. Subscriber purchased-quantity queries and historical attendance/refund policy remain separate open work. HPOS remains unsupported and explicitly refused by the reader; it was not enabled for testing.

## Verification and audit

- Changed PHP lint and whitespace checks pass. Staged aliases exercise selected source independently of loaded production classes; critical suites rerun against deployed files.
- Fresh reservation actual Woo/MySQL fixture: initial **16 assertions**, extended to **18** including failures of both product-map reads. Tests cover pending/held/processing/completed/canceled/failed orders, stale low/high Sales cache, untouched reporting cache, untagged orders, duplicate mappings, serialized/comma/newline historical IDs, product-identity-only mapping, conservative partial refunds, identical walk-up authority, failed aggregate read and recovery. Disposable $0 orders/showing/product/refund removed; mail suppressed, no gateway used.
- Capacity standalone **16**, actual walk-up Woo/WCS **15** (including independent last-seat workers), actual reserved-member **15**, transaction/ownership MySQL **16**, and actual Woo ticket **42** pass against deployed source. Walk-up and reserved-member fixtures verify original ticket/log digests after cleanup.
- Performance audit caught an initial correlated product lookup averaging about **510 ms**, maximum **525 ms**, over 20 published finite-capacity showings. Resolve the small product set first, preserving deduplication. Final query samples averaged **89–107 ms** with maxima **95–162 ms** on this host. An alternative materialized item query was tested and discarded. These are sequential read samples, not a load benchmark; indexed queries and the shared seat ledger remain open. No new index or request cache conceals stale reservations.
- Initial refund fixture let production refund hooks create two test tickets (30866/30867, private order 30864/showing 30862). Removed those hooks from the fixture, added defensive cleanup, verified exact private identity/title and unadmitted state, then removed both test records. No customer ticket removed. A read-only baseline helper initially sorted Will Call by product/customer rather than baseline ID; corrected sort and reran successfully. Neither fixture issue affected a production browser request.
- Live signed-in public checkout: one October 30 comedy ticket with `tototest`, **$0 order 30935 / ticket 30945**. Receipt, valid ticket and loaded QR verified; cart emptied automatically. Request took time to finish and was observed without resubmitting. Explicit test note retained; order canceled afterward, ticket canceled/ineligible/unadmitted. No customer checked in or fulfilled.
- Browser smoke: Door Mode showing selector, Manual Member Admit total-arrived instruction, Will Call page and public private-event booking calendar/Book now control render without fatal errors. No actual camera/NFC scan, private-event booking submission, guest/Blocks/paid gateway or full load test claimed. Existing Elementor compatibility issue remains separate.
- Final original-row digests match: **1,230 tickets**, **18,898 ticket metadata rows**, **128 member logs**, **391 Will Call summaries**. No reservation fixture records remain. Canceled public test order retained as audit evidence, outside original-row baseline. Five deployed file bytes match local source; fresh WordPress request reports 1.0.24.

## Recovery

Server originals/stage: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-reservations`, outside web root. Existing live source matches Git checkpoint 38d8ffa after line-ending normalization. Exact four previous files archived before deployment; new helper did not previously exist.

Verified local rollback archive: `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint25-rollback-files.tar.gz`. Local/server SHA-256: `b77cc763593211d7daa8f1eff93a6409f1d6d9ad71b1a71ddacf79202ba88c0a`. Local copy verified, not a claim of completed Google Drive cloud synchronization. Single SSH session at a time; host closed the first connection after fixture completion, then one later reconnect used.

Restore old Capacity and Issuance before restoring the module loader and Suite version. The new Reservations file can remain unused; do not remove it before restoring dependent files. No schema or historical financial data to reverse. Baseline evidence comes from checkpoint 24's private `original-evidence.json` and remains outside web root.

## Next

Coordinate classic checkout, order-pay and Store API with member admissions through a durable shared showing hold/seat authority. Verify hold expiry, retries, payment callbacks, cancellation and lock ordering before deployment. Then address ticket-to-member-visit links for safe Undo, historical reconciliation and indexing. Advertising contracts/renewals remain deferred until stability work is complete.
