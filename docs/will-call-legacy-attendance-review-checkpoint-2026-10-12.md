# Will Call legacy attendance review guard — 2026-10-12

## Issue

Older Will Call data stores one aggregate `used_qty` per customer and context. Newer ticket records retain individual admissions and their sources. When the saved aggregate exceeds the current eligible checked-in ticket count, the system cannot safely tell whether that difference is historic, stale, or independent. Replacing the aggregate with the ticket count would silently lose prior attendance; adding the counts could double-count.

## Change

- The Will Call view detects a saved aggregate greater than the ticket-level count, labels the row `Review required`, displays both values, disables its controls, and shows a page-level warning/count.
- Exact ticket-level totals continue to use ticket records only; uncertain aggregate values are not added to them.
- The transactional `apply_will_call_group()` path independently rejects writes when a ticket-backed group has a greater saved aggregate than current checked-in ticket identities. The check occurs while holding the existing order/customer locks, so a crafted request cannot bypass the read-only UI.
- No automatic merge, migration, or historical rewrite was performed. Resolution of flagged history requires a future manager reconciliation policy.

## Verification status

- Added standalone regression coverage for separate display of the historical and ticket-level counts.
- Added a disposable WordPress/Woo fixture that will assert the warning/disabled controls, unchanged aggregate after rendering, and failed transactional overwrite with unchanged ticket and aggregate state.
- Hosted run [38231760221](https://github.com/Tototex/roxy-suite/actions/runs/38231760221) passes PHP 8.0–8.4 and the full PHP 8.3 suite. The private WordPress/Woo fixture confirms the warning/disabled row, no mutation on render, refusal to overwrite the higher aggregate, and preservation of both underlying states. A second fixture case confirms a separate QR undo cannot silently leave then overwrite a stale aggregate.
- The guard remains branch-only; no production file or historical record was changed. Resolving flagged rows still requires a manager reconciliation policy.

## Selective live UI deployment — 2026-10-15

- Reconciled the installed sources before deploying. The live Ticket API already matched the tested branch and included the transactional refusal guard, so it was not overwritten. The Will Call admin file contained unrelated live-only ticket-order query optimizations; those were preserved. Only the legacy-count review warning, disabled row controls, summary metric, and authoritative-count tagging were merged into that live file.
- Exact pre-deploy Will Call file SHA-256: `79dd956cdbc0ab52dc4010679bd810c2c5054f8f0d5c0b3f10982eee9c1054f8`. Verified rollback copies: `I:\My Drive\Roxy Site Recovery\2026-10-15\will-call-review-ui\roxy-will-call-live-predeploy.php` and `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261015-will-call-review/roxy-will-call.php`.
- The selectively merged candidate passed the production PHP 8.3.35 lint before replacement. Atomic replacement completed; installed live SHA-256 is `57bb47ce422511ebac04fca21a5ba828fb2a15e55704599b1656fe74076ebbf0`, and production PHP lint passes after replacement.
- A read-only WP-CLI evaluation invoked the deployed authoritative detector across 76 contexts and found 44 saved customer rows requiring review. It changed no data. Their saved counts remain intact and will be excluded from exact ticket totals; do not automatically reconcile them.
- An installed-code WP-CLI render test used one existing flagged context and a redacted synthetic display row; all five assertions passed: summary review metric, row warning, both legacy/ticket counts shown, row marked read-only, and both controls disabled. Output was reduced to booleans only; no customer data was printed and no records changed.
- The authenticated admin page could not be visually verified: browser address-bar navigation did not complete, so this is not claimed as a successful UI smoke. No admission, order, or historical record was changed. The private WordPress/Woo render and guarded-write fixture plus PHP 8.0–8.4 CI remain the functional test evidence ([run 38231760221](https://github.com/Tototex/roxy-suite/actions/runs/38231760221)).
