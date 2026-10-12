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
- Hosted PHP syntax, isolated suite, and private WordPress/Woo fixture are pending for this checkpoint.
