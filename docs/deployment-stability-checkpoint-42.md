# Checkpoint 42 — preserve allocations and share sale snapshots

2026-10-06. Suite 1.0.41. Selective Square, RefundSnapshot, Reporter and Suite-version deployment; Show Tickets Sales changes remain staged and are not included.

Fresh movie report emails and draft saves no longer upsert current financial entries. They retain studio history/report snapshots, status and logging without overwriting cross-category concession allocations. Saved-report resends remain read-only to current financial/history tables. Legacy saved CSV rows without a theater name use the configured fallback.

Managed automatic sync and fresh movie email operations share an immutable request-local sale-day snapshot. Repeated reads of the same date/location/environment reuse fully validated orders. Nested scopes share it; the outer scope clears it on success or exception. Failures are not cached, returned arrays cannot mutate the stored copy, and separate operations always read fresh provider data. Manual/range operations and metadata work still need broader optimization; no persistent cache or whole G11 resolution is claimed.

Read-only refund-feed discovery uses explicit updated timestamps, complete pagination and location/identity/status checks. Explicit creation bounds avoid Square's default one-year creation cutoff. Historical itemized snapshots batch return/source orders, reuse authoritative payment-refund objects and reject malformed or conflicting identities. This capability is not yet wired into an automatic historical backfill or cash ledger.

## Verification

- PHP 8.3 candidate lint; 72 Square-response, 40 refund-snapshot, 39 automatic-refresh and 21 refund-Reporter checks pass.
- 14 actual-Square-class fake-network snapshot checks cover nesting, immutable copies, empty results, date/location/environment separation, fresh operations, exceptions, failed retries and invalid dates.
- 21 fresh email/draft orchestration checks and eight saved-report checks pass. The fresh-builder boundary is explicitly replaced by fixture rows; these are not end-to-end provider/calendar tests.
- Actual installed WordPress: 16 fresh email/draft checks exercise real Store persistence in randomized private schemas. Successful mail, draft and failed mail preserve unlocked $5/$5 concession allocations and protected control rows byte-for-byte. All mail intercepted, network disabled and production Grosses writes guarded; cleanup checks private table removal. Eleven Reporter/Store refund-integration and seven two-connection mail-lock checks also pass.
- Post-deployment snapshot, fresh orchestration and actual WordPress private-schema checks pass against deployed sources. Bootstrap confirms Suite 1.0.41 and the snapshot API loaded. All four deployed files match local candidate SHA-256 and original files matched the rollback baseline immediately before replacement.
- Actual read-only historical Square probe: 18 original sale days, 38 itemized adjustments, no pending source days; two GIFT_CARD entries remain unsupported and are not guessed into ticket corrections. No production backfill, financial refresh, provider refund or actual report email invoked.
- Production financial counts remain Movies 2,029, Live 66, Rentals 19, Legacy 815. Public empty cart and ticket listing load after deployment. Authenticated Grosses browser verification still awaits renewed sign-in; no admin-browser success is claimed.

## Recovery and remaining risk

Server rollback: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-grosses-snapshot/`, four original PHP files and SHA manifest. Matching archive on `I:\My Drive\Roxy Site Recovery\2026-10-06\checkpoint42-rollback.tar.gz`: 27,510 bytes, SHA-256 `b952be7ff047820b8e857f7ac2374fae3563ae4ce71fbebe4b7eba8015e0d836`. Archive listing and checksum verified; cloud synchronization not asserted. Restore those original files only after checking for subsequent legitimate edits. Checkpoint 41 retains the earlier consistent financial SQL recovery snapshot; this checkpoint introduces no financial-schema migration or historical data changes.

This prevents the managed email/draft allocation-overwrite path, not all allocation or accounting issues. Shared cash/refund-day ledgers, historical backfill, live-door/Woo corrections, scheduler finalization/catch-up, exactly-once email crash recovery and broader range/metadata optimizations remain open. Square timestamps expose refund creation/latest update rather than a guaranteed bank-settlement timestamp; the transaction-date interpretation is awaiting user clarification. Staged Woo Sales tests passed quantity/deletion checks but consumer/date semantics remain under review and are not deployed.
