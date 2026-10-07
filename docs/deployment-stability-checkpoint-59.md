# Checkpoint 59 — Manual reporting snapshots and lookback allocations

2026-10-06. Suite 1.0.56 selectively deployed; all three candidate/readback hashes match.

Manual movie/live pulls, fresh studio draft saves and reconciliation ranges now share the operation-local Square sale snapshot, with nested scope support and cleanup on exceptional exits. Managed operations retrieve category assignments fresh once per environment/account instead of trusting the old fourteen-day transient; empty results are shared only within the operation. Malformed or conflicting catalog identities stop reporting rather than silently discarding classification. Persistent cache behavior outside managed operations remains unchanged.

Manual movie pulls and automatic sync now rebalance every unique affected report date, including lookback dates, across existing movie/live/rental rows. Previously those older movie rows could overwrite their shared concession amounts while only the requested day was rebalanced. Manager-protected rows remain untouched. Failed updates of unlocked rows now throw instead of reporting completed allocation. This is not a transaction spanning all financial rows: an interrupted allocation may have partial writes and must be retried/reviewed; locked overrides and unmatched sales can still require reconciliation.

## Verification

Before/after: 25 actual Square-class fake-network snapshot/category assertions; 11 actual Reporter orchestration/allocation assertions with fake builders/storage; seven private-WordPress/MySQL actual Reporter/Store assertions. The SQL fixture uses randomized owned copies of the three financial tables, blocked production writes/network/mail, fake Square/metadata/showing boundaries, and checks exact 1,001-cent conservation on both target/lookback days, a forced SQL failure, manual protection and unchanged original records. Owned tables removed. This is not a real Square pull or full authenticated admin certification.

Existing fresh email/draft orchestration and actual private-WordPress persistence regressions pass with updated guarded builder boundaries. The 25 money and 39 automatic-refund assertions pass against the candidate. Post-deployment all 49 staged isolated PHP programs pass against installed source; all 78 installed PHP files pass PHP 8.3 lint and all ten modules pass structural diagnostics. Original movie/live/rental/legacy financial digests compare unchanged. Live public Tickets page reloads unchanged; reporting admin still needs sign-in.

The locked-row isolated test initially kept its simulated failure enabled for other unlocked rows. Corrected fixture, not production safeguards. Main-agent review tightened the smaller-model SQL fixture with metadata isolation, mutation/network/mail guards, two-day coverage and original record digests before execution. One disconnected remote session was replaced; an unusable noninteractive replacement was explicitly stopped before opening the single interactive session. No live replacement occurred before backup/testing.

G4/G11 remain partial: arbitrary writers, complete cross-category/unmatched/protected-row reconciliation, bounded historical ranges and broader allocation optimization need further audit. Historical manual refund price evidence is the next separate fix (G2); financial refund-day policy is not changed here. No studio email, real refund, payment, vendor order or historical recalculation was performed.

## Recovery

Three originals and checksum manifest: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-report-snapshots/`.

Verified before deployment: `I:\My Drive\Roxy Site Recovery\2026-10-06\roxy-checkpoint59-rollback.tar.gz`, 26,807 bytes, SHA-256 `8ae2a1bceb93cff0733cb3e796cc42dcd38135f17f88f012d60319c500882176`. No cloud-sync claim or schema migration. Unintegrated Requested Showing agreement helper remains undeployed; its independent tightened request-identity checks total 23 passing assertions.
