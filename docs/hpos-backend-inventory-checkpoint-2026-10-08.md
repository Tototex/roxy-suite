# HPOS backend inventory checkpoint — 2026-10-08

## Scope and result

This is a source-level follow-up to C4, not an HPOS migration or compatibility claim. The current checkout explicitly declares `custom_order_tables` unsupported for the actual plugin file at `before_woocommerce_init`. Core Health Check reports post-based storage as the supported configuration, an active HPOS configuration as a failure, and unavailable/throwing detection as a warning. The existing isolated storage regression verifies those three outcomes and the exact compatibility registration. The plugin bootstrap includes and initializes this guard.

The repository search for WooCommerce order tables and post-backed order metadata found these remaining storage-dependent groups:

- Ticket seat reservations and managed holds read WooCommerce line-item tables and post order status/metadata directly.
- The ticket issuance transaction owns writes across posts, post metadata, and WooCommerce order-item metadata; this is part of its custom atomic issuance model.
- Ticket sales persists supplemental showing tags in order post metadata, while order retrieval itself uses WooCommerce order-query APIs.
- Requested Showings payment-attempt and conversion claims validate/store markers through post metadata and verify the associated `shop_order` post record.
- Other standard order searches use `wc_get_orders()`/`wc_get_order()` or WooCommerce objects; these alone do not establish support for the ticket-specific direct-table paths above.

The direct table and metadata paths are not made HPOS-compatible by this checkpoint. The deliberate compatibility declaration and Health Check are the current safety boundary. Do not enable HPOS, force its feature flag, or migrate order data based on this review. Converting these transactional paths would require a separate design that preserves locking, idempotency, refunds, and cross-module order-item identity, followed by actual tests on both storage backends.

## Verification

- `tests/core-storage-regression.php` verifies the registered hook, `declare_compatibility('custom_order_tables', <actual plugin file>, false)`, the legacy-storage pass, the active-HPOS failure, and unknown-state warning.
- Hosted PHP compatibility/full-suite run [37819068920](https://github.com/Tototex/roxy-suite/actions/runs/37819068920) passed, including that storage regression.
- Static search covered runtime PHP under `includes/` for `woocommerce_order_items`, `woocommerce_order_itemmeta`, `shop_order`, and direct order-meta access.
- No database mode, order, or historical record was changed during this review.

## Remaining work

C4 remains open for full HPOS compatibility and dual-backend certification. The currently supported state is intentionally post-based storage, with HPOS explicitly rejected and diagnosed rather than silently assumed to work.
