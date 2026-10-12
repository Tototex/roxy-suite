# Grosses outbox Unicode claim failure — 2026-10-12

The Oct 11 scheduled provisional report failed before `wp_mail()` because WordPress rejected the prepared outbox claim query when the JSON payload contained Unicode punctuation. `EmailOutbox::encode_json()` now leaves JSON Unicode escaped (ASCII-safe) while preserving the original decoded subject/body. This addresses WordPress's database charset validation at the durable-send boundary; it does not change report content or recipient policy.

Verification:

- Reproduced the old query rejection against a private temporary MySQL table and verified the corrected claim against that same database path.
- Ran all 20 actual `EmailOutbox` checks against a uniquely named private MySQL table on the live host. The test verified create/repeat schema, Unicode-bearing claim, independent-connection duplicate rejection, immutable result state, invalid UTF-8/oversize/date rejection, and fail-closed read/write errors. The fixture removed its private table; no mail or provider call was made.
- Added the regression to the disposable-MySQL GitHub Actions job. Hosted run [38218023435](https://github.com/Tototex/roxy-suite/actions/runs/38218023435) completed successfully; its job log confirms all 20 checks passed.
- The deployed outbox source hash matches the tested source at commit `a18cbf9`. A read-only inspection found an Oct 11 daily report row in `accepted` state, meaning WordPress accepted it for handling, not proof of delivery. Its recipients had zero exact matches against active Inventory vendor email settings. No report was triggered or sent during verification.

Still open under G8: broader live Logs-tab smoke/deployment parity and verification of all outbox routes. The test did not simulate an actual mail delivery and does not claim one.
