# Subscriber ticket usage read-failure checkpoint

## Finding

The subscriber allowance calculator treated WooCommerce's paid-order query as an iterable without validating its result. Failed queries, missing orders, or malformed line-item collections could be skipped or treated like zero purchased subscriber tickets, granting fresh entitlement despite unknown usage.

## Change

- Invalid query results, failed individual order reads, malformed line-item collections, and incomplete line-item identity/quantity now cache a fail-closed sentinel.
- Subscriber allowance returns zero when paid usage is unknown; no cart or walk-up calculation can turn that unknown result into new entitlement.
- Quantities and walk-up counts must be finite, nonnegative whole numbers and cannot overflow the accumulated count.

## Verification

`tests/capacity-walkup-regression.php` adds fault cases for a failed paid-order query, a missing order, and a malformed line-item result. Hosted PHP syntax matrix and the complete PHP 8.3 isolated suite are pending on this commit. No live ticket cart/order was changed.

## Remaining

This protects this module's read path but does not certify guest/Blocks/gateway coverage or all shared capacity-cache behaviors. The larger T1/T6 verification remains in progress.
