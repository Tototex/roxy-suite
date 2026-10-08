# Inventory receipt reconciliation checkpoint — 2026-10-08

## Change

- Open vendor orders now query Square inventory change history for their catalog variations and locations.
- An order is released only by a later `ADJUSTMENT` whose reason is `RECEIVED`, with a positive quantity and a transition from `NONE` or `UNLINKED_RETURN` into `IN_STOCK`. Sales, recounts, manual net increases, and receipts dated before the order do not release it.
- Any qualifying partial receipt releases the order, preserving the requested partial-arrival behavior. Receipt event ID, quantity, reason, and Square timestamps are retained in the order snapshot and displayed in Order History.
- The status update remains conditional on the order still being `ordered`, so a concurrent cancellation is not overwritten. The Square pull remains all-or-nothing if change-history retrieval fails.
- The Inventory screen now tells employees to use Square's Receive Stock action; manually recounting stock does not produce the receipt signal this guard requires.

## Verification

- Hosted workflow [37747535931](https://github.com/Tototex/roxy-suite/actions/runs/37747535931) passed the full PHP 8.3 isolated suite and PHP 8.1–8.4 syntax jobs. PHP 8.0 was queued at the time this note was written; recheck the run before treating the syntax matrix as complete.
- Focused tests cover post-order partial receipt, sale rejection, pre-order receipt rejection, later-order isolation, malformed/failed Square history responses, no partial inventory commit, and cancellation during reset.
- Hosted run [37755291616](https://github.com/Tototex/roxy-suite/actions/runs/37755291616) passes PHP 8.0–8.4 syntax and the PHP 8.3 full isolated suite, including the API-version header assertion.
- Current official Square reference for API version `2026-09-16` documents `updated_after` as a standard request field (without a Beta marker), matching the plugin's pinned `Square-Version` header. `updated_after` filters by the inventory change's calculated time; the plugin independently checks both event creation and occurrence times against the order cutoff. The focused fake-HTTP regression now asserts the pinned version and cutoff request shape. See [BatchRetrieveInventoryChanges](https://developer.squareup.com/reference/square/inventory/BatchRetrieveInventoryChanges). The [Inventory API process flow](https://developer.squareup.com/docs/inventory-api/how-it-works) documents `NONE` → `IN_STOCK` as receiving stock; [adjustment reasons](https://developer.squareup.com/docs/inventory-api/adjustment-reasons) identifies `RECEIVED` as an increase reason.

## Not verified / deployment gate

- This is an audit-branch change only. No production code or order data was changed, and no vendor message was sent.
- The site's authenticated Square connection has not yet been used to make a read-only `BatchRetrieveInventoryChanges` request with the `updated_after` filter. The current official API reference no longer marks this filter Beta, but a live connected-account request is still required before treating the deployed integration path as verified.
- Confirm employee Receive Stock workflow in Square during the next real delivery. A physical count/recount is intentionally not treated as proof of receipt.
