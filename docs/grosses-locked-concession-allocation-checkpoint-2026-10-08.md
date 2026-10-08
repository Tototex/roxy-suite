# Grosses locked concession allocation checkpoint — 2026-10-08

## Change

When rebuilding a day’s concession allocation, a manually locked row is now treated as a fixed amount, not as an editable allocation target that later gets skipped. The allocator sums valid locked cents, proportionally assigns the remaining matching Square cents among editable matching rows, and rounds by largest remainder so the result remains exact to the cent. If the locked amount exceeds the matching Square line total, it stops before any write and asks for review. A fully locked set whose fixed total matches the source is left untouched and reconciles successfully.

This avoids silently losing part of the Square allocation when locked rows competed with writable rows. It does not rewrite any existing saved report; it only changes future explicit/scheduled rebalances.

## Verification

- Regression cases verify a partially locked 1,001-cent day remains 1,001 cents, preserves the locked row and allocates the exact remainder to editable rows.
- A protected total greater than the Square source fails before Store writes.
- Fully locked rows matching the source remain unchanged and return reconciled totals.
- GitHub Actions [37767400575](https://github.com/Tototex/roxy-suite/actions/runs/37767400575) passes PHP 8.0–8.4 syntax and the full PHP 8.3 isolated cross-module suite.

## Remaining

Sales without any eligible matching report row are still not represented in a dedicated reconciliation queue, and a write failure partway through a multi-row save is not yet an all-or-nothing transaction. No live reports were refreshed or modified for this test.
