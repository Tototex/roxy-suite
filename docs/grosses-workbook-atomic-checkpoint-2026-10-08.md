# Grosses workbook snapshot atomicity — 2026-10-08

Annual workbook generation no longer writes directly to its saved snapshot
filename. It reserves a unique same-directory temporary path exclusively,
copies and updates the workbook there, verifies each sheet update and archive
close, then atomically renames the completed file over the snapshot. Failures
close and remove the temporary file while preserving the prior snapshot. A
temporary-name collision retries without overwriting the existing file.

Seven isolated checks create and open a real XLSX archive, verify required
worksheets, force a bad template and confirm the previous snapshot digest is
unchanged, then force a temporary-path collision and verify it is preserved and
the failed generation's file is removed.

GitHub Actions run 37734845283 passed the workbook, advertiser email, Grosses
log-result, Will Call, and showing-selector regressions plus plugin PHP lint on
PHP 8.0–8.4. The test used only private temporary fixtures; it did not access the
live site, refresh production data, or send email. Other export/attachment paths
and live deployment/access verification remain under G10.
