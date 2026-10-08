# Checkpoint 31 — complete Grosses exports and genre analytics

2026-10-05. Suite 1.0.30 selectively deployed: Grosses Store, Reporter, Settings dashboard error handling and Suite version. No schema migration, reporting pull, email, order, checkout or historical financial update.

## Correction and impact

- G9: movie/live/rental/legacy CSV export previously requested the matching row count but received at most 1,000 rows from screen-oriented list methods. Genre averages/totals requested 5,000 and suffered the same 1,000-row clamp.
- New complete-data iterator uses existing dataset filters, 500-row pages and a collation-aware keyset cursor across date/time/title/ID. Existing screen list limits remain unchanged. Equal/case-insensitive titles have a stable ID tie-breaker. COALESCE keeps nullable cursor strings comparable. Initial maximum matching ID prevents newer inserts from appearing midway through the report.
- Genre attribution, case-sensitive genre keys, comma-token handling, metric choices and ranking rules remain unchanged. All matching rows now contribute rather than only recent capped rows.
- Export maps rows lazily and writes to a unique PHP temporary spool before sending attachment headers. A later database read or spool write/rewind failure returns a clear failure rather than a successful partial download. Temporary spool is closed/removed and no public upload URL is created. Normal header names, currency formatting, permission checks, nonce and filters retained.
- Final audit added a dashboard exception boundary: analytics failure renders an unavailable notice, exposes no database diagnostic/incomplete cards and returns control to the surrounding admin page. It does not convert the failure into a healthy zero.
- Low-to-moderate risk: read-only report paths and dashboard rankings affected, no receipt/accounting edits. Rankings across more than 1,000 rows intentionally change to include older rows. Existing CSV formatting remains unchanged, including currency strings. No claims about financial accuracy beyond parity with the existing source records.

## Verification

- All changed PHP lint passes; normalized deployed hashes match local source. Fresh WordPress reports Suite 1.0.30. Live baseline for Store/Reporter/Suite matched Git 89e0c2d. Settings difference was only trailing CRLF blank lines; normalized baseline matched Git, with no unrelated production code overwritten.
- `tests/grosses-complete-mysql.php`: 30 checks against deployed code on connection-private temporary tables. Every dataset gets 5,001 records; full order equals independent SQL, including tied/case-insensitive titles. 1,001-row day filters, search/month filters, empty filters and mid-read new insert exclusion pass. Genre totals/averages include the old beyond-cap record and preserve comma attribution. Initial and second-page SQL failures throw. Exact original production record digests unchanged in all four datasets; private temporary tables dropped.
- `tests/grosses-export-regression.php`: 15 standalone checks exercise the actual Reporter handler with a synthetic iterable. All four datasets and default fallback output 5,001 rows, original columns and currency formatting. Comma/quote names and multiline rental notes round-trip. Permission denial, bad nonce and a failure after 1,000 yielded rows return no partial CSV.
- `tests/grosses-complete-readonly.php`: actual all-column SELECT parity against independent ordered full queries before/after deployment: movies 2,029; live 66; rentals 19; legacy 815. No provider calls or writes.
- Chrome: Movies, Live Shows, Rentals and Legacy Movies load and actual Export CSV controls download successfully. Spreadsheet skill read-only import/reconciliation via bundled Artifact Tool verifies exact row counts and monetary totals against visible dashboard controls:
  - Movies: 2,029 rows; ticket gross $487,229.50; concessions $370,252.61.
  - Live: 66 rows; ticket gross $25,152.50; concessions $33,087.48.
  - Rentals: 19 rows; invoice gross $10,206.00; concessions $7,427.74.
  - Legacy: 815 rows; ticket gross $1,024,753.75; concessions $563,460.50.
- Signed-in Grosses Dashboard loads for Last 12 months and All time. All-time genre rankings include Adventure ticket gross $244,711.00 / concessions $193,121.38; genre average winners render. No automation run, edit save, report send or credential save clicked.
- Final fresh dashboard reload after Settings deploy retains all-time totals/rankings, with no captured browser console errors. Public homepage renders its current movie/event, ticket-price and showtime headings. This is a page-load smoke check, not a new checkout or full frontend certification.
- Existing 23 stability and 37 Square response tests pass on deployed source. Secret/settings suite now passes nine checks including graceful analytics failure. Corrected its test-only get_option stub to return saved settings only for the settings key; its earlier unrelated array-to-string warnings no longer occur. No live credentials/settings changed.
- Tests are isolated or read-only. Cart/payment/admission/subscription/vendor workflows are not exercised by this reporting-only checkpoint and are not newly certified.

## Recovery and limitations

Server originals/stage: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-grosses-export`, outside web root. One SSH connection at a time; interrupted session was confirmed closed before reconnecting.

Checksum-verified local Google Drive recovery copies, before their respective deploys:

- `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint31-rollback-files.tar.gz`: Store, Reporter and Suite originals. SHA-256 `0637a3dde1d5160d02fe16d8dd9cd8c5522456a992ab176d03c91eb67d0b7a0d`.
- `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint31-dashboard-rollback.tar.gz`: Settings original. SHA-256 `e329d7bf4dc14097a16843ad855a500cb26d4bb46031fb4642fb5e1f9eade378`.

Restore only these four PHP files if required; there is no schema/data rollback. Google Drive local copy is verified, cloud synchronization is not asserted.

The ID ceiling is not an immutable transactional snapshot: edits/deletes to existing records during traversal can change the result. Avoid editing/importing the selected records during an export; full snapshot semantics remain a separate hardening opportunity. Genre aggregation retains only group totals, but each dashboard metric traverses independently and SQL sorts may need future profiling for substantially larger data. Existing formula-like CSV text handling is unchanged and should be reviewed separately. Download delivery interruption after headers is not guaranteed recoverable.

G10 still covers other predictable/public workbook and attachment temporary paths. Accounting, report migration, shared Square snapshots, ticket/payment/subscription/Social concurrency and other open tracker items are not closed here. Advertising contracts/renewals remain deferred.
