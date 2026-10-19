# Ticket checkout storage-verification guard deployment — 2026-10-19

The tested ticket capacity handler now blocks ticket add-to-cart and rechecks the cart at checkout when WooCommerce storage is missing, HPOS-enabled, or cannot be positively verified as the legacy post-based mode. This fails closed without enabling HPOS or changing WooCommerce data.

- Hosted workflow [39295666882](https://github.com/Tototex/roxy-suite/actions/runs/39295666882) passed all jobs, including new assertions for add-to-cart rejection, the explanatory notice, and checkout-time revalidation if storage becomes unverified after carting.
- Exact predeploy SHA-256: `7ac57e0dad322303b8faef0e9ced3df9f42007b98048bcd8d1b4312f326e3a3b`.
- Verified originals: `I:\My Drive\Roxy Site Recovery\2026-10-19\ticket-storage-checkout-guard\class-roxy-st-capacity.php.original` and `/home1/anrvxfmy/deploy-backups/roxy-suite-20261019-ticket-storage-checkout-guard/class-roxy-st-capacity.php.original`.
- Installed SHA-256: `ba3122153728f7320794ca2afc6bcc5779dea89d89796a060c00b009819a66fa`; candidate and installed PHP lint passed.
- Public home, tickets, and showings returned HTTP 200.
- No WooCommerce setting, order, payment, cart, ticket, or customer record was changed during deployment or smoke checks.
