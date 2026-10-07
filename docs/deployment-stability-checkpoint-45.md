# Checkpoint 45 — Fail-closed Square inventory retrieval

2026-10-06. Suite 1.0.44. Selective Inventory Square and Suite-version deployment.

The inventory pull now rejects malformed native JSON objects/collections, invalid catalog/variation identities, wrong parent links, invalid stock quantities, unrequested locations/variations, duplicate counts and repeated/invalid cursors before stored stock is touched. Shared retrieval budget: 120 seconds / 100 pages, 10,000 catalog items / 20,000 variations. Calls use the remaining deadline, up to 35 seconds each. Partial pulls never reach the transaction.

Configured locations are deduplicated. Available stock still sums different requested locations across pages; legitimate negative decimal stock is retained. An opaque cursor `"0"` is followed. Absent/empty count collections remain legitimate zero stock, as Square documents the absence of a count for an object/state/location that has not interacted with that state. An unrecognized nonempty response is not mistaken for an empty inventory response. API version unchanged at 2026-09-16.

## Evidence

- PHP 8.3 lint passes. Forty-three actual-class fake-network/storage assertions cover malformed payloads, typed collections, identity/quantity validation, duplicates, pagination/budgets, valid negative/multi-location stock and legitimate empty results.
- Existing inventory integration regression passes with explicit location IDs in mock count records; purchase costs, approval policy, mail content and transactional activity behavior remain unchanged.
- Real read-only Square catalog/count probe parses 203 variations with candidate Store writes redirected to memory. SQL guard prevents live Inventory writes; HTTP guard permits only catalog and inventory-count read endpoints; no email permitted. Products/vendors/orders/runs digests unchanged. Probe passes again against deployed Square source.
- Isolated responses and existing regression pass again after deployment. Before/after products/vendors/orders evidence compares identically; Suite bootstrap reports 1.0.44. This is parser/provider-read verification, not a real inventory-saving pull or receipt/order test.

## Recovery and remaining work

Both originals matched normalized Git baselines before backup and raw originals before replacement; candidates/deployed bytes match local SHA-256. Server recovery folder `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-inventory-responses/`.

Archive verified before deployment: `I:\My Drive\Roxy Site Recovery\2026-10-06\checkpoint45-rollback.tar.gz`, 6,602 bytes, SHA-256 `5e78182c3da6aa5f67e9ebfec732dc722c290ddc069a5ffa1ada93e664804e30`. Cloud synchronization not asserted. No schema or historical inventory migration.

I9 remains open for lost database connection/lock ownership, remaining legacy validation and other state/error paths. I4 adjustment-event reconciliation, empty-catalog retirement policy and I6 unknown-cost/price provenance remain separate unresolved work. Existing two-decimal storage precision is unchanged; validation does not claim five-decimal persistence.

Primary contract checked: [Square InventoryCount](https://developer.squareup.com/reference/square/objects/InventoryCount), [batch counts](https://developer.squareup.com/reference/square/inventory-api/batch-retrieve-inventory-counts), and [ListCatalog](https://developer.squareup.com/reference/square/catalog-api/list-catalog).
