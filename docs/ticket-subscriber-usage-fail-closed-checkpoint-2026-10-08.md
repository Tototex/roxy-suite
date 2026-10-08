# Subscriber ticket usage read-failure checkpoint

## Finding

The subscriber allowance calculator treated WooCommerce's paid-order query as an iterable without validating its result. Failed queries, missing orders, or malformed line-item collections could be skipped or treated like zero purchased subscriber tickets, granting fresh entitlement despite unknown usage.

## Change

- Invalid query results, database errors, unavailable API, invalid showing identity, thrown query/order reads, malformed line-item collections, and incomplete line-item identity/quantity now cache a fail-closed sentinel.
- Subscriber allowance returns zero when paid usage is unknown; no cart or walk-up calculation can turn that unknown result into new entitlement.
- Quantities and walk-up counts must be finite, nonnegative whole numbers and cannot overflow the accumulated count.

## Verification

`tests/capacity-walkup-regression.php` adds fault cases for invalid showing identity, a simulated database error, a failed and throwing paid-order query, a missing and throwing order read, and a malformed line-item result. `tests/capacity-missing-order-api-regression.php` verifies missing `wc_get_orders` support with an active fixture subscription. Hosted run [37750257124](https://github.com/Tototex/roxy-suite/actions/runs/37750257124) passed before the new database-error fixture; the expanded test and PHP 8.0–8.4/full PHP 8.3 suite will run on the next hosted push. No live ticket cart/order was changed.

## Remaining

This protects this module's read path but does not certify guest/Blocks/gateway coverage or all shared capacity-cache behaviors. The larger T1/T6 verification remains in progress.
