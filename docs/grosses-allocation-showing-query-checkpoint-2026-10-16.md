# Grosses concession showing-query checkpoint — 2026-10-16

## Change

`Reporter::apply_concessions_to_reports()` computed the dated-row count and fetched all movie/live showings inside the Square line-item loop. Both are invariant across a date's allocation, so each eligible concession line repeated two WordPress showing queries and report-row scans. The calculation now runs once before processing orders/lines. Matching and allocation rules are unchanged.

## Verification

- `tests/grosses-reporter-snapshot-rebalance-regression.php` enables a single actual CPT-showing fixture and allocates 500 eligible Square concession lines. The result remains exactly $500.00 while `get_posts()` remains fixed at five calls for the whole allocation (the existing setup/matching reads plus one invariant calculation), instead of growing with line count.
- Hosted run [38876603397](https://github.com/Tototex/roxy-suite/actions/runs/38876603397) passed PHP 8.0–8.4 syntax/regressions, the full PHP 8.3 suite, Social browser/private-MySQL checks, and Requested Showings/Woo lifecycle.
- Before deployment the live reporter exactly matched the candidate's parent version (SHA-256 `92b8a7d6659335d3f6481af8266591ea8835307b7eb153fcd00dfd73389b6979`). The original is backed up on I: at `I:\My Drive\Roxy Site Recovery\2026-10-16\grosses-allocation-showing-query\class-roxy-grosses-reporter.php.original` and on the host at `/home1/anrvxfmy/deploy-backups/roxy-suite-20261016-grosses-allocation-showing-query/class-roxy-grosses-reporter.php` with the same SHA-256.
- The tested candidate was staged and linted before an exact-baseline check and same-filesystem replacement. Installed SHA-256 `b246f8ae346a5b3a79b6ade7ccdfd543e213d074be8e4c5eaea134ad506bc980` matches the branch; live PHP lint passes, the Suite plugin is active under `roxy-suite/roxy-suite`, and the homepage HEAD request returned HTTP 200.
- No daily pull, report refresh, database write, email, historical correction, or external order was triggered during this verification.
- The scale regression expansion is committed as `0ae499e` and hosted run [38877267193](https://github.com/Tototex/roxy-suite/actions/runs/38877267193) passed all eight jobs, including the full PHP 8.3 regression suite, PHP 8.0–8.4 syntax, private MySQL, Social browser, and Requested Showings/Woo lifecycle jobs.

## Remaining scope

This closes the repeated-showing-query inefficiency. G11 remains open for a representative populated/scale benchmark of the full allocation pipeline; the existing report-date EXPLAIN only established index selection on current production data.
