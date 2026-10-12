# Inventory cost review UI audit — 2026-10-12

## Finding and scoped fix

The server accepts a zero-cost line only when it is explicitly marked Free and has a source and checked date. The vendor-review page initially rendered that line as Free/$0, but its quantity-change JavaScript inferred “known” from `cost > 0`. Editing any order quantity therefore changed a valid Free line to Unknown/Incomplete and disabled submission. This contradicted the server's cost policy and created a false UI block.

The order-review input now carries a `data-cost-known` flag derived from the same server-side `cost_is_known()` policy used by estimates and submission. The browser recalculation consumes that flag, preserving valid zero-cost Free lines while continuing to block unknown-cost positive quantities. No purchase prices, provenance fields, pack sizes, reorder points, targets, or overrides were changed.

## Verification

- `node tests/inventory-cost-review-ui-regression.cjs` passed. It extracts and executes the actual inline vendor-review JavaScript with representative form controls, checking explicit Free at $0, unknown cost blocking, positive-price recalculation, and vendor minimum handling.
- `git diff --check` passed; Git emitted only normal CRLF conversion notices for tracked files.
- The existing PHP `inventory-integrity-regression.php` now also checks the server-generated UI known-cost flag for Free versus Unknown. It was not run locally because PHP is unavailable in this environment.
- The Node regression was added to `.github/workflows/php-compatibility.yml`; no workflow was triggered from this isolated worktree.

## Residual gaps

- This change is local/branch-only; no live settings or data were changed, no mail was sent, and no deployment was performed.
- Live schema/UI and accuracy of manually entered source/date values remain unverified. `estimate` remains an operator-configured amount and does not imply independently verified supplier pricing. No Square retail or inferred price is substituted for purchase cost.
- Run the PHP compatibility suite and the existing private WordPress/MySQL migration fixtures in CI before deployment; retain the existing verified-backup gate for any production changes.
