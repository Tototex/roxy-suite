# Checkpoint 39 — consistent Square tax and discount arithmetic

2026-10-06. Suite 1.0.38. Selective Grosses Reporter and Suite version.

Concession allocation and daily reconciliation now share one integer-cent calculation. Prefer actual line total_money minus total_tax_money; this preserves discounts and allocated service charges. US gross_sales_money already excludes tax, so a fallback deducts discounts and retains allocated charges rather than subtracting tax twice. An explicit zero total remains zero. Aggregate money is counted once, not multiplied again by quantity. Legacy aggregate-base fallback is permitted only without tax ambiguity; missing totals or malformed/noninteger/negative/non-USD money stops the report instead of inventing figures. Square's current official [line-item fields](https://developer.squareup.com/reference/square/objects/OrderLineItem) support the distinction between unit price, variation aggregate, gross, discount, tax and amount to collect. No API-version change.

Movie studio nominal ticket prices/counts remain on their existing path. This is the tax/discount arithmetic fix only: itemized/custom refunds, refund-date policy, immutable shared date snapshot and allocation-after-email remain open G2/G4/G11. No historical recalculation, pull/save, real report send or vendor order test.

## Verification

- Explicit PHP 8.3 lint passes. 25 synthetic checks cover US inclusive/exclusive taxes, discount, modifiers/multiple units, free lines, apportioned charge, fallback parity, aggregate quantity handling, daily reconciliation and refusal cases.
- Actual Square search-only comparisons across October 3–5: 19 completed orders, 28 line items. Zero October 5 orders; October 4 has 12/16 and October 3 has 7/12. Cents match total less tax; no receipt/customer identifiers printed, accounting writes or mail deliveries.
- Seven standalone saved-report tests before deployment and ten installed WP saved-report tests before/after deployment pass; emails including failure alerts intercepted.
- Original four financial dataset counts/projection digests match checkpoints 37/38. No report or historical figure changed.
- Live bytes match local SHA-256: Reporter `b1d162fe1d9580b5fc32827b7ad388709354423539596e3dcbf146c0261d47c3`; Suite `b2c14bdb95e656765022d5d237b5d3c9e2da41d6e56b4d450f551a38477caeba`.

## Recovery and risk

Medium reporting impact. Normalized live Reporter baseline matched HEAD; Reporter/Suite originals byte-compared immediately before replacement. Server `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-grosses-money/originals/`. Before deployment copied rollback archive to `I:\My Drive\Roxy Site Recovery\2026-10-06\checkpoint39-rollback.tar.gz`, 19,544 bytes, matching server/I SHA-256 `33644d21190ce22b5acaa77046dd9a3fb073dda8ae5a237f43b5d8f4357431d2`. Cloud sync not asserted.

Restore the two original PHP files for rollback; no data restore needed. Corrected future calculations can differ from old erroneous concession totals when an authorized pull is run. Foreign currencies are deliberately refused rather than converted. Refunds still require their separate reconciliation implementation and policy confirmation; passing these checks is not refund reconciliation or mail delivery certification.
