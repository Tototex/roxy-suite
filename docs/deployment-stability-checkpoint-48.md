# Checkpoint 48 — Grosses list validation compatibility

2026-10-06. Suite 1.0.47. Selective Grosses Square/Store/RefundSnapshot and Suite-version deployment.

Eight native PHP 8.1-only array_is_list calls introduced by later reporting fixes are replaced with private PHP 8.0-compatible helpers. Empty/sequential arrays remain lists; sparse, associative or out-of-order keys are rejected. No global polyfill or report/refund policy change.

## Verification

PHP 8.3 lint passes for all three classes. Before and after deployment: 18 helper cases, existing Square response regression and 40 refund-snapshot assertions pass with exit 0. Actual private MySQL tests pass for 17 refund-review assertions and 16 original nominal-price assertions. They preserve private saved snapshots/canonical evidence and never write production reports or send mail; owned private tables are removed.

Current target runtime is PHP 8.3. No PHP 8.0 interpreter is available here: helper syntax uses only PHP 8.0-supported features, but this is not whole-Suite runtime certification under 8.0. Public live Tickets page renders normally; Suite bootstrap reports 1.0.47. No checkout, order or email performed in this checkpoint.

## Recovery

Four live originals match normalized Git baselines; candidate/deployed readback hashes match. Server folder `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-grosses-list-compatibility/`.

Verified I-drive recovery archive before deploy: `I:\My Drive\Roxy Site Recovery\2026-10-06\checkpoint48-rollback.tar.gz`, 27,064 bytes, SHA-256 `018065d5e3528db00b24ff1919da196a0219daaba82a4b0f1564066af0943ae6`. Contents verified; no cloud-sync claim. No schema/data migration. First archive read failed parsing; a fresh complete read was checksum-verified before deployment.

C3's introduced native-list compatibility regression is corrected. Remaining Grosses financial ledger/backfill/catch-up/send coordination and full authenticated browser testing remain separate; no whole-Suite completion claim.
