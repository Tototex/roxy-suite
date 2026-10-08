# Exact Social account verification checkpoint

Selective Meta/Admin files; originals matched HEAD, private rollback copies under `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261003-054238/checkpoint11`. No schema change.

- Requires explicit numeric configured Facebook Page ID, selects exact match only, including later cursor pages. Reconstructs trusted Graph endpoint instead of following next URL; rejects non-2xx, malformed/error response, missing/repeated cursor, missing configured page. Bounded ten requests/thirty-second budget with per-request timeout at most twelve seconds and redirects off.
- Configured Instagram mismatch or missing candidate token fails without overwriting account settings. UI explains selection, missing Page and Instagram mismatch errors.
- Eleven isolated real-method fixtures: varied business order, later page, trusted URL, no configured page, missing match, HTTP/JSON/cursor failure, Instagram mismatch no writes, successful intended fixture connection. Credential regression suite also rerun against deployed source.
- Read-only staged live Graph selection confirms exact configured Page and Instagram; all checked connection options unchanged. Browser displays existing saved connection and new error guidance. No Verify/save/authorization/publish clicked against real connection.
- Endpoint cross-check: [Meta's public API collection](https://www.postman.com/meta/facebook/request/bqfxwbp/get-access-tokens-of-pages-you-manage). Direct developer pagination page returned 429; implementation additionally checked against actual live response and isolated cursor fixtures.

Provider publishing success/claim/retry semantics remain separate open findings.
