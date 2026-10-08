# Ticket subscriber entitlement overflow — live checkpoint — 2026-10-08

## Finding and fix

Subscription quantities at or above the PHP integer headroom boundary were accepted as valid entitlement. The candidate rejects equality at `PHP_INT_MAX - accumulated_quantity`, failing closed instead of granting a near-unbounded seat count. This is a one-line change in `class-roxy-st-capacity.php`; the sole source difference from production before deployment was the comparison `>` → `>=`.

## Verification and deployment

- Hosted workflow [37841576124](https://github.com/Tototex/roxy-suite/actions/runs/37841576124) passed for the exact source commit `00ff3f6909821bece70a6fc187e4e79ebabed187`, including the PHP 8.0–8.4 matrix, full isolated PHP suite, and private WordPress/MySQL fixtures. `tests/ticket-publication-regression.php` includes `PHP_INT_MAX` subscription-quantity cases.
- Before rollout, the live file SHA-256 `114aabdee854a63219718c1aa006b19ed081bd0e54db2a441be4cb40cefebde8` was copied to the I: selective-backup folder and private server recovery directory; both copies verified.
- A hash-guarded atomic replacement deployed the candidate. Production `php -l` passed, and the post-deployment file SHA-256 is `3697e0910d86e60edcf0919c5ae0ddd265c5ed272770cb0933cd1f381dcdb283`.
- No live cart, order, subscription, ticket, or admission data was modified. A follow-up attempt to stage another isolated host fixture was refused by SSH after deployment; the exact code is covered by the successful hosted suite, and the live PHP lint/hash were verified.

This closes only the overflow-boundary correction. Broader T1/T6 cross-path capacity races, provider/gateway coverage, and the other tracked ticket issues remain open.
