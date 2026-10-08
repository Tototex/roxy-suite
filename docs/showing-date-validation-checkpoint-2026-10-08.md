# Showing date validation checkpoint — 2026-10-08

## Change

The Show Tickets save handler now validates single showing start values as exact local calendar dates and 24-hour wall-clock times in the WordPress site timezone. Schedule-builder submissions are validated as a whole before shared metadata or generated showings are written. Impossible dates, invalid times, incomplete rows, and non-scalar submitted values reject the entire save; the prior saved showing and shared settings remain intact. An administrator receives a visible notice explaining that the submitted schedule was not saved.

Valid date strings remain unchanged local wall-clock values, including leap-day values. DST gap times that normalize to a different local value are rejected; ambiguous fall-back local values retain the established wall-clock representation.

Scheduled live-ticket price change dates now use the same strict validation. “Duplicate to next weekend” validates the saved source showing start and advances each configured price-change date by seven site-local calendar days, preserving its wall-clock time through spring and fall DST transitions. If an existing saved start/price-change date is malformed or cannot be represented at the shifted date, the duplicate is not created rather than inheriting an invalid schedule.

The Will Call showing-date reader now accepts only exact supported local date/time formats and rejects impossible dates and DST-gap wall times rather than relying on `DateTimeImmutable` normalization. This keeps its archive labels and past/future classification consistent with the admin write contract.

The Show Tickets admin weekend anchor now uses the same strict validator, preventing a malformed existing value from receiving a misleading “Duplicate Weekend” action or being normalized into another weekend.

The Show Tickets admin list date column now uses that validator as well; invalid historical metadata renders as unavailable rather than displaying a normalized date.

Ticket-product synchronization and generated product labels now require the same validated showing timestamp. Customer-facing showing cards, the single-show date label, and the SEO title also use that timestamp and wp_date() so invalid legacy dates are omitted and valid timestamps are rendered once in the site timezone.

## Regression coverage

`tests/schedule-child-status-regression.php` covers impossible single and price-change dates preserving prior metadata, valid leap-day persistence, invalid batch rejection without partial child creation, seven-day price-date shifts across both Los Angeles DST transitions, the actual duplicate action writing shifted dates to the generated showing, admin weekend-anchor validation, and safe date-column rendering. `tests/will-call-regression.php` covers strict local date parsing for leap day, impossible dates, DST gaps, and ambiguous fall-back time. Social schedule tests also reject impossible dates. Fixture transients are reset between cases. Additional scalar guards prevent malformed arrays from generating string-cast warnings.

The installed WordPress duplicate fixture (`tests/ticket-weekend-duplicate-wordpress.php`) was updated to expect the shifted scheduled price dates for both DST scenarios. It requires the private WP-CLI fixture environment and was not executed in this local hosted-CI pass.

## Verification boundary

Follow-up verification: hosted run 37738638386 passes tracked PHP lint on PHP 8.0–8.4 and the full isolated regression suite on PHP 8.3. This run includes the new malformed-date product synchronization and SEO-title assertions and the strict site-timezone public date rendering changes.

`git diff --check` passes. Hosted run 37738150983 passes all tracked-PHP syntax checks on PHP 8.0–8.4 and the complete 71-program isolated regression suite on PHP 8.3, including the actual duplicate action writing the shifted price dates, malformed source-date and admin weekend-anchor rejection, safe admin date rendering, Will Call strict parsing, and existing Social invalid-date checks. The installed WordPress fixture was syntax-checked but not executed in the hosted isolated environment; no production deployment or live data change was made. The earlier date-validation attempt exposed a missing fixture stub, which was added and verified in successful run 37736678490.
