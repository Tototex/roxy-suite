# Checkpoint 41 — itemized Square movie-ticket refunds

2026-10-06. Suite 1.0.40. Selective Grosses Square, Store, Reporter, Settings, loader, two new helpers and Suite version deployment.

User policy: Hollywood ticket counts and nominal gross are corrected on the original sale day. Financial cash refunds belong to the day the money is returned; that separate accounting path is not implemented by this checkpoint. Pre-purchases/check-in are not substituted for Square reportable ticket sales.

Read-only return discovery fully paginates updated COMPLETED orders, checks payment-refund completion and payment ownership, batches original-source retrieval and reconciles exact source order/line identities. Quantities only are adjusted; the original monetary receipts are not prorated. Pending refunds do not deduct sales and persist source dates for retry. Failed/refused refunds are skipped. Invalid references, custom amounts, ambiguous showing links, duplicate evidence, over-refunds and unavailable original prices stop for review rather than guessed accounting.

Original nominal prices come from matching immutable emailed evidence, or a unique one-category canonical baseline for strict past-day refresh. Duplicate rows, malformed quantities and fractional/nondivisible cents fail closed. Full refunds retain an explicit zero row. Historical automatic corrections write ticket columns only, preserving concessions, unrelated fields and SQL-protected manual corrections.

Changed emailed snapshots stay immutable and get separate lazy-created InnoDB review evidence. Daily review UI explains the affected original dates and deliberate corrected-draft workflow. Flagged saved snapshots cannot be resent. A shared reentrant, connection-owned lock serializes managed movie email decisions/dispatch and review flags; ownership is rechecked immediately before dispatch. This is not an exactly-once email outbox or crash-recovery guarantee (G8).

## Verification

- Explicit PHP 8.3 syntax checks for all eight deployed files.
- 100 pure itemized-return checks, 29 snapshot checks, 21 Reporter checks and 39 automatic-refresh checks; 50 Square-response assertions pass. Cases include pending-to-completed retry, multiple partial/full refunds, failures, multi-day scan outages, protected records and missing/ambiguous identity/price evidence.
- Actual private MySQL: 16 original-price, 17 review-evidence, 17 ticket-only quantity, 11 Reporter/Store integration and seven independent-connection mail-lock checks pass. No production-table writes or provider mutation; all fixture mail intercepted.
- Seven installed WordPress review-rendering checks pass, including hostile evidence escaping and unchanged saved figures. Seven isolated and ten installed saved-email regressions pass; 63 existing row-protection MySQL checks pass.
- Actual read-only Square snapshot: one original sale day, two itemized adjustments, no reconciliation issues. No refund created, financial report run or email submitted.
- All normalized original live PHP baselines matched HEAD, and originals were byte-compared against the backup before replacement. Each deployed file matches its staged/local SHA-256. Suite bootstrap confirms both helpers loaded.

## Recovery and remaining risk

High reporting impact, bounded/fail-closed provider reads. Server backup `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-grosses-refunds/` contains original six PHP files, a consistent seven-table Grosses SQL snapshot and the two refund-cursor option states. Archive copied and listed on `I:\My Drive\Roxy Site Recovery\2026-10-06\checkpoint41-rollback.tar.gz`: 184,991 bytes, matching SHA-256 `57d7f1da1ab80bcb5a37153cd0dc014e0a4882ebe5241151eb0c61cad7e410e2`. Cloud synchronization not asserted.

Restore the original loader/Reporter/Store/Square/Settings/Suite files for code rollback. New helper files and separate review table can remain inert. Review-cursor restoration and financial SQL recovery must be deliberate: do not replace later legitimate edits indiscriminately.

Initial automatic discovery scans 30 days, not all historical refunds. Subsequent scans resume from the successful cursor minus one day and pending source dates; source-date expansion includes earlier partial returns. Historical backfill remains required. Reads share a 120-second deadline and 100-return safety cap; oversized/ambiguous history stops for attention, not partial success. Financial refund-day accounting, live-door/Woo refunds, final-day catch-up, complete shared snapshots and email acceptance/crash reconciliation remain G2/G3/G4/G8/G11 work.

Original production dataset counts and digests remain unchanged after deployment: Movies 2,029, Live 66, Rentals 19, Legacy 815. Live authenticated browser review is pending sign-in renewal: the expired session redirects to WordPress login. Public home and empty cart load after deployment. No authenticated browser verification is claimed yet.
