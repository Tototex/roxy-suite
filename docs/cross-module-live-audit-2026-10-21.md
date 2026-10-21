# Cross-module live audit — 2026-10-21

This checkpoint records read-only production checks and the previously authorized correction of six itemized ticket-refund rows. No orders, payments, social posts, or emails were created by this audit.

## Cross-module health and public routes

- The live `RoxySuite\Health::run_full()` check returned `pass` for Core/Environment, Show Tickets, Requested Showings, Will Call, Member Check, Event Booking, Arcade, and Inventory.
- Grosses is `warn` because the 2026-10-19 scheduled report never completed; its closed-day refresh exhausted its retries. The 2026-10-20 scheduled no-sales report and closed-day refresh completed successfully. The advertiser scheduler was skipped because it was not due.
- Social Publisher is `warn` for one failed partial post; see below.
- Anonymous public GET requests returned HTTP 200 for `/`, `/tickets/`, `/showings/`, `/rent-the-roxy/`, `/private-events/`, and `/member-check/`. The anonymous Member Check response contained a login prompt and no member status/history or private-photo endpoint.
- All checks were read-only. Event Booking’s configured health check uses only the Sling health/auth GET path.

## Grosses and email outbox

- The live email outbox contains four accepted sends, zero current `sending` attempts, zero `sending` attempts older than 15 minutes, and zero `uncertain` outcomes. No outbox row was changed and no email was sent.
- The 2026-10-19 scheduled run failed with `This request could not be authorized.` The bounded closed-day queue is failed at its maximum retry count and the stored reason is that the initial report did not complete. A separate exact-date Square Orders read returned zero orders, but no historical recovery was invoked in this audit.
- On 2026-10-21, six verified stale, unlocked ticket-refund rows were corrected using the existing protected updater after the current Updraft database archive passed a complete gzip integrity read. The follow-up Square-backed comparison found all seven recalculable affected showings match; no remaining mismatch, missing/ambiguous row, locked row, or other rebuild error was found. The 2026-03-13 record remains unchanged because its original category-price evidence is insufficient; the calculation fails closed rather than guessing.
- Approval was requested before any manual recovery of the failed 2026-10-19 report. Existing emailed reports are not resent automatically.

## Member Check photo privacy

- The installed `roxy-sub-check.php` SHA-256 matches the local source.
- The privacy migration marker is complete; 3 of 3 subscription-photo references are marked private, 15 exact file/derivative paths are in the deny list, and the upload `.htaccess` privacy rule is present.
- An anonymous HEAD request to a direct image URL returned HTTP 403; an anonymous request to the staff-only photo endpoint returned HTTP 404.
- Existing member/photo records were preserved. No photo files were moved or deleted.

## Social Publisher partial outcome

- One failed video post is recorded for 2026-10-19. Facebook has a confirmed post ID; Instagram has no confirmed media ID and a container ID is retained. Facebook Story was not selected.
- A read-only Graph API status check returned HTTP 200 and `EXPIRED` for the Instagram container. This is a terminal, non-published container state; the UI’s manager-confirmed retry path would create a fresh Instagram container while skipping the already-confirmed Facebook post.
- No retry, publication, removal, or database update was performed. Approval was requested before publishing the missing Instagram destination.

## Tests and synchronization

- Hosted workflow run [39589645011](https://github.com/Tototex/roxy-suite/actions/runs/39589645011) passed all 15 jobs for code commit `4564d6e4ca8ea844ae0a4e58a20f9afa8a27bc49`, including the PHP compatibility matrix, isolated cross-module suite, and disposable MySQL/WordPress lifecycle jobs. The Member Check photo regression is in that suite. The subsequent `e522b54` commit changes audit documentation only.
- The audit branch and local worktree were synchronized at `e522b54`. Existing untracked `.codex-*` recovery folders were left untouched.
- Follow-up verification on 2026-10-21: GitHub's latest-attempt job listing confirms all 15 jobs in workflow `39589645011` completed successfully. A fresh local syntax pass found all 20 tracked JS/CJS/MJS files pass `node --check`; fresh anonymous GETs to `/`, `/tickets/`, `/showings/`, `/rent-the-roxy/`, `/private-events/`, and `/member-check/` each returned HTTP 200. No source or production data changed during these checks.

## Continuation verification (2026-10-21 07:22 PDT)

- Local branch `stability/audit-2026-10` is synchronized with `origin` at `cf4fdcbd99d2bf992afe96312cffd218a66d3fc7`. The latest runtime code commit is `ce53d840ea523b2a94150fbee42ea4b8937859e3`; the intervening commits are audit documentation only. Its hosted PHP compatibility workflow [39607498515](https://github.com/Tototex/roxy-suite/actions/runs/39607498515) completed successfully.
- Fresh local checks: all 20 tracked JS/CJS/MJS files pass `node --check`; seven directly runnable JavaScript regressions pass (Will Call, Social picker, release guard, Member Door, Inventory cost UI, deployment manifest, and booking assets). The Social bulk-editor and Social AI browser tests could not run here because the Windows shell has no PHP CLI. The Grosses workbook download verifier also requires explicit downloaded report-file arguments and was not run without those inputs. These are local execution prerequisites, not code-test failures; hosted PHP/browser coverage remains green.
- Fresh anonymous GET smoke checks returned HTTP 200 for `/`, `/tickets/`, `/showings/`, `/rent-the-roxy/`, `/private-events/`, `/requested-showings/`, `/movie-requests/`, `/roxy-arcade/`, and `/member-check/`. No cart, checkout, order, or payment was created.
- A fresh sequential read-only production `RoxySuite\Health::run_full()` check reports Core/Environment, Show Tickets, Requested Showings, Will Call, Member Check, Event Booking, Arcade, and Inventory as passing. Grosses remains warning for the deferred 2026-10-19 closed-day refresh; Social Publisher remains warning for one failed row. The 2026-10-20 Grosses no-sales run and the 2026-10-20 23:00 inventory pull remain successful. No report, provider post, order, email, payment, or historical business record was modified by this continuation check.
- Explicit authorization is still pending for either the no-email 2026-10-19 Grosses recovery (which writes historical dashboard/refund reconciliation data) or a Requested Showings policy change that would keep partially reconciled approvals unpublished. Neither change was made.

## Current tracker reconciliation (2026-10-21)

- The stability tracker remains intentionally open where work needs live provider/bank evidence, owner policy, a physical device, or an isolated restore environment; a green aggregate workflow does not close those boundaries. In particular, C2 still awaits an ordinary versioned release on `master` to verify emitted provenance, C4 remains gated by third-party HPOS compatibility and restore testing, and the Oct. 19 Grosses recovery remains unrun pending approval.
- C1's older Oct. 15 “zero failed Social rows” observation is superseded: the later read-only Oct. 21 Health check found Social Publisher warning for one failed row with an expired Instagram container. No retry or publication was performed. C1 remains in progress because Health diagnostics do not prove every scheduled workflow's durable completion/delivery.
- C2's status remains open for a real release, but its package-parity evidence is newer than the original table entry: the Oct. 21 parity check found 71 exact runtime files, 28 line-ending-only differences, zero missing files, and zero substantive mismatches.
- B2 is resolved for Roxy-managed reservation writes, as covered by the two-connection acceptance/lock regression in workflow [39601858593](https://github.com/Tototex/roxy-suite/actions/runs/39601858593). External writers that bypass Suite APIs and live gateway behavior remain outside that result.
- A read-only review of current Requested Showings code corrected two stale suspected-defect descriptions: approval returns an error if the shared Event Booking show-time lock helper is unavailable, and funding-goal notification records its sent marker only when `wp_mail()` confirms acceptance. `tests/requested-funding-read-regression.php` verifies failure leaves the marker empty, later retry succeeds, and confirmed mail is not duplicated; the PHP compatibility workflow includes that fixture. The remaining unpublished-until-reconciled policy is separate and still awaits the owner's choice above.
- The ticket dashboard's misleading revenue-only display is corrected in `1ff45e6` and selectively deployed: Gross Revenue, Refunds, and Net Revenue are separate cards, and the per-ticket table says Gross Revenue. The previous live file (SHA-256 `09711b9016a48af7d32b8582ccba18b04db6fbc39f7a4c1efdd807b300850ec4`) is preserved and hash-verified at `I:\My Drive\Roxy Site Recovery\2026-10-21\ticket-dashboard-net-revenue-1ff45e6\class-roxy-st-cpt.php.live-original` and `/home1/anrvxfmy/deploy-backups/roxy-suite-20261021-ticket-dashboard-net-revenue-1ff45e6/class-roxy-st-cpt.php.live-original`. The installed file SHA-256 (`408459625df0df7e8771637cc14cf1467f0a682d6377025c57a51266411857f0`) matches the tested local candidate and passes production PHP lint. Read-only WP-CLI rendering of showing 31758 confirms all three revenue labels; public HEAD requests for home, tickets, showings, and rentals returned HTTP 200. No order, payment, refund, ticket, or historical record was changed.
- Workflow [39618899636](https://github.com/Tototex/roxy-suite/actions/runs/39618899636) completed successfully for `1ff45e6`, all 15 jobs passing, including PHP 8.3/8.5 isolated regression suites, PHP 8.0–8.5 syntax, Social browser checks, HPOS, private WordPress/Woo lifecycle, and private-MySQL fixtures.
- A fresh read-only production `RoxySuite\Health::run_full()` check after deployment reports Core/Environment, Show Tickets, Requested Showings, Will Call, Member Check, Event Booking, Arcade, and Inventory passing; Grosses remains warning for the deferred 2026-10-19 closed-day refresh, and Social Publisher remains warning for one failed row. This check performed no write, report send, post, order, payment, or historical reconciliation.
- Member Check photo privacy remains deployed and unchanged by this pass. The owner confirmed the intended staff-only access; the existing migration preserves photo references, while the access-controlled endpoint and upload deny rules prevent public reads. The existing hosted lifecycle regression includes anonymous/staff authorization, direct-file protection, derivative coverage, and post/HPOS migration.
- Local direct checks on the current synced worktree pass all seven standalone JS regressions (release guard, manifest, booking assets, social picker, member door, inventory cost UI, and Will Call JS). They complement, rather than replace, the hosted PHP and browser suites.
