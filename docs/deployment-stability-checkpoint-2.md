# Stability checkpoint 2 — 2026-10-02/03

Selective deployment on top of checkpoint 1, version 1.0.17; not a complete Suite release or completion of the audit.

Changed production files: Inventory Admin; Requested Showings CPT, Frontend, Conversion. Private backups remain under `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261003-054238/`: original Requested Showings files in `original/`, prior Inventory Admin in `checkpoint1/`. No production Git reset.

All four deployed files match the locally transferred source after CRLF/LF normalization. All six isolated suites rerun against deployed source and passed. Actual WordPress excerpt and REST filters verified with in-memory fixtures, anonymous REST returned 200, and the public request-page calendar/date/deadline lookup worked. Production scan found no requested-showing records or generated contact excerpts requiring migration.

Inventory confirmation fixtures verify read-only GET, expiring scoped signatures, tamper/expiry rejection, nonce-before-write, confirmed POST returning to history, and replay rejection. Existing four real vendor orders remain Ordered with unchanged totals; no real order confirmation sent during testing. Old anonymous indefinite links are invalid; signed-in authorized managers can still confirm legacy links. Newly generated manager links expire after 30 days and require explicit confirmation.

Remaining findings and verification limits: [tracker](stability-2026-10.md).
