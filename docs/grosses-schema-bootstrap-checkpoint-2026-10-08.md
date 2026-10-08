# Grosses schema bootstrap checkpoint — 2026-10-08

## Change

- Grosses now uses a distinct `verified-1` schema marker rather than equating schema state with the plugin release version. This deliberately causes one additive `dbDelta()` pass on existing installs after this code is deployed.
- The marker is written only after all nine required tables and representative required columns are confirmed in the database. A partial install remains retryable.
- The live presale-column migration now checks the `ALTER TABLE` result and verifies the column exists. Bootstrap checks both additive migrations and renders a restricted admin warning if either cannot complete; errors also go to the PHP error log without exposing SQL or credentials.
- Added a focused fault-injection regression that simulates one table failing to install, verifies no marker is written, then verifies a retry stamps the marker after successful installation.

## Risk and scope

Risk is low to medium. Deployment causes one `dbDelta()` schema verification/migration pass for Grosses. The change is additive; it does not rewrite report/history rows or alter financial values. On database failure, Grosses remains available at the WordPress level but reporting may be incomplete; administrators see a warning and the migration retries on later requests.

Import batch/file write APIs were traced and currently have no callers, so they are documented as dormant rather than changed. The 62 `insert_log()` callers still do not inspect the returned ID; a safe operational fallback for audit-log insert failures remains open.

## Verification

- `git diff --check` passes.
- Local PHP CLI is unavailable in this workstation environment. Hosted workflow [37763498944](https://github.com/Tototex/roxy-suite/actions/runs/37763498944) passed all five PHP 8.0–8.4 syntax jobs and the PHP 8.3 full cross-module suite, including the focused schema bootstrap regression and release/package guards.
- No production site, database, saved report, email, vendor order, or financial record was modified.
