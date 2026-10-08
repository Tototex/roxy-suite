# Grosses anomaly status display — 2026-10-07

A read-only inspection of the live Grosses Logs page showed concessions mismatch
and unassigned-concession anomaly records displayed as `Success`. Those rows
mean the discrepancy was detected and logged successfully; they do not mean
the reconciliation passed.

The local candidate now displays `Review` for `anomaly` events and retains the
existing Success/Failed labels for other events. The result filter now has a
separate Review option, and Success/Failed exclude anomaly rows so the filter
matches what the table displays. It changes no log records, report values, or
reconciliation behavior. Four isolated result-label and three filter checks
pass, along with the Grosses list-compatibility and 35 scheduler checks. PHP
lint passes.

The live UI has not been changed. Deployment remains pending the release
checkpoint's safe backup/deployment process; no historical log data was edited.
