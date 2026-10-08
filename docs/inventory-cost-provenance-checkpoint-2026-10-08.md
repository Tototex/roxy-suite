# Inventory cost provenance checkpoint — 2026-10-08

## Change

- Product costs now distinguish Unknown, Estimate, Confirmed quote, and Free.
- Existing positive configured prices are preserved and migrated to Estimate. Existing zero values remain Unknown; no price, pack size, stock rule, vendor, or order quantity is rewritten by the data migration.
- Confirmed quotes and Free items require a source and checked date. Estimates may remain source/date-less to preserve existing configured values without claiming a supplier quote.
- Supplier SKU is stored separately from Square SKU and captured in order snapshots and manager-forwardable email text.
- New Square products start with unknown cost, no source/date, and blank supplier SKU.
- Inventory schema version advances to 0.1.16. Each additive product column is explicitly added if absent and checked again after DDL; the one-time data-migration marker is saved only after the data write succeeds.
- Positive-quantity orders with Unknown/malformed cost are rejected; explicitly Free lines are valid zero-cost lines. Vendor minimum checks still apply to the resulting total.

## Verification

- Hosted workflow [37746306562](https://github.com/Tototex/roxy-suite/actions/runs/37746306562) succeeded: PHP syntax on 8.0–8.4 and the full PHP 8.3 isolated cross-module regression suite.
- Eleven focused schema/data migration checks cover column-add verification and failure, preserving numeric cost and unrelated rules, retaining zero as Unknown, migration-marker idempotence, and retry behavior after data/marker failures.
- Inventory order-integrity tests cover Unknown rejection, Free zero-cost acceptance under a zero vendor minimum, saved provenance, and forwarded email inclusion of supplier SKU and quote source/date.
- This is a GitHub audit-branch change only. No production migration or live UI was run; database behavior against WordPress/MySQL and live UI rendering still require a protected test deployment. No vendor order/email was sent.
