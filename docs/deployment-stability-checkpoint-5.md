# Membership deployment checkpoint

Selective production files: Member Check module and Members Dashboard. Suite remains 1.0.17. Live originals matched HEAD after CRLF normalization; rollback copies and member-scan SQL backup are private under `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261003-054238/checkpoint5`.

Evidence:

- Ten standalone lookup/admission/navigation checks; twelve actual MySQL assertions against connection-local temporary log table; two export checks covering 1,201 rows in 500/500/201 batches. Tests rerun against deployed source. No real admissions recorded.
- Nine actual schema assertions against uniquely named disposable permanent table: legacy columns upgraded, historical row retained, current schema recognized, missing table recovered. Fixture option interception prevents real schema-option edits; disposable table dropped in finally. This is separate from the temporary aggregation fixture, which bypasses its table-existence guard.
- Deployment records schema version 1. All 128 actual scan rows retain SHA256 `aa27354e4a293e50ebd503291d035aa3b7db11457d0102ebe12be4f78fa02501` before/after.
- Live dashboard: 61 active seats, 38 subscription records, monthly revenue $956.55, zero current-month visits, 36 missing photos, 10 trade/comp records, unchanged. Live unified log loads without error notice, preserves filter tab and contains protected admin-post CSV link.
- CSV route is before admin HTML, nonce/access protected, fixed-boundary keyset streamed, formula-escaped, and includes source/showing/quantity. Actual browser file-download contents not verified; fixture verifies stream contents.

Remaining: member admission/undo ledger reconciliation, reserved-ticket/log atomicity, concurrent retry, requested-versus-changed ticket counts, dashboard query-level pagination. Four core MyISAM table conversions require user approval; none converted in this checkpoint.
