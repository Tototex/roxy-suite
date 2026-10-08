# Checkpoint 26 — durable checkout seat holds

2026-10-05. Suite 1.0.25 deployed selectively. No schema conversion, provider charge/refund, real admission, inventory submission, vendor email, or public publishing.

## Correction and scope

Classic checkout creation, order-pay and the Store API checkout adapter now claim persisted ticket quantities under the same showing locks used by member walk-ups. Checked transactional order metadata stores the expiry; fresh reservation reads include only active managed pending/checkout-draft holds, alongside the existing processing/completed/on-hold reservations. Expiry uses the database clock and releases availability without needing cron. Server-side session retry excludes only its own unpaid order; cart changes, checkout exceptions, failure and cancellation release unpaid holds. No request parameter can exclude another customer's reservation.

Confirmation rechecks availability and subscriber entitlement, excluding the order itself. Sorted named-lock leases span Woo's actual status persistence, including a hold expiring after validation. Inner transactions release only their own lock references. Provider callbacks are not wrapped in a database transaction. A late confirmation with no available seat is retained on-hold with a manager-review marker and blocked admission/QR display. Existing payment date and transaction evidence are preserved; no automatic refund or additional charge occurs. Normal confirmed processing-to-completed transitions do not reacquire their own occupied seats.

New Showings Settings field: **Unpaid ticket seat hold (minutes)**. Blank inherits Woo's existing **10080 minutes / seven days**. A ticket-only override leaves rentals and other Woo orders unchanged. The optional user choice of 15 minutes, 30 minutes or existing seven days is still unanswered at this checkpoint; neither saved settings nor the global Woo duration were changed. Missing fields on older settings forms preserve an existing override.

## Verification and re-audit

- All eight deployed PHP files lint; their exact bytes match local source. Predeployment existing source matched Git checkpoint 2e5e097 after line-ending normalization.
- Actual Woo/WCS/MySQL checkout fixture expanded from **27 to 30 assertions** and rerun against deployed source. Covers independent checkout last-seat contention, checkout-versus-member-walk-up contention, subscriber holds on unlimited showings, trusted retries, changed cart release, order-pay and Store API adapters, expiry without cron, late-payment review/payment evidence/admission block, cancellation/failure/checkout exception, injected late-write rollback, and all-or-none two-showing claims/releases. No billing/provider used. Disposable draft products/showings, users, subscriptions, orders, tickets and visit logs cleaned up; original ticket/log digests unchanged.
- Actual MySQL **23 assertions** (original transaction suite 16 + confirmation lease 7) pass, including autocommit during lease, deterministic scope ownership, reentrant claim, retention through inner release, explicit final release, partial contention and cleanup.
- Deployed actual member walk-up **16**, reserved-member **15**, ticket issuance/admission/refund/group/undo **42**, fresh reservation **18**; standalone capacity **16**, eligibility **19**, Door/member **11**, settings **8** pass.
- Broader live regression initially caught an old hybrid fixture trying to reserve three more subscriber seats after one person had already arrived on a three-person entitlement. New guard correctly placed that order in review. Updated the fixture to assert rejection, reserve the two remaining seats, then verify only two further arrivals and duplicate prevention. This was a disposable fixture, not a customer order or a relaxed production guard.
- Live signed-in classic checkout: one October 30 comedy ticket, `tototest`, **$0 order 31038 / ticket 31041**. Receipt, loaded QR, durable hold marker, confirmed status and eligibility verified without admitting anyone; cart cleared. Test order canceled afterward; ticket ineligible/unadmitted. Coupon and exact test-note/quantity/amount guards protect the cleanup helper. Slow submission was observed without a second submit.
- Browser smoke: new blank integer setting visible; Door Mode, Manual Member Admit total-arrived guidance, Will Call selector, rental calendar and Book now control render. No rental submission, physical camera/NFC scan or paid gateway test claimed.
- Original baseline remains **1,230 tickets / 18,898 ticket metadata / 128 member logs / 391 Will Call summaries** unchanged. Private checkout-hold fixtures remaining: **0**. Public canceled test retained outside original baseline. Read-only 20-showing reservation sample mean **87.4 ms**, maximum **95.11 ms**; not a load benchmark.

## Risk and remaining limits

This closes the implemented managed checkout-versus-walk-up hold path, not every order writer. Maximum four showings per ticket order, matching the bounded shared-lock implementation. HPOS remains unsupported/off. Historical products missing direct showing identity, unsynchronized administrative quantity/mapping edits, third-party raw SQL order writes, connection-reconnect fault injection, guest/Blocks browser workflows and paid provider callbacks remain unverified or open. Fixtures test the Store API conflict adapter, not a full Blocks HTTP purchase. Woo status persistence itself is not converted into a global SQL compare-and-swap for arbitrary writers.

Review orders conservatively keep on-hold reservations and require a manager to resolve availability before confirmation. Database/read failure denies ticket confirmation rather than silently granting seats. Existing refund original-quantity reservation policy and historical attendance/refund policy are unchanged. Front-end subscriber allowance still has separate cached purchased-usage work; final claims use fresh persisted entitlement usage. Seven-day inheritance may keep abandoned ticket seats held too long; the ticket-only setting enables a separately chosen shorter duration.

## Recovery

Single existing SSH connection; originals/stage outside web root at `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-holds`. Seven previous files archived before deployment; Holds helper previously absent.

Verified rollback copy: `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint26-rollback-files.tar.gz`. Local/server SHA-256: `8b6873feaa0a9a9be7ffa04586d4671349584dd628b29c8d9741fdcf81532f51`. Local verification does not imply finished Google Drive cloud synchronization.

Restore the old module loader first to stop initializing Holds, then old Capacity/Tickets/Settings/Reservations/Issuance and Suite version. Leave the now-unused Holds helper in place until no in-flight new requests reference it. No historical data or schema rollback required; expired hold/review metadata can remain inert under the previous version. Review any genuine manager-review orders before rollback because old code does not enforce the review flag.

## Next

Continue ticket/member visit linkage and safe Undo/history reconciliation, then indexed reporting/availability work and the remaining cross-module audit. Guest, Blocks and paid-provider tests need safe isolated coverage. Advertising contracts, renewals and monthly reporting remain flagged for follow-up after stability work.
