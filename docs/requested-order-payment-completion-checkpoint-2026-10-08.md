# Requested Showings Woo payment-completion checkpoint — 2026-10-08

## Change

After a Stripe PaymentIntent is confirmed as captured, the conversion path now verifies that the Woo order save succeeded, `payment_complete()` returned success, and a fresh `wc_get_order()` read shows the same order as paid with the matching transaction ID, amount, currency, and persisted paid timestamp. If any check fails, conversion returns a reconciliation-required error; it does not mark the backing charged or attempt another provider charge. The zero-charge path uses the same persisted-order verification before recording completion.

This does not create a Stripe charge or order in this change. It closes a code-confirmed gap where the previous implementation ignored Woo order persistence and payment-completion results after a provider success. Manager/provider reconciliation, actual installed lifecycle validation, and publication/occupancy coordination remain open.

## Verification

- Eight provider-free Woo-shaped checks cover successful persisted completion, pre-completion save failure, false completion result, stale/unreadable readback, missing paid timestamp, transaction/amount/currency mismatches.
- The existing requested-showing conversion checks continue to verify ticket and sponsorship totals remain nontaxable even with WooCommerce's store-tax setting enabled.
- Hosted PHP 8.0–8.4 syntax and the complete PHP 8.3 isolated suite passed in GitHub Actions run [37771999081](https://github.com/Tototex/roxy-suite/actions/runs/37771999081); the focused eight-check regression also passes locally.
- No order, charge, email, or backing was created or changed.
