# Checkpoint 43 — Woo ticket-sales eligibility and refunds

2026-10-06. Suite 1.0.42. Selective Show Tickets Sales and Suite-version deployment.

Sales now excludes unpaid on-hold orders, retains processing/completed zero-price orders and discovers registered Woo-paid custom statuses through the paid-status filter. Canceled and terminal refunded orders remain excluded. Per-item refunds reduce sold/type/presale/day-of/subscriber quantities with a zero clamp. Refund deletion uses Woo's `(refund_id, parent_order_id)` contract to invalidate/rebuild affected caches. Cache version 3 rejects older aggregates.

The legacy `gross_revenue` and ticket-type `revenue` remain original line values for eligible orders. Additive itemized refund/net fields describe that same eligible-order projection, not bank capture, payment-day revenue or refund-day cash. An item-fully-refunded processing order and a terminal refunded order intentionally have different eligible-order membership. These values must not substitute for the separate financial ledger. Grosses continues its existing showing-associated original-value use; dated cash accounting remains open.

## Verification

- PHP 8.3 lint; 16 isolated actual-Sales-class checks pass: free paid-status orders, unpaid holds, custom paid-status query discovery, terminal exclusions, partial/full/mixed-line refunds, zero clamp, cache invalidation and deletion callback.
- Seven installed Woo checks use disposable actual $0 processing/on-hold orders and a zero-money itemized refund of a generic hidden draft product, never a real showing product. Candidate cache/tag writes remain in memory; fixture mail is intercepted before transport, with no gateway/refund payment or real ticket issuance.
- Installed Woo AJAX deletion source is checked for its two-argument action contract, then the exact action is simulated after deleting only the owned fixture refund. This is not a browser/AJAX-handler test.
- Both isolated and installed Woo checks pass again against deployed Sales. Suite bootstrap reports 1.0.42; original live files matched normalized Git baselines and raw rollback copies before replacement, and deployed files match local candidate hashes. Fixture cleanup removes only owned products/orders/refunds; follow-up queries find zero remaining named fixture products/orders.

## Recovery and remaining work

Server backup `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-sales-refunds/` contains both original PHP files and SHA manifest. Matching archive on `I:\My Drive\Roxy Site Recovery\2026-10-06\checkpoint43-rollback.tar.gz`: 7,351 bytes, SHA-256 `10a74b7d83a4adaf1aadd28ac61216a61b5ff1810733a8b3d0da7252b6d082cb`. Contents, size and checksum verified before deployment; Google Drive cloud synchronization not asserted. Restore only these originals after checking for later legitimate changes; old cache-version entries rebuild automatically rather than becoming authoritative.

Paid-provider/financial-date reconciliation, malformed/fractional extension-supplied data hardening, HPOS/legacy discovery, cache-write concurrency, historical reconciliation and complete live-door/refund cash accounting remain open. Seat reservation/admission authority is unchanged and must not be derived from this reporting cache. Authenticated admin browser checks still await sign-in; no claim of complete Suite audit or checkout certification is made.
