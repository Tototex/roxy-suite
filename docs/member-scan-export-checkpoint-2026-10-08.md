# Member scan-log CSV export checkpoint

Date: 2026-10-08
Scope: read-only audit and local code/test changes; no export of live member data.

The member scan-log exporter already used a fixed maximum-ID boundary, bounded 500-row queries, and literalized spreadsheet formulas. This follow-up builds the complete CSV in a uniquely named 0700 directory / 0600 file under the system temp directory, rejecting temp storage under the known WordPress roots or `DOCUMENT_ROOT`, then sends only after all pages and writes succeed. This is containment against configured roots, not protection against unconfigured web-server aliases.

- Invalid or failed maximum-ID reads stop before response headers, preventing an empty-looking successful export.
- Temporary-file creation, flush/close, database page reads, monotonically increasing IDs, short/zero-byte row writes, and delivery are checked. Filesystem warnings are suppressed on expected failure paths; failed generation cleans the private file before returning an error; shutdown cleanup covers abnormal request termination.
- A later-page database failure now returns no partial CSV and sends no headers or data.

Regression `tests/member-export-regression.php` verifies 1,201 rows in 500/500/201 batches, formula neutralization, filtering, snapshot boundaries, malformed max-ID failure, late-page failure with no partial output or temporary-file leak, docroot rejection, and successful/failed short-write handling.

Hosted workflow [37743442006](https://github.com/Tototex/roxy-suite/actions/runs/37743442006) passes PHP 8.0–8.4 lint and the PHP 8.3 full isolated cross-module suite. This does not verify web-server delivery after a client disconnect or live deployment. No live members or data were changed.
