# Audit continuation — 2026-10-16

Retried the production SSH path once using the configured Roxy key. The host refused the SSH banner before authentication, so post-deploy Social schema-version/column verification remains pending. No rapid retries were made.

Re-ran the available workstation regressions for Booking assets, Inventory cost UI, Member Door Mode, Social picker, Will Call JS, release guard, and deployment-manifest generation. All passed, including 15 release-guard checks. These local checks do not substitute for PHP/MySQL fixtures or hosted browser tests.

No production state changed. The existing `.codex-live-*` recovery folders remain untracked and untouched.

Grosses Cashflow production smoke (read-only): changing the date on the Cashflow page and submitting produced the page's “report request expired” notice. Refreshing the form with the selected date and submitting succeeded, proving the nonce was bound to the originally rendered date rather than the chosen date. A Cashflow snapshot for 2026-10-15 then completed: Square $0.00 collected/$0.00 refunded; WooCommerce $31.95 collected across two transactions/$0.00 refunded; no excluded review exceptions. The page states this snapshot is not saved or emailed. No historical report, order, setting, or email changed.

Fixed the Cashflow form by using one capability-protected nonce action independent of the selected date, and added `tests/grosses-cashflow-date-nonce-regression.php`, which simulates a manager choosing a different date after the original form render and verifies that the submitted nonce reaches the requested read-only calculation. Hosted workflow [38885900437](https://github.com/Tototex/roxy-suite/actions/runs/38885900437) completed successfully for commit `c44dcfd7eddbb20f2989a6ce3997c6969bddde94`: all eight jobs passed, including PHP 8.0–8.4 lint/regressions, Social browser regressions, private MySQL fixtures, and disposable WordPress/WooCommerce lifecycle. The change is pushed to `stability/audit-2026-10`, not deployed. Deployment is pending a reachable SSH host for fresh live-file comparison and verified pre-deploy backups.
