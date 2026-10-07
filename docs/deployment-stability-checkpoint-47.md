# Checkpoint 47 — Inventory save validation

2026-10-06. Suite 1.0.46. Selective Inventory Admin/Store and Suite version deployment.

Legacy product forms require complete rules and canonical record IDs; omitted tracking status preserves the existing value. Shared bulk validation rejects fractional quantities, overflow, excessive cost precision and malformed input. Vendor selections must still exist, checked inside the serialized transaction. Vendor forms require complete values, a supported order method, valid optional email and nonnegative two-decimal minimum. Live methods are email, online and manual, all supported. Missing product/vendor rows now fail rather than report success; unchanged existing rows remain valid saves.

## Verification

PHP 8.3 lint passes. Before and after deployment: 35 isolated Admin handler assertions, 26 actual temporary-MySQL assertions, 14 isolated integrity assertions, 36 shared stability assertions and the inventory regression pass. Twenty actual private connection-ownership checks pass against the candidate Store, preserving all four live dataset digests and cleaning private fixtures. Fake handler checks do not replace authenticated browser form testing.

Initial test invocations had wrong fixture paths or a missing staged root file; corrected invocations pass with explicit exit 0. The first postdeploy MySQL invocation omitted its required Store argument and failed before fixture writes; the corrected invocation passes. No production rules, inventory pulls, orders, email, payment or refund were changed. Products/vendors/orders before/after evidence compares identically. Suite bootstrap reports 1.0.46. Public cart reloads normally and is visibly empty. Authenticated browser testing remains pending WordPress sign-in.

## Recovery

All three originals matched normalized Git baselines; candidate bytes and deployed readback hashes verified. Server recovery folder: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-inventory-validation/`.

Verified before deploy: `I:\My Drive\Roxy Site Recovery\2026-10-06\checkpoint47-rollback.tar.gz`, 22,038 bytes, SHA-256 `01969524a0bc6c4c5d81b953890f4e28c95b859f79a39e27ef07982f1a74ed58`. Contents verified; no cloud-sync claim. No schema/data migration.

I9 remains open for additional settings/error paths and uncertain external email delivery. This is not whole-Suite audit completion.
