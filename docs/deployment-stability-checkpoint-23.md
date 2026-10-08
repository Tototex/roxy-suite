# Checkpoint 23 — reserved member admission and visit log consistency

2026-10-05. Live Suite 1.0.22; Member Check 1.3.9. Selective deployment of Issuance service, Ticket API, Member Check and Suite version. No schema conversion, real member admission, payment, vendor order, or public publishing action.

## Correction

- Reserved-member admissions now acquire the same deterministic order locks as QR/Will Call, plus a member/showing scope, and commit newly changed tickets and the validated member visit in one transaction. Failed later ticket or log writes roll back all changes. The existing member log table must be InnoDB; otherwise the operation fails closed.
- Schema initialization remains on the early initialization hook. Admission only verifies readiness and never repairs schema, including before rejecting a caller-owned transaction. The canonical Member Check log fields/validation are reused through an internal writer; its SQL includes the original connection and every required lock. No callbacks to external providers or schema DDL inside the transaction.
- Under the locks, recheck active membership, reservation set, ticket/order/showing/type identity and paid/unrefunded eligibility. Keep the 64-order service limit. A changed membership quantity cannot silently clamp a log after tickets have changed.
- Requested quantity is the desired arrived total for that reservation, not an increment. Repeating target 1 does not admit another ticket. Increasing to 3 after one arrival changes/logs only two. QR admissions count toward that total but their actor/source are not overwritten or logged again as member arrivals.

## Verification

- Changed PHP and fixture files lint. Actual selected staged sources are evaluated separately from loaded production classes, including Member Check; the same fixtures then rerun against deployed source.
- Actual Woo/WCS/MySQL private fixture: **15 assertions** pass. Outdated schema refused without repair; active three-person membership and three subscriber tickets; injected later log failure; later ticket failure; simulated nontransactional log engine; clean retry; repeated target; increased target; two independent overlapping staff processes yielding exactly one admission/visit; preservation of earlier QR; unchanged complete original ticket/log digests after cleanup. Temporary fixture user, subscription, order, tickets, showing, product and log rows are removed. Pending fixture subscription is activated only through fixture-local record change to avoid billing/renewal transition callbacks. Mail suppressed; no gateway used.
- Broader staged regression: actual MySQL 16 and Woo ticket 42 assertions pass. Eligibility 19, Will Call 16, member Door Mode 9, and Member Check schema 9 assertions pass. Membership lookup/history/quantity regression passes 13 assertions. An initial private standalone membership run lacked an unchanged dashboard dependency in the stage and exited 255; dependency staged and rerun passes. The added readiness assertion was moved before lookup so an existing last-query assertion inspects its intended query. No production file or customer request failed from these fixture setup errors.
- Live browser: Door Mode and Manual Check-in load; a nonexistent member search returns no active subscribers without admission; Member Check Log displays its 50-row page/export control; public homepage loads without fatal error. No camera/NFC hardware scan claimed. Browser navigation briefly timed out; direct navigation and follow-up inspection confirmed the pages loaded. No paid checkout needed for this scoped change.
- Before deployment, exact four live originals backed up; LF-normalized hashes match Git checkpoint 847942e. Deployed file bytes match staged source; fresh WordPress request reports Suite 1.0.22.
- Final full-row baseline verification: all **1,230 existing tickets**, **18,898 ticket metadata rows**, **128 member log rows** and **391 Will Call summary rows** unchanged. No remaining private fixture posts.

## Recovery

Server backup/evidence/stage: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-member` outside the web root. Exact rollback archive copied through the existing SSH session, before deployment, to `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint23-rollback-files.tar.gz`. Local/server SHA-256: `917bab27f403ed3a71b9a354ddf495bf76864910009e39816363fe171c3e0f06`.

Restore only the four original files; no data migration or historical backfill to reverse. Restore Ticket API first before removing its newer helper/Member Check dependencies. Keep archive and evidence private (no customer data in this document).

## Still open

This is a captured reserved-ticket transaction, not a shared showing seat ledger. Walk-up capacity/entitlement races, refund/new-order authority outside common locks, membership undo/log reconciliation, the unused generic item helper, indexed cold-load queries and physical camera/NFC testing remain open. Already-admitted refund attendance policy still awaits the user's choice; no policy changed. Stability work continues before advertising contract/renewal features.
