# Grosses email send guard checkpoint — 2026-10-07

## Implemented locally

- Added a dedicated InnoDB outbox with a unique SHA-256 logical-send key. A `sending` claim is persisted before WordPress mail handling; the state can move only to `accepted` or `uncertain`. No automatic retry or status reset exists.
- Outbox schema verification is version-cached instead of running several table-introspection queries on every site request; send-time database errors still fail closed.
- Daily manual and scheduled reports share one report-date key. The exact report snapshot is stored before crossing the mail boundary. An accepted duplicate is suppressed and a fresh post-send snapshot remains a draft for review.
- Saved-report, live-show, and advertiser mail paths also claim before sending. Saved-report and advertiser resends require a fresh UUID and an explicit confirmation. Live-show sends are deduplicated by exact row snapshot.
- The Grosses Logs tab displays recent outbox status, type, source, date, mode, and exception/review note. It does not expose message payloads or a retry control.
- Added a private-table WordPress regression for unique concurrent claims, immutable payload, accepted/uncertain transitions, fail-closed database errors, and bounded inputs. Updated isolated Reporter fixtures for the outbox API and a manual-versus-scheduled duplicate case.

## Verification and limitations

- A PHP 8.0 grammar parser successfully parsed all 31 changed/existing PHP files reported by the worktree status. `git diff --check` passed.
- A private server-side candidate snapshot passed PHP 8.3 lint for the Grosses source and test files. Fresh-email allocation (25 checks), saved-report read-only (8 checks), attachment safety (31 checks), and advertiser email guard (6 checks) passed on PHP 8.3 with mail intercepted.
- The outbox regression passed 20 assertions against two independent MySQL connections. It created and removed a randomly named private test table in the site's database; it did not modify Grosses records or call mail/provider APIs. The test's `finally` cleanup and post-test completion both succeeded.
- The latest small source/test edits passed the local PHP 8.0 grammar parser. The advertiser fixture exercises the actual Workbook email path while replacing only XLSX file generation with a private attachment fixture; it verifies scheduled/manual deduplication, confirmed resend replay protection, and uncertain-result blocking.
- No production files or database changed; no mail was sent. No deployment has occurred.
- Final PHP 8.3 lint of all 246 local PHP files passed, and the live Grosses Logs tab was inspected read-only. The installed page still shows the legacy activity log and has no outbox-status section, as expected because this candidate has not been deployed. Its live totals and existing records were only viewed; no pull, reconciliation, report send, or setting change was triggered.
- Still required before deployment: make a fresh verified offsite backup for the deployment window and use one safe server connection to deploy the exact candidate. Afterward, verify the outbox table/schema and Logs rendering read-only, and run only an intercepted-send fixture; do not send test mail to real recipients. Verify duplicate suppression with fixture requests, not live sends.

## Operational meaning

`accepted` means WordPress accepted the message for handling, not that an inbox received it. `sending` after a process interruption and `uncertain` after an ambiguous result are intentionally blocking and require a manager to verify the mail-provider outcome. An intentional resend creates a new auditable key; it does not overwrite the original attempt.
