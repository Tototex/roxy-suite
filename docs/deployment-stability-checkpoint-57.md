# Checkpoint 57 — Serialized requested-showing conversion and creation evidence

2026-10-06. Suite 1.0.54 selectively deployed; candidate readback hashes match.

Request approval and pledge INSERTs share a connection-owned named lease. Repository submission rechecks request type, active status, deadline and started-conversion evidence under that lease. Both paid and subscriber INSERTs carry ownership predicates in SQL, so reconnect/ownership loss cannot silently execute an unguarded insert. Approval closes pledging with the explicit Conversion / Manager Review status before creating a showing or reading backings.

Per-backing leases re-read current rows rather than trusting approval snapshots. Immutable creation evidence is transactionally committed before new showing/order creation; missing saved links after interruption require reconciliation rather than another entity. Existing saved orders can still follow the guarded retry path. Creation markers are intentionally not automatically cleared even after a definite creation error; operators must inspect showing/order/provider records before recovery. Provider calls remain outside SQL transactions.

Backing list failures no longer masquerade as empty successful approvals. Partial conversions notify the manager but suppress Suite's customer success email. Charged/no-charge completion must be saved and read back against order/showing/intent identities before conversion returns success.

## Verification

Before/after: 55 isolated actual handler/repository/conversion assertions with mocked collaborators, 46 isolated actual payment/creation-helper assertions, 12 actual temporary-MySQL pledge assertions with virtual membership/request eligibility, seven actual private-request SQL creation assertions, 17 mocked-provider orchestration assertions and 14 privacy/deadline assertions pass. Temporary table/private request removed; original backing/request/showing/order rows unchanged. Installed structural diagnostics pass for all ten modules. Six changed PHP sources pass PHP 8.3 lint. No real approval, payment, refund, public showing, customer email or vendor order was invoked by these fixtures. Installed Woo order creation/completion and paid provider behavior are not certified by this pass.

Review found and fixed missing shared public-pledge serialization, backing-list read failures, an open partial-conversion request and unchecked completed-backing writes. Fixture corrections: fake SQL returns stored scalar strings, candidate class-name replacement needed escaped-string handling, and partial failure correctly sends a manager review notice (not zero mail). R4 remains partial: commit-then-response-loss for pledge insertion still needs a durable receipt/uncertainty gate; complete provider reconciliation and installed lifecycle remain open. R3 price/currency/tax snapshot remains open. Publication/occupancy coordination is not certified here.

## Recovery

Five originals plus new-helper absence manifest: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-conversion-claims/`.

Verified before deployment: `I:\My Drive\Roxy Site Recovery\2026-10-06\roxy-checkpoint57-rollback.tar.gz`, 19,516 bytes, SHA-256 `72ba15cf3226b784d651de6c42340db2bec34f04b3700b2eeb4520b2f492810f`. Initial preparation script accidentally renamed the SHA-256 algorithm while changing checkpoint numbers; it failed before backup/deployment. Corrected and verified fresh archive used. The failed 20-byte server archive was not used or copied offsite. No cloud-sync claim, schema migration or existing backing rewrite.
