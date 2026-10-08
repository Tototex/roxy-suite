# Grosses advertiser attachment isolation — 2026-10-08

Advertiser email workbooks are generated under the protected private directory
with a UUID-bearing filename reserved using exclusive file creation. A collision
retries with a fresh ID and cannot overwrite the existing file. The attachment
is removed after the synchronous WordPress mail attempt, including accepted,
false, thrown, duplicate-suppressed, and outbox-claim-failure paths. A failed
workbook build also removes its partial file.

The intercepted-mail regression forces a filename collision with an existing
sentinel, verifies that it is preserved, and checks that all generated
attachments are distinct and removed across success, suppression, failed and
thrown mail, and pre-mail outbox failure. All 13 advertiser outbox checks pass.
Grosses anomaly status and filter checks are also part of the compatibility
workflow.

GitHub Actions run 37734604353 passed runtime PHP lint and the showing-selector,
Will Call, Grosses log-result, and advertiser email attachment regressions on
PHP 8.0–8.4. Mail was intercepted; no advertiser email was sent, and no live
site or production data was changed. This closes the same-period advertiser
attachment overwrite race for generated mail attachments only. Other workbook
snapshot generation paths, public download controls, and overall G10 review
remain open.
