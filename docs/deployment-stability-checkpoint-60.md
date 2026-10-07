# Checkpoint 60 — Historical refund category-price evidence

2026-10-06. Suite 1.0.57 selectively deployed; Store, Reporter and Suite version readback hashes match.

Any refunded movie showing rebuilt for a past original sale date now requires saved nominal-price evidence for every category with remaining tickets, including unaffected lines in that same showing. The date uses the configured report timezone. This applies in the shared builder used by manual pulls, drafts, fresh reports and automatic correction. Missing historical category evidence cannot silently substitute today's price. Store searches older complete emailed snapshots when newer evidence lacks a required category; otherwise it uses existing unambiguous canonical evidence or stops for review. Same-day first pulls retain current-price fallback, and fully refunded zero-ticket showings require no irrelevant unit price.

Manual movie pulls now accept full-refund zero-ticket corrections consistently with drafts and automatic refresh. Empty non-refund pulls still stop before entry writes. This does not automatically resend emailed reports, change financial refund-day accounting or recalculate existing historical records.

## Verification

Before/after: 27 actual Reporter builder assertions (fake Square/Store, including report-day clock, missing evidence before flags/mail, original general/discount prices and zero-ticket behavior); 19 actual private-MySQL Store price-evidence assertions; 13 manual/snapshot/allocation orchestration assertions with fake builders/storage; and 11 actual Reporter/Store private-MySQL integration assertions. Private schemas/tables are randomized and removed after tests; provider/calendar boundaries are virtualized. The combined fixture preserves concession/manual fields, immutable emailed snapshots and separate refund-review evidence, and refuses flagged resend without mail. It is not a real refund or authenticated admin certification.

Post-deployment all 49 staged isolated PHP programs pass against installed sources; all 78 installed PHP files pass PHP 8.3 lint and all ten module structural diagnostics pass. Original movie/live/rental/legacy financial digests remain identical to the pre-checkpoint59 baseline. Live public Tickets reloads unchanged; reporting admin still requires sign-in. Smaller-model read-only review found no immediate integration defect; it correctly noted that category evidence is not proof a snapshot predates the specific return. Corrupt/previously repriced historical evidence and complete backfill remain manager-reconciliation work, not guessed repairs.

G2 remains partial: dated financial cash refunds, live-door/Woo corrections, bounded older refund discovery, custom/exchange review and evidence provenance remain open. No real Square call, refund, payment, report email, vendor order or historical financial row was changed by this checkpoint.

## Recovery

Three originals and checksum manifest: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-refund-category-prices/`.

Verified before deployment: `I:\My Drive\Roxy Site Recovery\2026-10-06\roxy-checkpoint60-rollback.tar.gz`, 38,841 bytes, SHA-256 `fa2c658ae92582e80961ec4053f9ec359ebb16dc717cbbc1a10f50b38cb97a3a`. No cloud-sync claim or schema change. Requested Showing agreement helper remains unintegrated/undeployed.
