# Grosses anomaly status display — 2026-10-07

A read-only inspection of the live Grosses Logs page showed concessions mismatch
and unassigned-concession anomaly records displayed as `Success`. Those rows
mean the discrepancy was detected and logged successfully; they do not mean
the reconciliation passed.

The local candidate now displays `Review` for `anomaly` events and retains the
existing Success/Failed labels for other events. It changes no log records,
filter semantics, report values, or reconciliation behavior. Four isolated
result-label checks pass, the Grosses scheduler regression passes, PHP lint
passes, and `git diff --check` passes.

The live UI has not been changed. Deployment remains pending the release
checkpoint's safe backup/deployment process; no historical log data was edited.
