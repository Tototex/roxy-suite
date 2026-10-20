# Subscriber ticket usage read-failure checkpoint

## Finding

The subscriber allowance calculator treated WooCommerce's paid-order query as an iterable without validating its result. Failed queries, missing orders, or malformed line-item collections could be skipped or treated like zero purchased subscriber tickets, granting fresh entitlement despite unknown usage.

## Change

- Invalid query results, database errors, unavailable API, invalid showing identity, thrown query/order reads, malformed line-item collections, and incomplete line-item identity/quantity now cache a fail-closed sentinel.
- Subscriber allowance returns zero when paid usage is unknown; no cart or walk-up calculation can turn that unknown result into new entitlement.
- Quantities and walk-up counts must be finite, nonnegative whole numbers and cannot overflow the accumulated count.
- Subscription entitlement reads now also fail closed for false/exceptional query results, malformed item collections, missing accessors, and fractional/invalid quantities; thrown subscription API errors no longer become request fatals.

## Verification

`tests/capacity-walkup-regression.php` covers invalid showing identity, simulated database errors, failed/thrown paid-order and subscription queries, missing/thrown order reads, malformed line-item collections, and fractional subscription quantities. `tests/capacity-missing-order-api-regression.php` verifies missing `wc_get_orders` support with an active fixture subscription. The hosted PHP 8.0–8.4 syntax matrix and full PHP 8.3 isolated suite pass in [run 37750719505](https://github.com/Tototex/roxy-suite/actions/runs/37750719505). No live ticket cart/order was changed.

## Remaining

This protects this module's read path but does not certify guest/Blocks/gateway coverage or all shared capacity-cache behaviors. The larger T1/T6 verification remains in progress.

## Policy regression clarification — 2026-10-20

The existing entitlement rule intentionally grants at least one subscriber seat for each active or pending-cancel subscription, even when Woo returns an empty item list or a zero-quantity item; additional valid item quantities increase the allowance. This preserves the established membership policy rather than treating a missing line item as proof that the membership itself is invalid. Regression assertions cover the minimum-one behavior as well as malformed/fractional data. Hosted run [39509868924](https://github.com/Tototex/roxy-suite/actions/runs/39509868924) passed all 11 jobs, including the PHP 8.0–8.4 matrix, isolated suite, and private WordPress/Woo/MySQL/HPOS lifecycle jobs. No production ticket or subscription data was changed.
