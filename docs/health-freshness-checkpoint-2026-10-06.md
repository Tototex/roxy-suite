# Health freshness checkpoint — 2026-10-06

Suite 1.0.58 selectively updates the read-only Health reader. Event Booking now exposes the saved daily monitor outcome, not just whether a recurring event exists. Missing, invalid, future or older-than-36-hour results warn; a recent failed result fails. Opening Health never reruns the monitor.

Inventory freshness uses the checked run-history reader and checks SQL errors in the success-history lookup. Invalid/future timestamps and incomplete records cannot appear fresh. An unreadable history is reported as unavailable rather than mistaken for an empty history.

Sixteen isolated actual-Health fixtures passed on PHP 8.3 before and after deployment, including failure/staleness cases and zero HTTP, mail, scheduling or job execution. Read-only installed structural checks passed for all ten sections. Installed Inventory reported a successful pull at 2026-10-05 23:00:07; Event Booking's saved monitor was successful at 2026-10-06 00:55:40. These are saved outcomes, not newly executed jobs. Authenticated browser checks remain pending login; no full-site certification is claimed.

The two previous live files were baseline-checked, archived and verified locally before replacement. Recovery archive: `I:\My Drive\Roxy Site Recovery\2026-10-06\roxy-health61-before.tar.gz`, 15,082 bytes, SHA-256 `8e6c567a6464c1435cc1a2c836d3f9c680ca94eb0bd02e6e6eb57173770cbe28`. Local verification does not establish Google Drive synchronization. Candidate hashes were checked before replacement and after readback; the version file was replaced last.

C1 remains partial: Requested Showings, Grosses/advertising and Social job-outcome telemetry still need review. No scheduled behavior, report resend policy or provider integration was changed here.
