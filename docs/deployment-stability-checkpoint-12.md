# Social provider response and partial-success checkpoint

Selectively deployed Publisher, Store and Admin. Live originals matched HEAD SHA-256 before replacement; originals retained under `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261003-054238/checkpoint12`. No schema or credential changes.

- Require HTTP 2xx, readable JSON and valid provider ID before recording publication. Unknown network/server/missing-ID outcomes enter Needs Review rather than ordinary retry. Facebook ID persists before Instagram begins; Instagram container survives until final publication ID persists. Failure to persist an accepted platform ID stops the next platform.
- Remote deletion must explicitly confirm boolean success. Partial removal clears only confirmed IDs; retry acts on remaining IDs. Failed/Needs Review rows with recorded IDs are removable. Missing IDs cannot falsely mark Removed. UI reports real failure and exposes Remove live post on posted rows.
- Selected-platform credential checks; Facebook-only posts do not require Instagram and vice versa.
- Store write results read back durable values: unchanged same-second updates succeed, missing rows and actual SQL errors fail closed. This is not an atomic ownership claim.
- Twenty-three isolated real Publisher checks pass against staged and deployed code: invalid/missing IDs, malformed JSON, timeouts, HTTP failures, false removal success, immediate ID persistence, failed persistence, platform-specific configuration, known-ID retry suppression and partial removal.
- Eight actual Store/MySQL checks pass against staged and deployed source using connection-local temporary tables only. First fixture attempt lacked a global database binding and failed before table creation; corrected fixture rerun passes. No production post altered by fixtures.
- Browser skill live check: Social Posts page loads without fatal error; ten rows, nine Remove live post links, one Retry remove. Production status totals unchanged: nine Posted and one Failed. No real provider POST/DELETE, approval or removal clicked.

S1 remains open for exclusive worker claims, stale worker reconciliation, resumable video processing and concurrent removal. S2 still includes Grosses Square response validation. No blanket full-suite completion claimed.
