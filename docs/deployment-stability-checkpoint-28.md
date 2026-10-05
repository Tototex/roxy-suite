# Checkpoint 28 — Members Dashboard pagination and historical review

2026-10-05. Suite 1.0.27 selectively deployed: Members Dashboard and Suite version only. No schema change, membership edit, admission, checkout, financial transaction, vendor order/email or public publication.

## Changes

The dashboard displays at most 50 matching subscriptions per page. Totals still cover all matching subscriptions, with the original visit/revenue/ID ordering, quantity normalization, trade exclusions and filters. Filter submission resets pagination; Previous/Next preserve all filters. Invalid or out-of-range pages normalize safely. Empty results remain valid.

Where there are no subscription query/result extension filters, capture subscription identities first, then read metadata and actual-admission aggregates in 100-ID batches. Retain compact ranking keys and build displayed rows only for the selected page. If an extension filters either the subscription query or complete returned collection, use the original subscription API and preserve the returned objects, including extension modifications. Both extension hooks currently have no registered live callbacks. Read failures stop the report instead of displaying partially calculated attendance/totals.

This is partial optimization, not query-level pagination: global filtering/ranking/totals still traverse all eligible identities, and Woo/WordPress object caches may retain hydrated data. The extension-compatible path still loads the full collection. No new report cache hides admissions/Undo, no HPOS activation and no rewrite of historic attendance.

## Verification and re-audit

- Installed Woo Subscriptions query implementation inspected before selecting the identity-only API. Live files matched Git 72326f7 after line-ending normalization before deployment.
- Changed PHP lint, whitespace checks and deployed/local file checksums match. Fresh WordPress reports 1.0.27.
- Synthetic 257-subscription test: 170 exact old/new parity checks across counting/status/photo/search filters and first/second/last/oversized pages. Additional checks cover invalid pages, filter-preserving navigation, extension-filtered collections/modified objects, and attendance read failures. Regression script requires a baseline dashboard source and candidate source; no actual subscription writes.
- 162 exact live old/new parity checks pass before deployment and again against deployed source. Aliased classes ensure the candidate is actually tested rather than an already-loaded older class. Tests print no customer details.
- Existing member regression (13 checks) and member-door regression (11 checks) pass against deployed source.
- Read-only original evidence audit: 1,230 tickets, 18,898 ticket metadata rows, 128 member log rows and 391 Will Call rows unchanged; no reservation fixtures remain.
- Browser: default report still shows 60 membership units across 37 counted subscription records, $942.56 normalized monthly revenue, zero October visits, 35 missing photos and 10 trade/comp records. Shows 37 of 37 rows; no error notices. Search with no matches displays the empty message and 0 of 0; Clear restores original values. Live data has fewer than 50 rows, so actual multi-page navigation is covered by synthetic fixtures, not claimed exercised on production. No photo/trade/settings actions submitted.

## Historical review

126 membership-check events (`nfc_scan`: 77 active and 49 inactive) are already excluded from actual attendance. Two active walk-up admission rows represent three people; these are not converted into lookup events. Read-only diagnostics found zero active rows with nonpositive quantity, zero missing subscription references, and zero NULL/zero timestamps. These diagnostics do not prove every historical event's correctness or validate all malformed-date cases. No guessed relabeling or reconciliation performed; M1 remains open where historical identity cannot be established.

## Recovery

One persistent SSH session. Originals and staged test sources are outside the web root at `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-members-reporting`.

Checksum-verified rollback archive: `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint28-rollback-files.tar.gz`. Server/local SHA-256: `657801164e940f07c2926545e2fd7629d3d23ae2af9542348caffa524e0c8ea3`. Local copy verified; Google Drive cloud synchronization is not asserted.

Restore the dashboard and Suite version from the originals, then recheck the report. No schema/data reversal is required.

## Next

Continue indexed/query-level reporting and the remaining cross-module audit findings. Review historical subscription/payment-date anomalies separately without silently rewriting renewal dates. Refund-after-attendance policy and ticket hold duration still require the user's choices. Advertising contract/renewal/reporting opportunities remain deferred until stability work is complete.
