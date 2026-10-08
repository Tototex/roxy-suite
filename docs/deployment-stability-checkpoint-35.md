# Checkpoint 35 — current ticket prices with checkout review

2026-10-05. Suite 1.0.34. Selective Show Tickets loader, new cart pricing helper and Suite version; no schema, settings, public price or financial record change.

T13 follows user policy: current scheduled/settings price, clearly disclosed. Shared totals hook updates the in-memory product price and session cart-line baseline. Existing sessions without a baseline use prior pre-coupon/ex-tax subtotal per quantity, including when Woo reloads an already repriced product. Repeated calculations do not repeat transitions; a later distinct change generates a new notice. Unknown/nonconfigured tiers and unrelated products are untouched. Canonical eligibility remains separate T7 work.

A changed-price request produces a detailed review error before checkout can proceed. Classic validation and installed Store API's legacy-notice bridge both pause the request. The installed Store API checkout route calculates totals and validates the cart before draft-order processing/payment. Next request with the reviewed baseline proceeds. No persistent authorization flags, product database writes or bypass of CAPTCHA/payment/security checks.

Medium checkout impact. Woo owns coupons/taxes after repricing; extensions overriding ticket prices after priority 20 need separate reconciliation. No promise of protection against arbitrary external writers or concurrent settings changes after final totals calculation.

## Verification

- Explicit PHP 8.3 lint passes. Seven standalone regression groups exercise actual Products scheduled-price helper: exact boundary, successive changes, movie settings, matinee, subscribers, real showing identity, legacy quote, removed/unrelated types and request-scoped review.
- Twelve installed-Woo fixture assertions before/after deployment: actual cart totals, quantity totals, baseline session serialization, classic gate/reviewed continuation, detailed Store API error/409, notice restoration, next-request continuation, actual 25% coupon recalculation. Private draft showing inserted without publication hooks and removed in finally; product/coupon in memory. No customer session initialized/saved, order/payment created or gateway invoked. Other checkout validators isolated in this CLI subprocess (draft is intentionally not saleable and CLI has no browser CAPTCHA); not a full browser checkout assertion.
- Live browser: initial cart empty; one published October 30 General Admission ticket added at $20. tototest applies successfully, total $0. Checkout renders current $20 line, $20 discount and $0 total. Place order not clicked; test ticket removed and empty cart verified. No production schedule price manipulated to manufacture a transition.
- Deployed/local file SHA-256 matches. Suite `55ef4202c1f5e1695b4ff5ecd4e3581b296aeccc03d399d51a2d5c42a1ecba33`; loader `e99c46adc1746e471ca19af093544f365d502a3b908e9d5af552170942a75da7`; helper `1c8775082979fc56ed732e56c210763dbd5d574c8244912283ad45b729e01b83`.

## Recovery

Live loader normalized content equals Git baseline (only line-ending difference); Suite byte identity verified before backup/replacement. Original live files retained at `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-cart-pricing/originals`.

Archive copied before deployment to `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint35-rollback-files.tar.gz`. Server/I SHA-256 `fc8aa557518a3bda0b151e5e512b55cdb386b403294221dd574c15b0e45c4f4f`. Local verification does not assert cloud sync. Restore originals/roxy-show-tickets.php to module loader and originals/roxy-suite.php to Suite entry point; only then remove new class-roxy-st-cart-pricing.php. No data rollback needed.
