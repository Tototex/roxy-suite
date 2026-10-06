# Checkpoint 37 — protect reporting corrections and read-only resends

2026-10-06. Suite 1.0.36. Selective Grosses Store, Settings, Reporter and Suite version deployment.

Manager edits on Movies, Live, Rentals and Legacy default to protection. The edit form includes an explicit zero value when unchecked so managers can intentionally unlock and save. Existing movie protection is respected; additive is_locked columns default to zero on the other three tables, with a separate verified/retryable schema marker. No module-version bump or historical replay. Existing records are not guessed to be corrections or automatically locked.

Automatic upserts, partial concession updates and movie metadata refreshes require is_locked=0 in the SQL update itself. This also protects a correction committed by another connection after lookup. Notes omitted by a pull are retained. Failed lookup stops before insertion; failed upsert writes throw a recoverable reporting error rather than claiming success. Earlier successful rows are not rolled back: the error explicitly warns of partial progress and retry. Legacy bootstrap entry migration catches errors without stamping completion or taking down checkout; full non-destructive migration review remains G6.

Saved-report resend emails the existing JSON snapshot and creates/cleans its private CSV. It no longer rebuilds entries or history. Existing emailed marker/log/status behavior is retained. Tests intercept mail before SMTP: acceptance/delivery are not certified, and duplicate sends/outbox remain G8.

## Checks

- Explicit PHP 8.3 syntax checks pass for all changed files.
- 63 actual MySQL checks against four private empty schema copies: additive migration/retry, default correction protection, explicit unlock, notes retention, independent cross-connection races for full/partial/metadata updates, lookup/update/insert fault injection, missing rows. Only fixture-owned tables/options removed.
- 40 actual WordPress edit-form checks across four datasets and both protection states: default checkbox, explicit unchecked zero, dataset/nonce and current-state explanation.
- Seven standalone saved-report checks plus ten installed WordPress checks: original snapshot/CSV, success/failure metadata and zero entries/history mutation; all mail including failure alerts intercepted.
- Complete reporting regressions: four datasets at 5,001 rows, ordering/filter/tied-key cases, actual financial-row parity, CSV download failure/auth/nonce handling and installed attachment lifecycle all pass. No delivered report or historical financial edit.
- Live Grosses page loads; existing movie edit shows the checked protection control and explanatory text. No real row saved in the browser. Manual saves/unlocks exercised through actual Store against private database fixtures.
- Original financial projections unchanged before/after deployment: Movies 2,029 (`85be44666d59087a43cbb4e7de878e39ac58c54d22b8dbf995e4774b1535470cc`), Live 66 (`7c8c6de64a0d9f3e798af325b52b2bf898096665206acc954a9e3220d6a4f8ba2`), Rentals 19 (`bf3dbca6ceaf3d994583eb7180be78b65a955bf1b35c04d2925d76091f6e31c62`), Legacy 815 (`f5810974d9df42013d7499b594d0d21c03b8804485a022ea599a9bb7945734520`). New three default-zero columns excluded from comparison; existing movie lock included.

## Recovery and risk

Medium reporting impact. All four live normalized-content baselines matched Git HEAD; original files byte-compared immediately before replacement. Server backup `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-row-protection/` includes original four files and a consistent four-table SQL dump. Before deployment copied archive to `I:\My Drive\Roxy Site Recovery\2026-10-06\checkpoint37-rollback.tar.gz`; size 149,613 bytes; matching server/I SHA-256 `17c180373a20c8f15fbd3c960c17ac703a0962f5c5b5f5b16dbd5f188a676cfc`. Cloud synchronization not asserted.

Restore the four original PHP files for code rollback. Additive zero-default columns may remain harmlessly; remove migration marker only for a deliberate retry. Do not restore the SQL dump indiscriminately after new legitimate reporting edits; it is a recovery snapshot, not a routine rollback command. No public product, order, payment or settings changed.
