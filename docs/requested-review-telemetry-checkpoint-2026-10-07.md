# Requested Showings review telemetry checkpoint — 2026-10-07

Requested Showings daily review now records a versioned latest-run result in `roxy_rs_daily_review_last_result`, including a run ID, start time, completion time, status, and sanitized failure detail. The daily review uses the existing connection-owned named lease when the full ticket storage layer is loaded, preventing overlapping cron workers from reviewing the same request set. Telemetry failures do not change the underlying review result.

Health now distinguishes no verified run, an in-progress run, a failed run, an incomplete result, and a completed run. A live no-request review was run after deployment on 2026-10-07 at 07:32:28 UTC. It wrote a completed result, sent no email, created no showing, and charged no payment. Health readback reported Requested Showings overall pass with the completed result.

Both changed PHP files passed PHP 8.3 syntax checks and their SHA-256 values matched the local candidate before replacement. The previous live files were retained in `/tmp/roxy-telemetry-backup-20261007` with hashes recorded before deployment. The focused funding-read regression had passed its pre-existing funding assertions before the SSH session dropped; the telemetry-specific completion/failure assertions require a bounded rerun. No real request, backing, order, or payment was changed.
