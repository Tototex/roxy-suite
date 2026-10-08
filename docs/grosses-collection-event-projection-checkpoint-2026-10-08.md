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

`SquareCollectionEvents::from_orders()` now projects one event per completed Square tender using `tender.amount_money`, the tender's stable ID (which Square documents as the associated payment ID), and `tender.created_at`. Split tenders therefore remain distinct, and the projection does not mistake an order's `total_money` (the amount to collect) for collected funds. A tender lacking valid identity, amount, currency, or timestamp fails closed; disagreement between tender and payment IDs and duplicate payment identities also fail closed. A zero-dollar completed order without tenders produces no collection. Positive completed orders with no tender evidence fail closed. Positive-tender return/exchange orders require manual cashflow reconciliation rather than risking double-counting. Refunds continue through the separate refund projection.

`WooCollectionEvents::from_orders()` accepts an explicit online-gateway allow-list. It includes only positive USD orders marked paid, requires a paid timestamp and unique gateway-scoped transaction ID, and preserves the original amount and payment date. Manual/offline and unlisted gateways are ignored. Neither projection writes to the database, mutates a report, nor sends email.

## Verification

- Hosted workflow [37742815484](https://github.com/Tototex/roxy-suite/actions/runs/37742815484) passes all PHP 8.0–8.4 syntax jobs and the PHP 8.3 full isolated regression suite. Follow-up workflow [37743081233](https://github.com/Tototex/roxy-suite/actions/runs/37743081233) passes the same matrix and suite with tender-ID fallback/disagreement regressions.
- The Grosses refund-snapshot regression covers paid/unpaid filtering, explicit gateway allow-listing, zero-dollar orders, cents/currency, paid-date conversion, missing evidence, duplicate transactions, multi-tender Square orders, tender-date/timezone provenance, tender identity and amount validation, and return/exchange fail-closed behavior.
- Local `git diff --check` passes. This workstation has no PHP executable, so local PHP execution was not available.
- No live site deployment or financial records were changed.

## Still open under G2

These projections are not yet called by a ledger or report. Still needed: durable provenance/idempotent collection and refund event storage, historical backfill review, exact versus proxy refund completion dating, payment-provider reconciliation, custom/exchange/manual adjustments, original-sale-day movie corrections across all report builders, and read-only end-to-end reconciliation against representative Woo/Square records. Do not treat this checkpoint as G2 resolution or as authorization to rewrite historical reports.

## Daily cashflow aggregation follow-up

Commit `64086df` adds `CashflowProjection::daily_totals()` as a pure, non-persisting aggregation seam. It keeps collection dates separate from refund dates, reports source-specific and combined USD cents, and rejects malformed lists, duplicate typed identities, invalid calendar dates/currencies/amounts, and integer overflow. The test fixture confirms that Square collections and Woo collections are not conflated, while each refund reduces financial net on its own refund date. It does not alter studio nominal ticket reports or rewrite original sale dates.

Hosted workflow [37744106699](https://github.com/Tototex/roxy-suite/actions/runs/37744106699) passed PHP 8.0–8.4 syntax jobs and the PHP 8.3 isolated cross-module regression suite, including the expanded refund snapshot regression. Local PHP is unavailable; `git diff --check` passed before commit. This remains an unintegrated projection: no database writes, dashboard changes, historical backfill, or live deployment.

The follow-up typed-identity regression (numeric text versus integer IDs) passed in hosted workflow [37744537626](https://github.com/Tototex/roxy-suite/actions/runs/37744537626), again with all five syntax jobs and the PHP 8.3 isolated suite successful.

2026-10-08 audit correction: Square collection projection now uses actual tender amounts and tender-created timestamps rather than order `total_money`/`closed_at`. Square's official Tender reference identifies `id` as the associated payment ID, `amount_money` as the total tender amount, and `created_at` as tender creation time. This is still a non-persisting projection only; it does not change live reports or historical records. Focused multi-tender, identity, timestamp, zero-dollar and return/exchange cases pass in hosted workflow [37801645308](https://github.com/Tototex/roxy-suite/actions/runs/37801645308), including PHP 8.0–8.4 syntax and the full PHP 8.3 isolated suite. Local PHP execution and live provider behavior remain unverified.

The next audit pass makes timestamp provenance explicit on each projected refund: Square uses `square_updated_at_proxy`; WooCommerce uses `woocommerce_refund_creation_proxy`. Neither field is an authoritative processor-completion timestamp. The cashflow projection remains unused by reports/dashboards until actual refund-date evidence and durable event provenance are resolved.

Hosted workflow [37760074611](https://github.com/Tototex/roxy-suite/actions/runs/37760074611) passes the full PHP 8.0–8.4 syntax matrix and PHP 8.3 isolated cross-module suite for these provenance labels and regressions.

A guarded live refund-feed probe is prepared in `tests/grosses-square-refund-feed-live-readonly.php`; it permits only HTTPS GETs to Square's `/v2/refunds` endpoint and blocks email. It was not run: the host closed the authenticated SSH connection before the test could be staged. No refund records were fetched and no production data or code changed.
