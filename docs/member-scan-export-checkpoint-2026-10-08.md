# Member scan-log CSV export checkpoint

Date: 2026-10-08  
Scope: read-only audit and local code/test changes; no export of live member data.

The member scan-log exporter already used a fixed maximum-ID boundary, bounded 500-row queries, and literalized spreadsheet formulas. This checkpoint tightens failure handling:

- Invalid or failed maximum-ID reads stop before response headers, preventing an empty-looking successful export.
- Output-stream open failure, header/data row write failure, and incomplete-marker write failure are checked and surfaced.
- Existing behavior on a database page-read failure remains an explicit `EXPORT_INCOMPLETE` marker in the streamed output.

Regression `tests/member-export-regression.php` continues to verify 1,201 rows in 500/500/201 batches, formula neutralization, filtering, and snapshot boundary; it now verifies malformed max-ID failure before output.

Hosted workflow [37743442006](https://github.com/Tototex/roxy-suite/actions/runs/37743442006) passes PHP 8.0–8.4 lint and the PHP 8.3 full isolated cross-module suite. This does not verify web-server delivery after a client disconnect or live deployment. No live members or data were changed.
