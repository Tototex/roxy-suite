# Grosses Health schedule freshness checkpoint — 2026-10-08

## Finding and correction

The Health panel compared `roxy_grosses_last_auto_date` with the current calendar date at all hours. This incorrectly warned before the configured daily send time, when the previous day's successful report is still the most recent report that could be expected. The freshness check now uses the configured Grosses report timezone and send time to determine the most recent scheduled report date. A just-missed run is a warning; two or more missed scheduled dates remain a failure. Invalid or non-string schedule values warn without coercion. Disabled scheduling retains its existing non-required behavior.

## Verification

- The focused isolated actual-Health regression suite passes 53 cases, including before/after schedule boundary, a missed run, a completed run, malformed dates, future dates, and malformed/non-string schedule settings.
- GitHub Actions run [37849078167](https://github.com/Tototex/roxy-suite/actions/runs/37849078167) passed PHP syntax on 8.0–8.4 and the disposable Requested Showings WordPress/WooCommerce and Social private-MySQL lifecycle fixtures.
- The production host PHP 8.5 lint and the same 53-case focused regression passed against the staged candidate.
- Before deployment, the original live file was copied to `I:\My Drive\Roxy Site Recovery\2026-10-08\Selective file backups\class-roxy-suite-health.php.before-grosses-freshness-fix` and `/home1/anrvxfmy/roxy-recovery/2026-10-08/class-roxy-suite-health.php.before-grosses-freshness-fix`. Both backup hashes equal the original live hash `aa32c141535ceb2ba1b7254acd1f53d8a079aa1006d3cd0976b4e3bbc81fe8e4`.
- Only `includes/class-roxy-suite-health.php` was atomically deployed. Production hash `cb339912bed8e6156c0119ee80034e9c430d6d60b12bd216d11ba0f8c93db485` matches the candidate; production PHP lint passes.
- The read-only production Grosses Health diagnostics now show `Last automatic run: 2026-10-07` as `pass` before the next scheduled run at `2026-10-08 23:00 PDT`. The advertiser scheduler's `Skipped` outcome remains a warning because it is not due that day. No scheduled job was invoked, and no records, settings, email, payment, order, or external provider state were changed.

## Scope and remaining work

This corrects one C1 diagnostic false alarm; it does not resolve the broader cross-module stability objective. The separate branch-only anomaly-status/filter UI correction remains undeployed, the known Social failed-job warning still requires review, and authenticated staff workflows and the other tracker items remain open. The one-file deployment does not imply that the branch as a whole is live.
