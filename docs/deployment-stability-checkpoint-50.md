# Checkpoint 50 — Uncertain order-email holds and Settings API validation

2026-10-06. Suite 1.0.49. Selective Inventory Admin/Settings and Suite version deployment.

False or thrown mail results cannot prove a message was not accepted downstream. The existing persisted pending-manager order now remains open instead of moving to email_failed and permitting a second fresh submission. Warning redirects retain order ID, discourage resubmission and direct inbox review. Same-key replay still sends no second message. A signed-in authorized manager can explicitly confirm an actually placed pending order as Ordered through the existing signed-link, confirmation POST and nonce checks; anonymous pending confirmation is rejected. Existing cancellation resolves an order not placed. This does not provide exactly-once SMTP delivery or retroactively quarantine old email_failed orders.

Registered Settings API sanitizer now validates complete input without the custom form marker, accepts normalized zero/one checkbox values and preserves the existing option on malformed input with a settings error. Public legacy sanitize remains compatible. Defaults are used if no readable saved option exists; database read-failure certification is not claimed.

## Verification

PHP 8.3 lint passes. Before/after deployment: 50 isolated Admin handler assertions (false/throw/replay), 14 decision-link assertions (including authenticated pending confirmation), 31 isolated callback assertions, nine installed WordPress callback/private-option assertions, 14 integrity assertions, 36 shared stability assertions and legacy inventory regression pass with exit 0. Actual callback test initially compared arrays with a different normalized key order; the fixture was corrected to compare against actual sanitized normalization and rerun successfully, with private option cleaned after failure and success.

Production inventory settings and cron unchanged in actual callback test. Products/vendors/orders before/after evidence compares identically. No real mail, order, inventory-saving pull, payment or refund performed. Suite bootstrap reports 1.0.49. All ten live modules pass structural diagnostics; these do not certify all business workflows. Authenticated browser forms still await WordPress sign-in.

## Recovery

All three originals match normalized Git baselines; deployed readback hashes match candidates. Server folder `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-inventory-email-holds/`.

I-drive archive verified before deploy: `I:\My Drive\Roxy Site Recovery\2026-10-06\checkpoint50-rollback.tar.gz`, 17,555 bytes, SHA-256 `8e0142591b6e1bfcf935612cf0cd1174149c4ad6059fb2b052b8141718dffa0a`. Contents verified; no cloud-sync claim. No schema/data migration.

I9 remains in progress for additional error paths, complete-empty-catalog handling and full browser verification. Manager role split and refund transaction-date interpretation still await earlier user choices. No whole-Suite completion claim.
