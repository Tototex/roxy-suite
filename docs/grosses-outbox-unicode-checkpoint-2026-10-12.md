# Grosses outbox Unicode claim failure — 2026-10-12

The Oct 11 scheduled provisional report failed before `wp_mail()` because WordPress rejected the prepared outbox claim query when the JSON payload contained Unicode punctuation. `EmailOutbox::encode_json()` now leaves JSON Unicode escaped (ASCII-safe) while preserving the original decoded subject/body. This addresses WordPress's database charset validation at the durable-send boundary; it does not change report content or recipient policy.

Verification:

- The supplied failure was stamped 2026-10-11 23:00:44 PDT; the ASCII-safe JSON fix was committed at 23:51:05 PDT, so this incident predates the fix by about 50 minutes.
- Reproduced WordPress's exact charset rejection in a disposable WordPress/MySQL fixture using a restrictive cached `ascii` charset and four-byte Unicode. The pre-fix raw-JSON write fails with `Could not perform query because it contains invalid data`; the fixed outbox claim then succeeds with the same subject/body preserved in escaped JSON.
- The expanded regression passes 21 checks against a uniquely named private MySQL table. It also verifies create/repeat schema, independent-connection duplicate rejection, immutable result state, invalid UTF-8/oversize/date rejection, and fail-closed read/write errors. The fixture removed its private table; no mail or provider call was made.
- Added the regression to the disposable-MySQL GitHub Actions job. Hosted run [38218934291](https://github.com/Tototex/roxy-suite/actions/runs/38218934291) completed successfully across PHP 8.0–8.4; its job log confirms the reproduced failure, successful fixed claim, all 21 outbox checks, and zero mail/provider calls.
- The deployed outbox source hash matches the tested source at commit `a18cbf9`. A read-only inspection found an Oct 11 daily report row in `accepted` state, meaning WordPress accepted it for handling, not proof of delivery. Its recipients had zero exact matches against active Inventory vendor email settings. No report was triggered or sent during verification.

Still open under G8: broader live Logs-tab smoke/deployment parity and verification of all outbox routes. The test did not simulate an actual mail delivery and does not claim one.
