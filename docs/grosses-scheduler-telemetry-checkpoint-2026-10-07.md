# Grosses scheduler telemetry checkpoint — 2026-10-07

Grosses scheduled daily syncs and monthly advertiser sends now save separate versioned latest-run outcomes in `roxy_grosses_last_scheduled_sync_result` and `roxy_grosses_last_advertiser_send_result`. Each record includes a run ID, start time, completion time, status (`running`, `completed`, `skipped`, or `failed`), and a short message. Telemetry writes are best effort and never change the existing report, email, or schedule-repair result.

Health now reports those outcomes distinctly from the existing last-date and advertiser-month markers. The live read-only Health check confirmed the new fields are currently unavailable rather than falsely green because no real report or advertiser send was triggered during verification. Existing daily and advertiser cron registrations were intact, the advertiser marker for 2026-09 passed its freshness check, and no email or provider call was generated.

Both changed PHP files passed PHP 8.3 syntax checks and matched the local candidates by SHA-256 before replacement. The prior live files were retained in `/tmp/roxy-grosses-telemetry-backup-20261007`. A future controlled scheduled run should populate and verify the completed/failed paths; this checkpoint intentionally does not send production reports.
