# Checkpoint 38 — non-destructive historical migration and calendar day columns

2026-10-06. Suite 1.0.37. Selective Grosses Store, Workbook and Suite version.

Data migration no longer replays whenever ROXY_GROSSES_VER changes. Existing nonempty completion markers are preserved; new migrations stamp a separate one-time value only after checked completion. A nonblocking connection-owned MySQL named lock serializes startup migration; ownership is rechecked before stamping. Failures leave work retryable, log a generic error and show a manage_options-only admin warning without taking down checkout. New migration rows are insert-only; existing history and current entries, including unlocked manual figures/free quantities/concessions/notes, are never replaced from incomplete older snapshots. History reads/writes now fail explicitly rather than claiming success. Earlier successful insertions can remain on failure; retry skips them. This is not a full cross-table transaction or general schema/import/logging remediation.

Workbook weekday assignment now uses the calendar date. DST-short days no longer move Monday into Sunday's column, and a clipped January 1 is placed on its actual weekday rather than Friday. Friday-start grouping, clipped first-week identity, and the user's week-start-month policy remain unchanged. No stored financial figures or saved workbook snapshots rewritten.

## Verification

- PHP 8.3 lint passes for changed files.
- 28 actual MySQL migration checks against three fixture-owned schema copies/options: legacy version marker, initial insert-only backfill, populated unlocked correction, historical preservation, repetition, saved/history read faults, history/entry/marker write faults, ownership-read fault and independent migration claim. All test-owned records/options cleaned. No production reporting mutation.
- 63 row-protection and ten installed saved-report checks rerun against deployed Store; no SMTP delivery.
- Seven calendar groups: full spring/fall Friday–Thursday periods, January clipping, Jan 1/Dec 31 retention, leap day, normal week and crossing-month attribution. Old backed-up code fails the full spring-forward fixture; new code passes. Main fixed a missing studio-mapping fixture stub before interpreting results.
- Nine actual WordPress read-only comparisons across 2024, 2025 and 2026 (53/52/41 weekly groups): every non-calendar weekly value and all monthly totals retained, daily columns conserve category totals.
- Live Grosses and Workbook dashboard load. Dashboard exposes an existing missing Windows template path (`I:\My Drive\Grosses\Roxy_Box_Office_2026.xlsx`) on the Linux host. No Refresh/Download/send clicked; actual uploaded-template generation remains open G10. Scheduler DST recurrence remains open G7.
- Live legacy history/entry markers remain `0.4.7`. Four financial dataset projections match checkpoint 37. History remains 92 rows, SHA-256 `5b9c38d1a920f7247336d1b1a8b475ce5fc5d3ff33f95b2c6645cca06ab496a74`; saved reports remain 81 rows, SHA-256 `2d0695659e09771256b39bef627244414d1dfc6c7a376de151f2e66cd29eaf15`. No historical replay or real report sent.

## Recovery / risk

Medium historical migration and low–medium export-layout impact. Workbook live normalized baseline matched HEAD; Store/Suite matched verified checkpoint 37. Originals byte-compared immediately before replacement.

Server `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-grosses-migration/`: original three files and consistent five-table reporting/history SQL snapshot. Archive copied before deployment to `I:\My Drive\Roxy Site Recovery\2026-10-06\checkpoint38-rollback.tar.gz`, 131,054 bytes, matching SHA-256 `c31ae80a56ba9a659b4d0a4a1aa46ca760009735625ae92352c7b5c252fb53a3`. Cloud synchronization not asserted.

Restore the three original PHP files for rollback. No production markers/records changed, so no routine data restore is needed. Never blindly restore the SQL dump over subsequent legitimate edits. Remaining checked schema/import/logging operations, chronological refund reconciliation and atomic email outbox are not certified by this checkpoint.
