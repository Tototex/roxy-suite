# Health record reads — 2026-10-07

Requested Showings, Will Call, Arcade, and Grosses no longer cast unavailable SQL counts to zero and show green. Their shared reader checks table identity/existence, SQL error state, and a nonnegative integer result. A missing table, thrown error, failed lookup/count, or malformed result shows **Read unavailable**, not an empty dataset. Requested Showings also checks errors on its active-request query.

Verification: 23 isolated actual-Health assertions passed before and after selective installation. Existing 28 Health freshness and 21 scheduler-outcome assertions passed against the candidate/installed source. PHP 8.3 syntax check passed and installed SHA-256 matches the local candidate.

Read-only installed WordPress check: no active requests, 2 backing records, 81 saved reports, 391 Will Call check-in records, and 3 Arcade score records. Existing schedules/outcomes unchanged. No admissions, emails, refunds, stock changes, or game/prize actions invoked. Original Health backup checksum verified on `I:\My Drive\Roxy Site Recovery\2026-10-07\checked-counts-and-ticket-cutoff` before replacement; cloud sync not certified.

This closes the reviewed false-zero count paths, not every Health query or module workflow. C1 remains in progress.
