# G2 collection-event projection checkpoint

Date: 2026-10-08  
Branch: `stability/audit-2026-10`  
Scope: read-only event normalization and live payment-gateway discovery only.

## Evidence

- A read-only authenticated WordPress Plugins page showed WooCommerce Stripe Gateway active and no WooCommerce Square gateway among the 20 active plugins.
- WooCommerce Payments settings showed Stripe Optimized Checkout active.
- The repository contains no WooCommerce Square gateway implementation. Its only project-owned `set_transaction_id()` call is in Requested Showings and stores a Stripe PaymentIntent ID.
- Current evidence therefore supports separate streams for in-person Square orders and WooCommerce online collections; it does not support deduplicating the streams by guessed matching rules.

## Projection added

`SquareCollectionEvents::from_orders()` accepts completed USD Square Orders with a valid close timestamp, exact integer cents, and stable order/location IDs. It preserves tender payment IDs as identity evidence, falls back to Square's tender `id` when the v2 `payment_id` field is absent, marks missing tender references incomplete, and rejects disagreeing or duplicate order/payment identities and malformed money/timestamps.

`WooCollectionEvents::from_orders()` accepts an explicit online-gateway allow-list. It includes only positive USD orders marked paid, requires a paid timestamp and unique gateway-scoped transaction ID, and preserves the original amount and payment date. Manual/offline and unlisted gateways are ignored. Neither projection writes to the database, mutates a report, nor sends email.

## Verification

- Hosted workflow [37742815484](https://github.com/Tototex/roxy-suite/actions/runs/37742815484) passes all PHP 8.0–8.4 syntax jobs and the PHP 8.3 full isolated regression suite. Follow-up workflow [37743081233](https://github.com/Tototex/roxy-suite/actions/runs/37743081233) passes the same matrix and suite with tender-ID fallback/disagreement regressions.
- The Grosses refund-snapshot regression now covers paid/unpaid filtering, explicit gateway allow-listing, zero-dollar orders, cents/currency, paid-date conversion, missing evidence, and duplicate transactions, alongside Square collection safeguards.
- Local `git diff --check` passes. This workstation has no PHP executable, so local PHP execution was not available.
- No live site deployment or financial records were changed.

## Still open under G2

These projections are not yet called by a ledger or report. Still needed: durable provenance/idempotent collection and refund event storage, historical backfill review, exact versus proxy refund completion dating, payment-provider reconciliation, custom/exchange/manual adjustments, original-sale-day movie corrections across all report builders, and read-only end-to-end reconciliation against representative Woo/Square records. Do not treat this checkpoint as G2 resolution or as authorization to rewrite historical reports.
