# Checkpoint 33 — attributable, checked booking refunds

2026-10-05 local date. Suite 1.0.32. Selective Event Booking refund helper, Woo integration, My Account cancellation and Suite version deployment. No schema/settings changes or historical financial rewrite.

## Correction and impact

- B4: payment-conflict refunds now request `refund_payment => true` and check the Woo result, including captured-payment confirmation. Success notes describe only a confirmed gateway refund. Errors/uncertainty produce a manager-review note and the existing internal conflict notification.
- B5: cancellation and conflict refund only attributable booking lines, using actual discounted line totals and taxes less their prior itemized refunds, never the original quote or entire mixed-cart balance. Linked adjustment order lines resolve through their booking metadata. Unrelated tickets/merchandise remain untouched.
- Legacy primary attribution permits one unlinked booking-payload line only when the booking repository links that exact order. Multiple possible legacy lines, missing ownership, inconsistent balances and amount-only prior refunds stop for manager review rather than guessing. Explicit conflict item identity comes from the payment callback's actual order item.
- A connection-owned, nonwaiting order refund lock serializes these managed paths. A verified persisted pre-gateway claim prevents blind retries after uncertain gateway/storage outcomes. Confirmed completed refunds cannot be repeated; cancelled orders with a captured payment date remain eligible for their booking balance. Unpaid invoices have no captured payment to return.
- Cancellation now reports missing linked orders and failed booking-status persistence, and does not clear reminders/queue cancellation after such failure. Cancellation callers are tested with fake repository/email/scheduling boundaries; whole booking concurrency remains B2/B3.
- Moderate financial/workflow impact: this intentionally refuses ambiguous refunds that previously could return unrelated money. Existing manual Woo refunds do not share the new lock. It is not a distributed transaction with Stripe, nor a repair of prior bookkeeping-only refunds. No new manager reconciliation UI or automatic claim clearing was added. Staff must inspect gateway/Woo evidence before any uncertain attempt is retried; do not delete claims just to clear an error.

## Verification

- Explicit PHP 8.3 lint passed for all three changed booking files and Suite; fresh WordPress loads Suite 1.0.32 and the helper.
- `tests/booking-refund-regression.php`: 20 grouped helper/cancellation checks against final deployed source. Covers mixed carts, discounts/taxes, partial refunds, primary/adjustment ownership, ambiguous legacy and missing identity, zero remaining balance, captured cancelled order, gateway error/exception/unconfirmed result, lock contention/replacement, claim save/readback failure before/after payment and retry behavior. Actual My Account cancellation function is loaded with fake repository/email/scheduling boundaries, including missing-order and failed-status-save cases.
- `tests/booking-refund-woo.php`: 18 checks against deployed code with actual installed Woo refund records and independent actual database lock connection. Exercises prior allocated refund, discounted/taxed remainder, preserved unrelated item, duplicate attempt, provider refusal and durable retry refusal, exact conflict identity, and actual deployed conflict handler success/failure notes. A request-local fake gateway only accepts this fixture's private order IDs; email delivery intercepted. Original booking-row digest unchanged; six private orders and their refunds removed through Woo APIs, with removal asserted. These are synthetic payment tests, not real Stripe transactions or a new customer checkout.
- Existing 23-check stability suite passes against deployed Suite (booking availability buffers, inventory forms/scheduling, updater and minimum PHP); no whole-suite certification implied.
- Live Chrome: booking calendar loads real Showing/Reserved blocks, Book now opens a populated modal with available and disabled Booked times. Modal cancelled without cart submission. Account/My Bookings loads the existing cancelled booking normally. No real booking/order cancellation, payment or external email sent.
- Existing Elementor Pro 3.1.0/core compatibility `softDeprecated` frontend error remains visible; no new dependency upgrade attempted. Browser page success is not a claim that all frontend errors are fixed.
- User policies recorded in the tracker: Square-refunded ticket sales reduce reportable counts and nominal studio gross; financial actual collections/refunds; advance purchase is not proof of attendance; existing week-start month grouping; current checkout price with a clear notice; online sales until show end. Reporting/checkout implementation remains separate open work.

## Recovery and integrity

Live originals matched the prior source before changes and again immediately before replacement. Server backup/staging outside the website: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-booking-refunds`.

Before deployment, `checkpoint33-rollback-files.tar.gz` copied to `I:\My Drive\Roxy Site Recovery\2026-10-05`; server/local SHA-256 both `dee491bc5359c706f1be04cac6274bb9811fd9419f5e97ee818abe93ccab3c5f`. Original archive entries: `woo-original.php`, `account-original.php`, `suite-original.php`. Cloud synchronization is not asserted.

Restore Woo/My Account/Suite from those entries to their corresponding paths if needed; the new `includes/modules/event-booking/includes/refunds.php` can be removed only after restoring Woo's old include-free version. No database rollback needed. Tests stayed outside the public website. One persistent SSH session used.

Final deployed SHA-256:

- refunds.php: `db8f65e650cfbbe18a8ccff59c6d2a9c8a8c411f1db831b70a68771e581a97d0`
- woo.php: `3c618332b1e3922d60146db55c1cd2b760c3d366eaba9273d90af1d26f1e435b`
- my-account.php: `9aae21e1fbaf3a22141814cd2208c7c04005fecc4afae9aea1218e03464153c3`
- Suite: `14a5a18287d2b111a62b955c54646ea39179631429cd16cdfba699f2f17dcb07`
