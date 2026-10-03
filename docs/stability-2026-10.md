# Stability remediation tracker

Baseline: 910530d3bc77e17ac2dc267bac2bdadd6b5fae71 (1.0.16). Remote master verified identical; remote rollback branch `backup/pre-audit-stability-2026-10-02` preserved before edits. Implementation branch: `stability/audit-2026-10`.

Scope: all findings in [the audit](audit-2026-10-01.md). No new revenue features until stability work is complete. Tests must distinguish isolated regression, live browser smoke, and actual workflow verification. Passing lint is not a functional test. Never mark a cross-module finding complete after fixing only one entry point.

Current safeguards: preserve live customizations; back up changed files before deployment; external payment/publishing concurrency tests require isolated fixtures. Inventory email policy remains submission-only. Pepsi stock remains unopened boxes; Vistar stock remains individual units. Preserve existing studio nominal-gross policy pending clarification. Existing real inventory orders must not be submitted/cancelled for testing.

## Findings

| ID | State | Evidence / remaining verification |
|---|---|---|
| C1 | In progress | Inventory/Social coverage and honest diagnostic labels deployed; live diagnostics detect one existing failed Social job. Remaining modules need job freshness coverage. |
| C2 | In progress | Rollback branch preserved; changed-file backups and selective deployment used. Release-wide reproducibility/manifest workflow remains open. |
| C3 | Resolved | PHP 8.0 minimum declared in plugin and diagnostics; isolated regression and live PHP 8.3 diagnostics pass. |
| C4 | Open | See audit; no resolution claimed. |
| C5 | Resolved | Five-minute failure backoff; error/HTTP/malformed/missing-asset and successful-cache regression checks pass. |
| C6 | Open | See audit; no resolution claimed. |
| M1 | Open | See audit; no resolution claimed. |
| M2 | Open | See audit; no resolution claimed. |
| M3 | Open | See audit; no resolution claimed. |
| M4 | Open | See audit; no resolution claimed. |
| M5 | Open | See audit; no resolution claimed. |
| M6 | Open | See audit; no resolution claimed. |
| A1 | Open | See audit; no resolution claimed. |
| A2 | Open | See audit; no resolution claimed. |
| A3 | Open | See audit; no resolution claimed. |
| A4 | Open | See audit; no resolution claimed. |
| I1 | Resolved | Bulk rules sent as one validated JSON field; completion/count checks reject truncation before writes. 500-row fixture passes. Live Save All preserves all 858 values across 143 rows. DB write failures remain I9. |
| I2 | Open | See audit; no resolution claimed. |
| I3 | Resolved, confirmation workflow | GET only renders confirmation; signed links expire after 30 days; POST requires token-scoped nonce and preserves conditional status update. Prefetch, tampering, expiry, replay, confirmed POST/history redirect fixtures pass. Anonymous legacy links fail; signed-in authorized managers retain confirmation access. No real order changed for this test. |
| I4 | Open | See audit; no resolution claimed. |
| I5 | Resolved | Next local 23:00 single event replaces fixed daily recurrence. DST/migration fixtures pass; live 2026-10-02 23:00:52 pull succeeded. |
| I6 | Open | See audit; no resolution claimed. |
| I7 | Resolved | Vendor seeds no longer replace existing blank email/zero minimum/custom rules. Regression verifies no duplicate-key rule updates. |
| I8 | Open | See audit; no resolution claimed. |
| I9 | Open | See audit; no resolution claimed. |
| I10 | Open | See audit; no resolution claimed. |
| I11 | Resolved | Valid vendor form ownership; obsolete row renderer removed. Live unchanged Pepsi save preserves every vendor field. |
| T1 | Open | See audit; no resolution claimed. |
| T2 | Resolved for ticket API | Payment/order/item eligibility revalidated at admission. Isolated unpaid/missing-order checks and real $0 on-hold/processing transitions pass. Separate Will Call bypass is T5 and remains open. |
| T3 | In progress | Stable cumulative refund allocation deployed. Actual $0 Woo order 30623: successive quantity refunds leave two then one eligible ticket; repeated sync and undo cannot revive refunds. Historical bulk reconciliation and refund-deletion lifecycle still need audit. |
| T4 | Open | See audit; no resolution claimed. |
| T5 | Open | See audit; no resolution claimed. |
| T6 | Open | See audit; no resolution claimed. |
| T7 | Open | See audit; no resolution claimed. |
| T8 | Open | See audit; no resolution claimed. |
| T9 | Open | See audit; no resolution claimed. |
| T10 | Open | See audit; no resolution claimed. |
| T11 | Open | See audit; no resolution claimed. |
| T12 | In progress | Current-event filters applied before limits; 500-old-event regression passes; live Door Mode shows October events again. Older archive pagination remains open. |
| T13 | Open | See audit; no resolution claimed. |
| T14 | Open | See audit; no resolution claimed. |
| T15 | Open | See audit; no resolution claimed. |
| T16 | In progress | Site-timezone schema/week anchors and exclusion of generated duplicate identities deployed; timezone/DST/duplicate-exclusion fixtures pass. Full duplicate workflow still needs a disposable fixture. |
| B1 | Resolved | Buffered intervals compared without start-time prefilter. Boundary/trailing-buffer fixtures and live calendar smoke checks pass. |
| B2 | Open | See audit; no resolution claimed. |
| B3 | Open | See audit; no resolution claimed. |
| B4 | Open | See audit; no resolution claimed. |
| B5 | Open | See audit; no resolution claimed. |
| B6 | Open | See audit; no resolution claimed. |
| R1 | Resolved for current data | New requests store contacts only in protected metadata; conversion strips legacy contact summaries; excerpt/REST filters protect legacy copies. Production scan found zero requests and zero generated contact excerpts across showings, so no historical edits/cache purge needed. Pure fixtures and actual WordPress excerpt/REST hooks pass; anonymous REST 200 and public date picker checked. |
| R2 | Open | See audit; no resolution claimed. |
| R3 | Open | See audit; no resolution claimed. |
| R4 | Open | See audit; no resolution claimed. |
| R5 | In progress | Server-side backing deadline added, shared with rendering; exact/minute-only boundary, future, expired and invalid deadline fixtures pass. Monetary-unit guessing/versioned migration remains open. No backer charged for verification. |
| G1 | Open | See audit; no resolution claimed. |
| G2 | Open | See audit; no resolution claimed. |
| G3 | Open | See audit; no resolution claimed. |
| G4 | Open | See audit; no resolution claimed. |
| G5 | Open | See audit; no resolution claimed. |
| G6 | Open | See audit; no resolution claimed. |
| G7 | Open | See audit; no resolution claimed. |
| G8 | Open | See audit; no resolution claimed. |
| G9 | Open | See audit; no resolution claimed. |
| G10 | Open | See audit; no resolution claimed. |
| G11 | Open | See audit; no resolution claimed. |
| S1 | Open | See audit; no resolution claimed. |
| S2 | Open | See audit; no resolution claimed. |
| S3 | Open | See audit; no resolution claimed. |
| S4 | Open | See audit; no resolution claimed. |
| S5 | Open | See audit; no resolution claimed. |
| S6 | Open | See audit; no resolution claimed. |
| S7 | Open | See audit; no resolution claimed. |
| S8 | Open | See audit; no resolution claimed. |
| S9 | Open | See audit; no resolution claimed. |
| S10 | Open | See audit; no resolution claimed. |

## Deferred opportunities

- Advertising contracts/tracking on the website, monthly reporting, renewal management, and permission-based outreach: explicitly requested for follow-up after stability. Automatic renewal terms and payment authorization require a separate design review.
- Arcade seasonal ranking (A4) is an optional feature, not part of stability remediation.

## Environment constraints

- Live server account quota on initial inspection: 9,872 MB / 10,240 MB; do not create a full staging clone there without first allocating space. Filesystem free space is not account quota.
- Live plugin contains production edits relative to its old server Git checkout. Compare file content to local baseline before any overwrite. Health Check has known drift to reconcile deliberately.

## Live test evidence — first remediation checkpoint

- Two authorized `tototest` checkouts charged $0. Order 30621 issued one ticket; manual check-in and undo succeeded. Order 30623 issued three tickets; actual zero-dollar Woo refunds and status transitions passed. Both orders canceled afterward, retaining explicit test notes and refund history. All four tickets are canceled/refunded, with no check-in flag. Only test cart items removed; cart now empty. No real vendor orders or public Social posts submitted.
- Diagnostics correctly distinguish structural checks from actual checkout tests. Full run reports 9/10 because it now detects an existing failed Social job; this is not a new publish attempt.
- Existing Elementor frontend error observed on cart/checkout/booking: `window.elementorCommon.helpers.softDeprecated is not a function`. Paid Pro/core version compatibility requires separate review; no unrequested dependency upgrade performed.
- Cart retained test ticket quantities after checkout in the multi-tab session. Explicit cleanup verified. Investigate checkout/cart session clearing; do not assume normal customer sessions behave identically.
- All deployed PHP files linted; isolated stability, inventory, ticket eligibility, and showing-selection suites rerun against live source. These do not certify the remaining concurrency, gateway, accounting, or Social-provider findings.

### Second checkpoint

- Requested-showing privacy/deadline fixes and Inventory email-link confirmation deployed selectively with private file backups. No existing requester records found; no historical financial data edited.
- Request-page calendar lookup and backing deadline display work for November 7 at 14:00 (deadline October 24 at 14:00). No request or backing submitted.
- Existing four vendor orders remain Ordered with their original totals. No order decisions submitted against real vendor orders.
- User confirmed studio reports retain nominal ticket gross; financial totals use actual collections and refunds. Week/month advertiser attendance attribution still awaits an answer. No historical numbers recalculated in these checkpoints.
