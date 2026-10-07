# Checkpoint 55 — Requested Showing subscriber eligibility safeguards

2026-10-06. Suite 1.0.52. Repository, public backing handler, conversion and Suite version deployed selectively.

Subscriber pledges now reuse the existing active/pending-cancel membership entitlement policy, reject unverified eligibility, and check aggregate outstanding user/request subscriber quantities under a connection-owned named lease. The actual insert includes ownership predicates, and failure paths release only verified owned leases. Canonical quantities and schema-representable amounts prevent truncation/overflow; paid-only pledge behavior is retained.

Conversion rechecks entitlement and existing showing reservations before creating a subscriber order, verifies saved ticket/owner/request identities, links a new saved order to its backing before payment, and requires the existing persisted seat hold before charge/no-charge completion. Already-paid or mismatched existing orders require review rather than another charge. This is not full durable conversion/payment coordination or ambiguous Stripe reconciliation; R4 remains open.

## Verification

PHP 8.3 lint passes. Before/after: 42 isolated assertions exercise actual handler/repository/conversion code with fake WordPress/Woo collaborators and actual Capacity eligibility logic. Eleven actual private temporary-MySQL assertions verify prepared inserts, aggregate caps, release, ownership loss at INSERT and unchanged production backing rows; temporary schema removed, exit 0. First isolated staged run lacked unchanged schema.php; copied it into the candidate root and reran successfully. No real membership, showing, order, mail, charge or provider call created by these fixtures. Actual installed Woo conversion/confirmation lifecycle and paid provider behavior are not certified by this pass.

Live public Requested Showings page renders its no-active-request and sign-in-required states correctly. Actual production backing count is two; no existing row was rewritten. Authenticated browser backing/manager approval still await sign-in. R2 remains in progress for full installed conversion lifecycle and arbitrary writers; R3 agreed-price persistence and R4 durable payment claims remain open.

## Recovery

Four originals match normalized Git baselines; deployed readback hashes match candidates. Server originals: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-requested-entitlement/`.

Before deployment, archive contents/checksum verified at `I:\My Drive\Roxy Site Recovery\2026-10-06\roxy-checkpoint55-rollback.tar.gz`: 19,613 bytes; SHA-256 `d41ab8164f87dccc81eb12ecfbcc17e831c0eefaee73df46c47ca32581d7822e`. No cloud-sync claim; no schema migration or historical rewrite.
