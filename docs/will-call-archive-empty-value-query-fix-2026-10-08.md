# Will Call archived showing query fix — 2026-10-08

## Finding

Live read-only verification of the archived-showing dropdown found that its WordPress meta query paired `compare => EXISTS` with `value => ''`. On the production WordPress version, that combination returned zero rows even though 108 showings had `_roxy_start` metadata. The dropdown therefore rendered only its placeholder. The standalone fixture had accepted the invalid combination because its `get_posts()` mock ignored the empty `value` semantics.

## Change

Build the start-date meta clause conditionally: archived queries use `EXISTS` and omit `value`; current-showing queries continue using `>=` with the local current date. Extend the regression mock so `EXISTS` plus an explicitly empty value returns no rows, and assert the archived query omits that key.

## Verification

- Before the fix, the exact production query returned 0 archived showings; the corresponding query without `value` returned 108.
- The candidate-focused `showing-selection-regression.php` passes all 20 checks on host PHP 8.5.11, including paging, ordering, navigation bounds, current-showing selection, and timezone cases.
- Candidate and test files pass host PHP syntax checks.
- No production files or records were changed during diagnosis. This fix is branch-only pending hosted CI, a verified exact-file backup, selective deployment, and live read-only UI confirmation.
