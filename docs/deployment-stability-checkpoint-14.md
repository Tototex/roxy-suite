# Square reporting response integrity checkpoint

Only Grosses Square client deployed. Original SHA-256 matched HEAD; private rollback copy under `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261003-054238/checkpoint14`. No schema, schedule, credential or financial-history changes.

- Non-2xx, network failure, malformed JSON, scalar/array root, non-array response collections and API errors now throw instead of silently returning empty sales. Valid `{}` and `orders:[]` remain legitimate empty searches.
- Explicit full-order search. Invalid/missing/repeated order IDs, summary-only responses, malformed/repeated cursors fail without returning partial totals. Pagination capped at 100 pages and a 120-second budget; each request timeout at most 25 seconds, redirects off. Exceeding limits is an error, not truncated success. Location count checked before call.
- Strict calendar-date parsing prevents silent date rollover. Local-midnight windows still correctly cover 23- and 25-hour DST days; this does not fix the separate scheduler/workbook G7 findings.
- 37 isolated real-client fixture assertions pass against staged and deployed source, with exit code zero. Initial fixture network-error stand-in lacked the WordPress error method; corrected test double rerun passes. No production code change was required for that fixture issue.
- Actual read-only staged and deployed Square search for 2026-10-03 succeeds with seven orders. No orders, amounts, personal data or credentials printed. No report generation, financial recalculation, exports, email or accounting write called by fixture.
- Actual Reporter call paths were inspected: Square failure propagates to existing failure handling; no new fallback to zero added. Broader allocation/upsert/partial-failure issues remain open G findings, not certified by this checkpoint.
- Live Grosses page loads Roxy Grosses/Movies without fatal error or error notice. Browser inspection only; no Pull/Send/Save clicked.
- Contract cross-check: [Square SearchOrders reference](https://developer.squareup.com/reference/square/orders-api/search-orders), optional response lists and full-order versus summary query. API version pin unchanged.

S2 response-validation finding is resolved. G1–G11 financial definitions, refunds, immutable allocation, delivery/outbox, migrations, export completeness and scheduling remain separate work.
