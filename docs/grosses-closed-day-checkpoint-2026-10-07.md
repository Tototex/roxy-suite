# Grosses closed-day refresh checkpoint — 2026-10-07

## Outcome

G3 is implemented for the approved provisional-report workflow. The scheduled
report keeps the intended Pacific calendar date in its one-shot cron arguments,
sends a clearly labelled provisional report, and durably queues an exact-date
closed-day review for 1:00am Pacific. The review reads the saved report
snapshot, flags changed emailed totals for manager review, and never sends an
automatic correction. It is idempotent and does not rewrite unchanged evidence.

The queue is protected by a MySQL advisory lock, refreshes WordPress's local
option cache before a locked read, verifies lock ownership before and after
writes, verifies the saved value, and retries failures at 15-minute intervals
with a maximum of three retries. A queue or cron-registration failure is
reported to Health rather than being treated as success.

## Verification

- PHP lint passed for all five changed runtime files.
- Isolated calendar/scheduler checks passed, including DST wall-clock behavior,
  legacy zero-argument migration, overdue event preservation, failed/no-op cron
  operations, provisional labeling, queue idempotence, and bounded retry state.
- Isolated store, reporter, refund, saved-report, and fresh-email suites passed.
- Actual WordPress scheduler fixture passed.
- Actual WordPress/database queue fixture passed 10 checks, including a second
  database connection committing another date while the first request had a
  stale option cache.
- Live installed hashes were checked after deployment and the read-only Health
  check reported the expected 11:00pm Pacific dated schedule. No live report,
  email, or cron callback was manually invoked.

## Backup and rollback

The five live Grosses files were archived before deployment at:

`I:\My Drive\Roxy Site Recovery\2026-10-07\grosses-closed-day\roxy-g3-live-20261007.tar.gz`

Verified SHA-256: `74bccf011f321b17d93e2ba37f8b8ba16a299c1cf3408ede060706959acbaf72`.

## Remaining boundary

This improves final-day review and evidence; it does not make WP-Cron itself
independent of the host runner, and it does not automatically resend a changed
report. Those are intentional controls under the approved workflow.
