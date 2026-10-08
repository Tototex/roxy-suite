# Showing date validation checkpoint — 2026-10-08

## Change

The Show Tickets save handler now validates single showing start values as exact local calendar dates and 24-hour wall-clock times in the WordPress site timezone. Schedule-builder submissions are validated as a whole before shared metadata or generated showings are written. Impossible dates, invalid times, incomplete rows, and non-scalar submitted values reject the entire save; the prior saved showing and shared settings remain intact. An administrator receives a visible notice explaining that the submitted schedule was not saved.

Valid date strings remain unchanged local wall-clock values, including leap-day values. DST gap times that normalize to a different local value are rejected; ambiguous fall-back local values retain the established wall-clock representation.

Scheduled live-ticket price change dates now use the same strict validation. “Duplicate to next weekend” validates the saved source showing start and advances each configured price-change date by seven site-local calendar days, preserving its wall-clock time through spring and fall DST transitions. If an existing saved start/price-change date is malformed or cannot be represented at the shifted date, the duplicate is not created rather than inheriting an invalid schedule.

## Regression coverage

`tests/schedule-child-status-regression.php` covers impossible single and price-change dates preserving prior metadata, valid leap-day persistence, invalid batch rejection without partial child creation, and seven-day price-date shifts across both Los Angeles DST transitions. Fixture transients are reset between cases. Additional scalar guards prevent malformed arrays from generating string-cast warnings.

## Verification boundary

`git diff --check` passes. Hosted run 37737143194 passes all tracked-PHP syntax checks on PHP 8.0–8.4 and the complete 71-program isolated regression suite on PHP 8.3, including the schedule child status and price-date shift tests plus malformed source-date rejection. The earlier date-validation attempt exposed a missing fixture stub, which was added and verified in successful run 37736678490. This is branch-only; no production deployment or live data change was made.
