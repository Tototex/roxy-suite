# Social worker ownership checkpoint

Selective Store/Publisher deployment. Pre-deploy hashes matched checkpoint 12. Private originals retained under `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261003-054238/checkpoint13`. No schema changes.

- Same per-draft MySQL advisory lock used for scheduled publish, direct publish, queue/status transitions, stale recovery and removal. Zero wait avoids tying up a competing worker. Connection-lifetime ownership avoids expiring leases while a video worker is alive; explicit finally release handles success and exception.
- Queue status changes atomically before scheduling; rejected schedule restores expected prior state. Completed or already-queued rows cannot be claimed twice. SQL persistence/status guards include current lock ownership; status claims also require original connection ID. Reconnected/released workers cannot save late platform results.
- Check ownership before every provider POST/DELETE. Interrupted worker exception and abandoned stale job require Needs Review. Stale sweep re-reads the row under ownership; active owners are not disturbed. Recorded remote IDs survive recovery. No automatic approval of abandoned publication.
- Confirmed deletion is persisted before requesting the other platform.
- 33 isolated Publisher/HTTP/worker assertions pass against staged and deployed source. Includes active owner contention, queue scheduling failure, repeat cron delivery, worker exception, lost ownership, stale active worker, stale abandoned worker and refreshed row.
- 18 actual MySQL fixture checks pass against staged and deployed Store. Two distinct connections exercise nonblocking contention/release and wrong/released-connection writes. Connection-local temporary tables only; fixture connections close and temporary table drops in finally.
- Production full post-row serialized SHA-256 prefix before/after: `328fe6881273de264a615d7c0b77e06c` (128 bits shown). Live Social UI loads ten rows without fatal error. No real publish/removal or post-status action clicked; no actual provider POST/DELETE performed.

S1 remains In Progress because admin/AI mutations do not yet share ownership/snapshot controls, and ambiguous outcomes still require explicit human remote review. Video polling/maintenance pagination remains S10. Core database conversion is unrelated to this advisory-lock change and still awaits explicit approval.
