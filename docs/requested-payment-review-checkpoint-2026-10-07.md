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
saved order's request/backing identifiers have been verified.

The current isolated fixture contains 10 render/security assertions and passed
on PHP 8.3 against a private candidate copy. It checks order-screen registration,
capability gating, escaping, matched attempt receipts, cross-attempt rejection,
and absence of unsafe controls. Existing payment orchestration tests passed 17
checks and agreement/conversion tests passed 22/7 checks. No payment API or
production order was touched. This change has not been deployed.

This improves manager visibility but does not implement a safe way to resolve
uncertain provider outcomes. Do not manually mark such an order paid or retry
conversion. A write-enabled reconciliation action requires a separate audited
design for verifying Stripe status and recovering Woo/backing state consistently.
