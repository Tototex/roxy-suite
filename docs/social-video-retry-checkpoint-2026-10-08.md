# Social video retry checkpoint — 2026-10-08

## Change

Instagram video publishing no longer waits in one worker while polling the container. Container creation stores its ID and returns; a scheduled retry claims the failed draft, queues a worker, reads that same container once, and either publishes it when finished or schedules the next bounded status check. The retry count is carried in the scheduled event and the stored status message, so no new database column is required.

Automatic polling stops after five checks, on a terminal `ERROR`/`EXPIRED` result, when the provider outcome is ambiguous, or when scheduling/persisting retry state fails. An expired/terminal container ID is cleared only after a successful ownership-checked write. `PUBLISHED` without a locally recorded media ID requires manager review and is never blindly republished. Old failed “still processing” rows can receive one final bounded status check; stale/duplicate retry events must match the expected persisted attempt.

## Verification

- Isolated publisher regressions cover initial container creation, retry reuse and finish/publish, expired-container clearing, already-published ambiguity, maximum poll exhaustion, legacy-row recovery, and ownership behavior.
- Hosted workflow [37762362751](https://github.com/Tototex/roxy-suite/actions/runs/37762362751) passes PHP syntax on 8.0–8.4, the full configured isolated regression suite on PHP 8.3, release-manifest checks, and deterministic runtime archive verification.
- No Meta provider call was made against a live account; the isolated test uses a fake HTTP transport. No live Social post or row was changed.

## Deployment boundary and remaining work

The change is committed and pushed to `stability/audit-2026-10` at `63fc82c`; it is not deployed. Production rollout needs the normal selective deployment and backup procedure. After deployment, verify an existing private test video/container only if one is available and explicitly safe to use; do not publish a public post as a smoke test. Repeated login optimization and remaining transport paths are still tracked under S10.
