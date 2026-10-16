# Audit continuation — 2026-10-16

Retried the production SSH path once using the configured Roxy key. The host refused the SSH banner before authentication, so post-deploy Social schema-version/column verification remains pending. No rapid retries were made.

Re-ran the available workstation regressions for Booking assets, Inventory cost UI, Member Door Mode, Social picker, Will Call JS, release guard, and deployment-manifest generation. All passed, including 15 release-guard checks. These local checks do not substitute for PHP/MySQL fixtures or hosted browser tests.

No production state changed. The existing `.codex-live-*` recovery folders remain untracked and untouched.
