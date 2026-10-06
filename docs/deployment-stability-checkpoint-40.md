# Checkpoint 40 — Pacific wall-clock schedules

2026-10-06. Suite 1.0.39. Selective Grosses Scheduler and Suite version.

Grosses and advertiser hooks now use one-shot events, re-armed for the next configured local clock time. Existing daily recurrences migrate automatically; a matching overdue one-shot is preserved for delayed WP-Cron execution. Changed clock/timezone settings repair stale events. Registration/removal failures are checked, logged as failures and do not claim successful repair or break bootstrap. A DST spring-gap normalization does not leak into the following day's configured time.

Live settings remain America/Los_Angeles, enabled Grosses 23:00 and advertiser 09:00. Verified next registrations: October 6 23:00 PDT and October 7 09:00 PDT, both single events. Advertiser callback retains its existing monthly-day guard. No callback was manually executed and no report was sent. WP-Cron still depends on its runner; late-sale finalization/catch-up and atomic send coordination remain G3/G8, not solved here. Nonexistent/ambiguous DST clock times follow PHP timezone normalization.

## Verification

- Explicit PHP 8.3 lint; 15 isolated fixtures including 23/25-hour DST intervals, spring gap, late execution, recurrence migration, settings/timezone changes, matching overdue events and registration/removal failures.
- Eight installed WordPress cron checks before/after deployment use unique inert fixture hooks only, with real WP scheduling/removal and injected registration failure. Hooks cleaned up; production settings/callbacks/financial tables untouched by fixture.
- Ten installed saved-report tests pass with all mail intercepted. Four original financial dataset counts/digests unchanged.
- Live browser Workbook Dashboard still loads after deployment. Missing existing Excel template remains G10; no download claimed.
- Live/local Scheduler SHA-256 `da4a612404507181f2167378a999117aba6339030b94fe3aca1c15fe2dff9631`; Suite `ad342c4984ca2c9af8d45179269f892fc4f67270099b48841364c1b9e9ecd9e1`.

## Recovery and risk

Medium operational scheduling impact. Normalized live Scheduler matched HEAD and both live originals were byte-compared immediately before replacement. Server backup `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-grosses-clock/` includes original Scheduler/Suite and prior hook registrations. Archive copied before deployment to `I:\My Drive\Roxy Site Recovery\2026-10-06\checkpoint40-rollback.tar.gz`, 6,518 bytes, matching SHA-256 `813b8a6986f81c852a5acebc024caf3b55325f9926b14997a7690395cc60f953`. Cloud sync not asserted.

Restore original PHP files and deliberately rebuild registrations with the restored scheduler if rolling back; do not restore the entire WordPress cron option over unrelated jobs. A transient scheduling failure may leave a hook absent/stale until retry, with failure logging. No financial data rollback needed.
