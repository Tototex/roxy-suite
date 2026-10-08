# Checkpoint 46 — Inventory connection-owned state changes

2026-10-06. Suite 1.0.45. Selective Inventory Store and Suite-version deployment.

Inventory named locks retain their owning connection identity, check ownership before/after callbacks, and release only on that connection. Nested state transactions recheck ownership. A transaction rejects pre-existing/autocommit-off transactions rather than implicitly committing someone else's work. Loss at START, write or COMMIT boundaries reports an uncertain save for review rather than silently succeeding.

Transaction-local SQL guards attach original connection/lock predicates to the actual managed Inventory insert/update/delete statement. Inserts use SELECT-with-predicate; update/delete predicates are grouped so OR cannot bypass ownership. Quoted WHERE text in product names/payloads is ignored. Captured stale SQL replay on a replacement connection affects zero rows even without the PHP guard remaining installed. Rollback targets only the original connection, including when its named lock was released; a replacement connection's unrelated transaction is untouched. Guard/depth/claims are cleaned on success/failure.

## Verification

- PHP 8.3 lint passes. Twenty actual private-MySQL checks pass: independent connections, actual lock release, rollback, guard cleanup, START-boundary loss, outer-pull loss, close/replacement, preservation of a foreign transaction, actual quoted/OR update and insert predicates, stale native SQL replay, rejection of an existing transaction/autocommit-off mode, and a subsequent successful operation.
- Connection closure/replacement and captured replay deliberately simulate loss; this is not a server restart, real network outage or proof of every wpdb retry/gateway outcome.
- Existing 21 actual temporary-MySQL checks, 14 isolated integrity checks, 18 fake-boundary Admin handler checks and inventory regression pass against deployed Store. Fake DB isolated fixtures do not exercise the WordPress SQL filter; actual MySQL fixtures do.
- Production products/vendors/orders/runs digests remain unchanged in the ownership fixture; before/after products/vendors/orders evidence files compare identically across deployments. All four production tables report InnoDB. Follow-up query finds zero private connection-fixture tables. Fixture cleanup completes with exit 0.
- Suite bootstrap reports 1.0.45. Public Tickets reloads correctly; cart visibly empty. No real inventory-saving pull, vendor-order transition, mail, payment or refund was performed. Authenticated browser workflows still await login.

## Recovery and remaining work

Both originals matched normalized Git baselines; staged/deployed bytes match local candidates and raw backups were rechecked before replacement. Server folder `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-inventory-ownership/`.

Verified archive on `I:\My Drive\Roxy Site Recovery\2026-10-06\checkpoint46-rollback.tar.gz`: 9,794 bytes, SHA-256 `655bd71a736ee703deac54fc7a2a8912021db21d9abd789d56b6f653b40d115b`. Contents, length and checksum verified before deployment; no cloud synchronization claim. No production schema/data migration.

I9 retains remaining legacy validation/missing-row/error-path work and uncertain external email outcomes. I4 receipt-event reconciliation and I6 unknown-cost/provenance remain separate. SQL guards protect managed unqualified single-row INSERT and WHERE-based UPDATE/DELETE forms while the Inventory transaction is active; this is not a promise to protect arbitrary external SQL, DDL or third-party storage engines. No whole-Suite audit completion claim.
