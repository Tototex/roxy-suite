# Checkpoint 44 — Inventory save, activity and scheduling failures

2026-10-06. Suite 1.0.43. Six-file selective deployment: Inventory Admin, Store, Square, Settings, Scheduler and Suite version.

Legacy product/vendor handlers now catch storage exceptions and display failure instead of ending without guidance. Settings distinguishes an unchanged option from a failed write and separately reports settings saved but scheduling failed. Post-email status failures retain the known mail outcome and direct the manager to the existing order. Activity-write failures after email, cancellation or a decision warn that the action already happened; they must not encourage duplicate submissions.

Store activity writes return an explicit result with a server-log fallback. Pull success activity is now inside the same transaction as stock and order-reset changes: failure rolls back the pull. Activity read errors are not mistaken for no previous run. These changes do not introduce nightly emails or change Pepsi/Vistar units, purchase rules or existing orders.

Inventory scheduling checks registration and readback. Failed or ineffective event removal stops rather than looping indefinitely; automatic repair failures are logged without breaking public bootstrap. Invalid clock values fall back to 23:00. Successful existing one-shot events are preserved.

## Verification

- PHP 8.3 lint passes for all six deployed files.
- 18 isolated actual-Admin handler checks, with fake storage/mail/scheduling: authorization/nonce order, save failure, unchanged option, failed option, scheduling failure, completed-action log warnings, and sent/unsent status-write failures. No real email sent.
- 21 actual MySQL checks in connection-local temporary products/orders/runs tables: duplicate submission, status changes, partial arrival, stale progress, rollback on later write/activity failure, truthful activity read failure, independent lock contention and pagination. Production vendor-order rows remain unchanged.
- Existing inventory pagination/cost/status/mail-content tests pass, expanded for activity rollback/retry and invalid clocks. Fourteen integrity checks, 11 signed-decision checks and 32 shared stability checks pass against deployed sources. Scheduler failures use fake cron state, not production event mutations.
- Suite bootstrap reports 1.0.43; current Inventory event remains a one-shot at 2026-10-07 06:00 UTC (October 6, 23:00 Pacific). Before/after read-only products/vendors/orders evidence files compare byte-identically.
- Public Tickets reloads after deployment with the expected October 30 event. Admin browser is still signed out: no authenticated settings/save workflow or real provider delivery certification is claimed.

## Recovery / limits

All six originals matched normalized Git baselines before backup and raw backup copies before replacement. Staged/deployed files match local SHA-256 candidates. Server backup: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-inventory-errors/`.

Verified recovery archive: `I:\My Drive\Roxy Site Recovery\2026-10-06\checkpoint44-rollback.tar.gz`, 23,093 bytes, SHA-256 `09b6ec80849a5e6e92332debb7da1cd8d4113fd1770c4c522587f8a6e6a318d4`. Contents, length and hash checked before deployment; cloud synchronization is not asserted. No schema or historical data migration.

I9 remains in progress: database reconnection/lost-lock behavior, all legacy validation/error paths, and provider-response hardening require further work. Unknown-cost safeguards remain I6; adjustment-event reconciliation remains I4. No whole-Suite completion claim.
