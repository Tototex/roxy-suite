# Social health read safety — 2026-10-06

Suite 1.0.60 makes Social diagnostic counts explicitly unavailable when table/count reads fail or return incomplete, malformed, negative or overflowing values. Such reads can no longer be cast to zero and reported healthy. Legitimate zero counts still pass; real failed/overdue jobs still warn. A genuinely missing table remains a structural error. No jobs are scheduled, published or retried by these checks.

Twenty-eight actual-Health PHP 8.3 fixtures passed before/after selective deployment. Main execution found a missing `wp_date` fixture stub in the first Social case; the fixture was corrected and rerun without changing runtime acceptance rules. Installed read-only Social diagnostics still show the existing one failed job and zero overdue jobs. All ten installed structural sections pass. No attempt was made to republish an ambiguous failed job. Wider module regression coverage remains the 49-program successful checkpoint 62 run; no additional full-site certification is claimed here.

The baseline-checked previous files were archived and verified on I: before replacement. Archive: `I:\My Drive\Roxy Site Recovery\2026-10-06\roxy-health63-before.tar.gz`, 16,097 bytes, SHA-256 `86bd207c772fc1168f4330356d4227e0cd01ed40826743211709e43cdb2aff11`. Version last and candidate/live hash readback guards were used. Cloud sync was not verified.

C1 remains partial: recording trustworthy completion outcomes for the other scheduled jobs requires checked underlying reads and separate automatic/manual telemetry. Requested-showing funding-read safeguards are under review next.
