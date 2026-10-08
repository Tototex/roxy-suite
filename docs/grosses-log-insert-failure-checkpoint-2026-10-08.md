# Grosses audit-log insert fallback — 2026-10-08

`Store::insert_log()` now emits one fixed, non-recursive PHP error-log message when its database insert fails, then preserves the existing `0` return value. The fallback contains no SQL, exception, event, report, message, or context data and does not retry the database write.

The focused regression uses an isolated failing database fixture and a temporary PHP error log. It checks the return value, exactly one insert attempt, exactly one generic signal, absence of caller-supplied private data, and an unaffected single primary-operation marker. Hosted workflow [37763883660](https://github.com/Tototex/roxy-suite/actions/runs/37763883660) passes all five PHP 8.0–8.4 syntax jobs and the PHP 8.3 full isolated suite, including this regression and the schema-bootstrap regression.

Risk is low: only the failed audit-log path changes, and it adds one generic server-log line. PHP CLI is unavailable in the workstation environment, so local execution could not be verified; the hosted PHP matrix is the execution evidence. No deployment or production data change was performed.
