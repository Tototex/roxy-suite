# Will Call archived showing query fix — 2026-10-08

## Finding

Live read-only verification of the archived-showing dropdown found that its WordPress meta query paired `compare => EXISTS` with `value => ''`. On the production WordPress version, that combination returned zero rows even though 108 showings had `_roxy_start` metadata. The dropdown therefore rendered only its placeholder. The standalone fixture had accepted the invalid combination because its `get_posts()` mock ignored the empty `value` semantics.

## Change

Build the start-date meta clause conditionally: archived queries use `EXISTS` and omit `value`; current-showing queries continue using `>=` with the local current date. Extend the regression mock so `EXISTS` plus an explicitly empty value returns no rows, and assert the archived query omits that key.

## Verification

- Before the fix, the exact production query returned 0 archived showings; the corresponding query without `value` returned 108.
- The candidate-focused `showing-selection-regression.php` passes all 20 checks on host PHP 8.5.11, including paging, ordering, navigation bounds, current-showing selection, and timezone cases.
- Candidate and test files pass host PHP syntax checks.
- No production files or records were changed during diagnosis. The exact pre-deployment PHP file was copied to the I: recovery folder and a private server recovery directory, with both backups matching the live SHA-256 `31bc6ab8dbd8823b1657c5803f471f5aa6c18815c429595a368337416d514654`.
- Hosted workflow [37846450441](https://github.com/Tototex/roxy-suite/actions/runs/37846450441) passes the PHP 8.0–8.4 syntax matrix and both private WordPress/MySQL fixture jobs.
- The single candidate PHP file was deployed with a live-hash guard and atomic same-directory replacement. Production PHP lint passes; its post-deployment SHA-256 is `79dd956cdbc0ab52dc4010679bd810c2c5054f8f0d5c0b3f10982eee9c1054f8`.
- A read-only production render now returns 109 options (108 showings plus placeholder), archived markers, and Archive page 1. It correctly omits Older showings because fewer than 200 archived records exist. No order, ticket, or attendance data changed.
