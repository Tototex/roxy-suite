# Ticket add-to-cart validation-chain checkpoint

## Finding

The ticket capacity callback is attached to WooCommerce's `woocommerce_add_to_cart_validation` filter. On a ticket product that passed the module's own capacity checks, the callback returned `true` even when the `$passed` value from WooCommerce or an earlier plugin callback was already `false`. That could undo an upstream rejection.

## Change

Return `false` immediately for an already-rejected request. Existing capacity and entitlement rules run only when prior validation passed.

## Verification

`tests/capacity-walkup-regression.php` adds a direct assertion that a false incoming filter value remains false while retaining the existing valid-last-seat and over-capacity cases. Hosted run [37749363129](https://github.com/Tototex/roxy-suite/actions/runs/37749363129) passed the PHP 8.0–8.4 syntax matrix and PHP 8.3 cross-module suite. No live cart or order was changed.

## Risk

Low and conservative: only requests already rejected by another callback change behavior (they remain rejected). Valid ticket requests use the existing logic.
