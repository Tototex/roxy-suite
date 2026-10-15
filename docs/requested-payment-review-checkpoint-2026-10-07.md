# Requested Showing payment-review panel — 2026-10-07

The payment attempt protocol continues to refuse a second provider call after an
attempt marker exists. A manager-facing, read-only panel was added to the
classic WooCommerce order screen for Requested Showing orders, restricted to
users who can edit that order. It surfaces the internal Woo order ID, amount,
currency, request/backing IDs, attempt time, and a validated saved PaymentIntent
and status when present. It never displays payment tokens and has no retry,
mark-paid, or reconciliation mutation. The panel warns managers not to retry or
manually mark paid and to compare the Stripe record before taking action.
The approval-failure email now includes the Woo order admin link only after the
saved order's ID, Requested Showings origin, customer owner, request ID, and
backing ID have all been verified. A saved payment-attempt marker is trusted
only when its version, order/request/backing/customer identities, amount, and
currency match the specific Woo order; mismatched or incomplete receipts are
not displayed as verified.

The isolated fixture now contains 26 render/security assertions, including
order-ID mismatch, request/backing/customer/amount/currency mismatches, per-order
capability checks, and approval email-link identity checks. PHP 8.0–8.4 syntax,
the complete PHP 8.3 cross-module suite, the Requested Showings private
WordPress/Woo lifecycle, and Social browser/private-MySQL jobs passed in hosted
run [38821609686](https://github.com/Tototex/roxy-suite/actions/runs/38821609686).
The fixture-only follow-up corrected an order-lookup test setup after the first
run; the corrected full run passed. The same targeted changes were selectively
merged into the live file while preserving 31 live-only lines. Live PHP lint
passed before and after atomic replacement, and the public homepage returned
HTTP 200. No recent payment-review order was available for a live render; that
probe was read-only. No payment API, database record, or production order was
touched.

Verified pre-deploy copies are at
`I:\My Drive\Roxy Site Recovery\2026-10-15\requested-payment-review\class-roxy-rs-conversion.php`
and `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261015-requested-payment-review/class-roxy-rs-conversion.php`.
The first-stage installed file SHA-256 was
`cf28b04727ad16b35751534e71820a6698b8addb0abd1c0839c4877a0c7493e0`; the
follow-up below records the current installed file and its separate rollback
copy.

Second-pass audit (2026-10-15): independent review found that a paid order with
a missing/malformed attempt marker could still display a reassuring audit
headline even though its receipt was not verified. The renderer now always
shows the reconciliation warning unless the saved attempt identity fully
matches the order. The regression asserts missing and mismatched marker warning
behavior, exact rendered text, and absence of form/button controls. Hosted run
[38822811177](https://github.com/Tototex/roxy-suite/actions/runs/38822811177)
passes PHP 8.0–8.4 syntax, the full PHP 8.3 suite, Social browser/private-MySQL,
and Requested Showings private WordPress/Woo lifecycle jobs. The fix was
selectively applied to a freshly retrieved live file after confirming its
predeploy SHA-256 matched the prior install; 31 unrelated live-only lines were
preserved. Production PHP lint passes and homepage GET returns HTTP 200. The
second-stage rollback copy is at
`I:\My Drive\Roxy Site Recovery\2026-10-15\requested-payment-review-followup\class-roxy-rs-conversion.php`
and `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261015-requested-payment-review-followup/class-roxy-rs-conversion.php`
(SHA-256 `cf28b04727ad16b35751534e71820a6698b8addb0abd1c0839c4877a0c7493e0`).
Current installed SHA-256 is
`175430f45318ecedaaeffc755f23456a41157b5d2a64def692e7075450867eee`.

This improves manager visibility but does not implement a safe way to resolve
uncertain provider outcomes. Do not manually mark such an order paid or retry
conversion. A write-enabled reconciliation action requires a separate audited
design for verifying Stripe status and recovering Woo/backing state consistently.
