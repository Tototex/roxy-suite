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
