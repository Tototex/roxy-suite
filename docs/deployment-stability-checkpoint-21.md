# Checkpoint 21 — completed zero-dollar cart retention

2026-10-05. Live Suite 1.0.20. Selective deployment of Suite loader/version and one new checkout helper. No gateway call, provider order, stock change, or public Social action.

## Diagnosis and correction

The installed WooCommerce classic no-payment handler calls `wc_empty_cart()`, which empties the request/session cart without destroying the signed-in user's persistent cart. A subsequent request reloads that saved cart. The coupon is no longer present, so the restored cart hash differs from the zero-dollar order and the normal thank-you cleanup protects it as a different cart. No custom Suite cart-clearing override was found.

The helper captures the saved cart immediately before the temporary clear, only if its item/custom-field identity matches the current cart (excluding recalculated tax/line amounts). The no-payment redirect may remove that snapshot only for a paid $0 order belonging to the same signed-in user, matching the captured order cart hash, with the current cart still empty. The redirect is unchanged; no blanket cart clear on old thank-you pages is installed. Guest, paid-gateway, and Blocks flows are untouched by this classic no-payment hook.

The final audit replaced an initial WordPress metadata deletion helper with an atomic SQL expected-value DELETE: WordPress's helper first selects metadata IDs, then deletes them, which is not a compare-and-delete transaction. The new predicate checks user, key, and complete serialized snapshot within the DELETE itself, invalidates the user metadata cache, and logs database failure without pretending the checkout/payment itself failed. A different newer saved cart is preserved. Identical-value ABA/multi-tab intent and gateway checkout/session races are not claimed solved.

## Verification

- Original Suite file matched Git checkpoint 20 before replacement. Its rollback copy remains under `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-cart/original`. Helper did not previously exist. PHP files linted; helper installed before the loader by atomic rename.
- Thirteen standalone contract assertions pass: matching completion, coupon recalculation, total/payment/customer/hash mismatch, guest, normal clearing, different pre-existing/concurrent cart, nonempty current cart, repeated completion, and malformed saved metadata.
- Seven actual Woo/WordPress private fixture assertions pass against final deployed source: persistent cart creation, core temporary clear, unchanged redirect, conditional deletion, a newer saved cart, an injected update immediately before the actual DELETE, and unrelated order/hash protection. The disposable user/order/session are removed; mail suppressed, no gateway/stock effects. The fixture explicitly evaluates the selected source under a separate class name so an already-loaded live helper cannot mask staged changes.
- Before-fix browser order **30712**, ticket **30713**, `tototest`, $0: completed order still had one persistent cart entry; browser restored its ticket with no coupon. Test canceled and cart item explicitly removed.
- First post-fix browser order **30716**, ticket **30718**, `tototest`, $0: QR loaded at 220 pixels, persistent count zero, cart remained empty without manual removal. Test canceled, no admission. Final audited SQL implementation receives a separate live browser check below.
- Final audited SQL browser order **30723**, ticket **30724**, `tototest`, $0: QR loads at 220 pixels, persistent cart empty, browser cart empty with zero items and no manual removal. Guarded cleanup cancels the order/ticket; no admission remains. Disposable fixture user count is zero.
- Nineteen ticket eligibility assertions rerun successfully. Manual staff lookup renders, and actual October 30 Will Call showing 30579 loads its data table without admission writes. All 1,223 pre-existing ticket records and metadata preserve their baseline digests after these checks.

## Recovery

Rollback archive SHA-256: `74772c3ebfb83841fd6ec68c0e824363e9a31c35b122c01d33065085481d9310`. Restore the previous Suite loader to disable the new helper; its unused file can remain. No schema change or data rollback required.

The server archive download timed out at SSH connection establishment; no rapid reconnect attempted and no offsite copy of that tar archive claimed. An alternative recovery ZIP was created directly from verified Git checkpoint `316bba0`: `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint21-rollback-from-git.zip`, SHA-256 `dbd6b2aceae1e27af623a1881426dd7c3695f5d807199ed4ea4e34c9de7d51b6`. Its Suite source matches the pre-deployment live baseline after normalizing Git's CRLF archive line endings to LF: `b72b6d0ed90fbdf1b0366f4140b23cdface738197146343a14862ce85555058d`. The first raw-byte comparison rejected that line-ending difference; the same-format source comparison passes. Server original and archive remain intact. Public homepage still loads without a fatal error after the SSH timeout.
