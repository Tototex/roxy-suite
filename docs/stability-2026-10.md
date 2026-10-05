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
| M1 | In progress | Dashboard sums active admission quantities, excluding verification/inactive scans. Actual SQL fixture and live baseline pass. Undo reconciliation and historical requested-vs-actual quantities remain open. |
| M2 | Resolved | Versioned schema, one request-local check; real dbDelta against disposable legacy table preserves history and recovers missing table even with current version. Live 128 rows unchanged. |
| M3 | In progress | Failed log inserts return failure, not admitted. PHP and actual SQL failure fixtures pass. Reserved-ticket/log atomicity and safe concurrent retries remain open. |
| M4 | Resolved | Unified log filters/pagination/export preserve tab; nonce-protected admin-post export before HTML. Live log and export link inspected; standalone navigation checks pass. |
| M5 | Resolved | Nonlogging lookup includes last actual admission, excluding lookups/inactive scans; strict site-timezone dates. PHP and actual SQL assertions pass. |
| M6 | In progress | CSV streams 500-row keyset batches with fixed upper boundary and literal formula escaping; 1,201-row fixture passes. Dashboard subscription pagination remains open. |
| A1 | Open | See audit; no resolution claimed. |
| A2 | Open | See audit; no resolution claimed. |
| A3 | Open | See audit; no resolution claimed. |
| A4 | Open | See audit; no resolution claimed. |
| I1 | Resolved | Bulk rules sent as one validated JSON field; completion/count checks reject truncation before writes. 500-row fixture passes. Live Save All preserves all 858 values across 143 rows. DB write failures remain I9. |
| I2 | Resolved, normal connection lifecycle | Serialized state lock plus transactional open-order check and unique submission identity; replay returns existing order without second email. Real MySQL two-connection contention and duplicate-open-order fixtures pass. Database disconnect/reconnect fault injection remains I9. |
| I3 | Resolved, confirmation workflow | GET only renders confirmation; signed links expire after 30 days; POST requires token-scoped nonce and preserves conditional status update. Prefetch, tampering, expiry, replay, confirmed POST/history redirect fixtures pass. Anonymous legacy links fail; signed-in authorized managers retain confirmation access. No real order changed for this test. |
| I4 | In progress | Stock compared against pre-pull inventory, not only original submission snapshot; 40 → 20 → 25 arrival fixture unlocks order. Triggering item/from/to/time retained; concurrent cancellation cannot be overwritten. Net changes between pulls can still miss delivery plus sales or mistake corrections for receipts; adjustment-event reconciliation remains. |
| I5 | Resolved | Next local 23:00 single event replaces fixed daily recurrence. DST/migration fixtures pass; live 2026-10-02 23:00:52 pull succeeded. |
| I6 | Open | See audit; no resolution claimed. |
| I7 | Resolved | Vendor seeds no longer replace existing blank email/zero minimum/custom rules. Regression verifies no duplicate-key rule updates. |
| I8 | Resolved | All tracked vendor items shown, including zero suggestions; server requires exact reviewed snapshot and explicit complete integer quantities, validates fresh costs/minimum/recipient. Missing/stale quantities never fall back to suggestions. Isolated fixtures pass; live Pepsi has all 22 items, remains locked Ordered with no Submit control. |
| I9 | In progress | Bulk rules, pulls, order creation/status/progress use serialized transactions and checked reads/writes. Injected later-write failure rolls back earlier change in actual temporary MySQL tables. Schema deployment caught a missing additive column: immediately rolled back, fixed explicit repeatable upgrade, tested and redeployed with all row hashes intact. Logging/legacy handler errors and DB reconnect failure remain. |
| I10 | Resolved for history/progress | 50-row pages and escaped vendor search; real 103-order fixture returns 50/50/3. Live search returns Pepsi, itemized checklist opens. Payload revision compare-and-swap prevents stale checklist overwrite, records actor/time when changed; stock-reset evidence retained. |
| I11 | Resolved | Valid vendor form ownership; obsolete row renderer removed. Live unchanged Pepsi save preserves every vendor field. |
| T1 | Open | See audit; no resolution claimed. |
| T2 | Resolved for ticket API | Payment/order/item eligibility revalidated at admission. Isolated unpaid/missing-order checks and real $0 on-hold/processing transitions pass. Separate Will Call bypass is T5 and remains open. |
| T3 | In progress | Stable cumulative refund allocation deployed. Orders 30623 and 30629 exercised actual $0 Woo refunds. Refund deletion now triggers ticket reconciliation; actual fixture refund deletion restores eligibility via installed Woo hook contract. Historical bulk reconciliation and attendance-after-refund policy still need audit. |
| T4 | In progress | Checkpoints 19–20: serialized issuance and individual admission/undo with checked transactions, guarded reconnect replay, stable sequence recovery, and bounded issuance retries. Twelve actual MySQL and thirty-two real Woo fixture assertions pass, including two independent staff workers and mid-write admission/undo failures. Live $0 checkout, staff check-in, stale replay rejection and undo pass. All 1,223 pre-existing ticket row digests unchanged. Group/member log coordination and shared seat authority remain open. |
| T5 | In progress | Will Call uses common paid/unrefunded ticket API, preserves actual QR/manual identities and requires explicit undo restricted to Will Call admissions. Same-customer canceled/missing-order fixtures pass. Live order 30629: manual first ticket retained while Will Call admits remainder. Hybrid legacy/new-ticket customers and atomic cross-module locking remain. |
| T6 | Open | See audit; no resolution claimed. |
| T7 | Open | See audit; no resolution claimed. |
| T8 | In progress | Will Call subtracts refunded quantities and collected line/tax refunds; cache includes ticket labels and invalidates on refund creation/deletion. Actual order 30629 quantity 3 → 2 → 3 passes. Sales/capacity/profile mapping/history policies remain open. |
| T9 | In progress | Will Call initially reads actual ticket check-ins; session-scoped two-hour queue, baseline conflict checks, per-row in-flight controls and operation-specific removal prevent stale state/older completion overwrites. Live stale-page rejection and refresh passed; mocked delayed-completion/client checks pass. Atomic server ledger/two-device simultaneous saves remain T4/T9. |
| T10 | In progress | Checkbox now explicitly sets Used quantity; member walk-up Used initializes from real admission and is read-only. JavaScript fixtures pass. Historical member rows surviving membership expiry still pending. |
| T11 | In progress | Member validation only admits with explicit Auto Admit flag; off/missing flag verifies only and provides explicit button. Reserved admission logs actual changed eligible tickets; canceled/refunded reservations cannot create phantom visits. Seven PHP/three mocked JS checks pass; deployed Door Mode asset, checkbox and event refresh checked. Reserved-ticket/log atomicity and real camera/NFC member scan still unverified. |
| T12 | In progress | Current-event filters applied before limits; 500-old-event regression passes; live Door Mode shows October events again. Older archive pagination remains open. |
| T13 | Open | See audit; no resolution claimed. |
| T14 | Resolved | Will Call ticket-type pills use createTextNode/textContent, not raw label HTML. Mock DOM hostile-label regression verifies literal text. Real malicious public product not created for testing; Door Mode label sinks already escape values. |
| T15 | In progress | Checkpoint 20: individual ticket writes are checked and transactional; manual/undo routes reject unsuccessful persistence. Actual later-write failures and live stale-repeat rejection pass. Will Call batch/map persistence and indexed showing/customer query optimization remain open; no latency benchmark claimed. |
| T16 | In progress | Site-timezone schema/week anchors and exclusion of generated duplicate identities deployed; timezone/DST/duplicate-exclusion fixtures pass. Full duplicate workflow still needs a disposable fixture. |
| B1 | Resolved | Buffered intervals compared without start-time prefilter. Boundary/trailing-buffer fixtures and live calendar smoke checks pass. |
| B2 | Open | See audit; no resolution claimed. |
| B3 | Open | See audit; no resolution claimed. |
| B4 | Open | See audit; no resolution claimed. |
| B5 | Open | See audit; no resolution claimed. |
| B6 | Resolved | Suite and standalone serve canonical module assets; mirrored old URLs retained for cached pages with drift regression check. Intentional zero prices/lead/pizza boundary settings preserved. Live Book now, changed future date, pizza time boundaries, week/month verified; six mocked helper checks pass. Production prices unchanged, no booking submitted. |
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
| S1 | In progress | Connection-owned locks serialize publisher/remover and administrative mutations; SQL guards reject wrong/released owners. Queue claims before scheduling; stale jobs require review, never automatic approval. Auto-approval excludes Needs Review/manual/error/publication evidence. Review approval now requires an explicit nonce-protected POST confirmation and displayed revision: 10 isolated route/render checks and 4 actual WordPress/SQL checks pass. Original rows unchanged. Remote reconciliation remains human-driven; resumable video work remains open under S10. |
| S2 | Resolved | Social validates HTTP/JSON/required IDs and strict deletion confirmation; uncertain publication requires review. Grosses Square rejects malformed/error/non-object responses and incomplete pagination, preserves legitimate empty results, validates dates and stops loops. 33 Social fixtures, 18 real Store SQL checks, and 37 Square response fixtures pass; live read-only yesterday search returns seven orders. Reporting page loads without error; no report sent or historical totals changed. |
| S3 | Resolved | Exact configured Page selection across bounded cursor pages; no auto-selection of unrelated business. Configured Instagram mismatch preserves settings. Eleven fixture checks; actual live Graph read matches configured Page/Instagram without option writes; deployed error guidance inspected. |
| S4 | Resolved for current worker/admin paths | Full-row revision/status writes under worker ownership; approval freezes content. AI/media late results cannot overwrite manager edit/approval. Manual edits revoke approval and are distinguished from generated captions; generation/auto-approval cannot replace them. AI-first and media-first completions handled without losing pending work. 32 actual snapshot SQL and 12 real AI-worker fixture checks pass; live private draft save succeeds and old-tab save is rejected. Fixture 186 removed with private backup; original post-row digest unchanged. |
| S5 | Resolved for current campaign/AI schedule paths | Actual published showing references produce sorted date/year/time lines; no assumed weekday defaults or positional slicing. Date/title changes during AI generation require review. Approval/publish gates reject stale/extra schedule lines without rewriting approved captions; partial retry retains platform IDs. 25 schedule fixtures, 35 publisher fixtures, 12 AI race checks pass. Actual WordPress reads validate two upcoming records and three captured October 30 campaign drafts; no production Social row changed. Live check uncovered and fixed filtered-vs-canonical title mismatch. Optional next-show tease disabled until verified showing facts exist. Existing weekend campaign scope unchanged; query bounds/resumable jobs remain S10. |
| S6 | Resolved for confirmed outcomes | Failed/partially published rows can remove recorded IDs; retries target only remaining confirmed IDs. Per-platform connection checks permit Facebook-only/Instagram-only publication. Removal redirect reports actual result and UI exposes removal. Live ten rows, nine Remove live post links and one Retry remove inspected; no actual publish/delete performed. Concurrent workers/removals and ambiguous reconciliation remain S1 work. |
| S7 | Open | See audit; no resolution claimed. |
| S8 | Resolved | Draft picker renders provider filenames via textContent/dataset, rejects legacy cached HTML, stores structured search results, checks IDs, and uses one handler. Five mocked DOM assertions pass; six actual PHP-rendered scripts compile. Live chooser opens once, Hangar search returns 200 assets and safe cached reopen works; Media Library opens. No import/assignment/save/publish clicked. |
| S9 | Resolved | Social and Grosses authenticated versioned encryption; crypto outage preserves existing settings, corrupted/key-rotated credentials fail closed with reconnect notices. Legacy CBC/old plaintext migrated using actual SQL compare-and-swap; 15 Social/7 Grosses crypto checks, disabled-crypto fixtures, 7 Social/8 Grosses MySQL checks pass. Live decoded credentials preserved, other Grosses settings unchanged, Square locations read succeeds; no credentials printed. |
| S10 | Open | See audit; no resolution claimed. |

## Deferred opportunities

- Advertising contracts/tracking on the website, monthly reporting, renewal management, and permission-based outreach: explicitly requested for follow-up after stability. Automatic renewal terms and payment authorization require a separate design review.
- Arcade seasonal ranking (A4) is an optional feature, not part of stability remediation.

## Environment constraints

- 2026-10-05: user-authorized core prerequisite completed: posts, postmeta, Woo order items, and itemmeta now InnoDB. Real offsite restore/conversion tests and four-table rollback passed with identical original record digests. This does not close remaining transactional code findings. See deployment checkpoint 18.
- Server Updraft archive cleanup preserved checksum-verified copies on the user's I: Google Drive. Quota now approximately 3,662 MB / 10,240 MB. Actual new database backup uploaded to Drive and its downloaded gzip validated; delete-local enabled. Earlier zero-byte DB archives are not usable backups.

- Live server account quota on initial inspection: 9,872 MB / 10,240 MB; do not create a full staging clone there without first allocating space. Filesystem free space is not account quota.
- Live plugin contains production edits relative to its old server Git checkout. Compare file content to local baseline before any overwrite. Health Check has known drift to reconcile deliberately.

## Live test evidence — first remediation checkpoint

- Two authorized `tototest` checkouts charged $0. Order 30621 issued one ticket; manual check-in and undo succeeded. Order 30623 issued three tickets; actual zero-dollar Woo refunds and status transitions passed. Both orders canceled afterward, retaining explicit test notes and refund history. All four tickets are canceled/refunded, with no check-in flag. Only test cart items removed; cart now empty. No real vendor orders or public Social posts submitted.
- Diagnostics correctly distinguish structural checks from actual checkout tests. Full run reports 9/10 because it now detects an existing failed Social job; this is not a new publish attempt.
- Existing Elementor frontend error observed on cart/checkout/booking: `window.elementorCommon.helpers.softDeprecated is not a function`. Paid Pro/core version compatibility requires separate review; no unrequested dependency upgrade performed.
- Checkpoint 21 addresses reproduced signed-in classic $0 checkout cart retention: completed ticket restored from persistent cart without its coupon. Conditional SQL snapshot deletion, thirteen contract checks, seven actual Woo/WordPress fixture checks (including update immediately before DELETE), and live $0 checkout/empty-cart checks pass. Guest, Blocks, gateway checkout and identical-value multi-tab ABA behavior are not claimed verified or changed.
- All deployed PHP files linted; isolated stability, inventory, ticket eligibility, and showing-selection suites rerun against live source. These do not certify the remaining concurrency, gateway, accounting, or Social-provider findings.

### Second checkpoint

- Requested-showing privacy/deadline fixes and Inventory email-link confirmation deployed selectively with private file backups. No existing requester records found; no historical financial data edited.
- Request-page calendar lookup and backing deadline display work for November 7 at 14:00 (deadline October 24 at 14:00). No request or backing submitted.
- Existing four vendor orders remain Ordered with their original totals. No order decisions submitted against real vendor orders.
- User confirmed studio reports retain nominal ticket gross; financial totals use actual collections and refunds. Advertiser monthly attendance retains the current week-start month rule (no calendar-month splitting). Label this policy clearly. No historical numbers recalculated in these checkpoints.

### Third checkpoint

- Inventory integrity source deployed selectively. Additive submission identity upgrade verified; old orders keep NULL identity and unchanged payload/status/total. Private four-table SQL backup and changed-file rollback copies retained. See deployment checkpoint 3.
- Initial upgrade failed because existing compressed dbDelta SQL did not add the new column. Previous files restored immediately; complete original row hashes verified. Explicit additive migration tested twice against temporary tables; optional-module migration failure no longer throws through global checkout bootstrap. Retry succeeded (inventory schema 0.1.15).
- Real MySQL temporary fixtures test migration, duplicate vendor prevention, cross-connection claim contention, failed-write rollback, stale progress rejection, partial stock increase, and >100-order history. No live vendor row/email/provider changed by fixtures.
- Live browser Save All succeeded with all 858 editable values across 143 tracked rows unchanged. Pepsi review displays 22 tracked products including zero suggestions, disabled because order is Ordered. History search and itemized checklist work; no real Submit/Cancel/decision clicked.
- Seven isolated suites pass against deployed source. This does not close the remaining seat-capacity, accounting, subscription, publishing, and cross-module findings.

### Fourth checkpoint

- Ticket admission eligibility API shared with Will Call; no direct promotion of canceled/refunded/unpaid tickets. Explicit source-aware undo protects earlier QR/manual admissions. Will Call financial/refund display and label-sensitive caches corrected.
- 15 standalone PHP Will Call checks and JavaScript syntax/eight DOM/queue checks pass. Existing ticket/refund and showing-selection fixtures rerun. Midnight exposed a test using the real day; archived-show cutoff now uses WordPress current_datetime and fixture clock is explicit.
- Authorized live checkout order 30629 totals $0 with tototest and explicit test note; tickets 30630–30632. Manual first admission succeeds; stale Will Call page rejected before overwrite; reload shows one actual admission; numeric controls add remaining two without changing first ticket identity. Actual zero-dollar one-ticket refund produces two eligible tickets; deletion and registered lifecycle hook restores three. Order canceled afterward and all checked/source flags cleared. Cart explicitly cleared and browser confirms no test purchases in Will Call.
- Cart retention after order-received reproduces. Woo shortcode calls wc_empty_cart but subsequent cart view still had three test tickets; investigate caching/session overlap before changing global cart behavior. No unrelated cart/order modified.
- Attendance for already-admitted subsequently-refunded tickets needs user policy confirmation; ticket metadata preserves admission history. Full atomic admission/issuance is still open.
