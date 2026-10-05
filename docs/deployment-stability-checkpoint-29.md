# Checkpoint 29 — atomic Arcade personal-best saving

2026-10-05. Suite 1.0.28 / Arcade 0.4.5 selectively deployed: Arcade PHP and Suite version. No schema change, real score submission, prize subscription, email, financial transaction or attendance change.

## Correction

Replace the read-then-overwrite personal-best save with a single insert/duplicate-key update using the existing unique user/game index. Competing attempts cannot replace a greater score with a smaller one. The achievement timestamp is assigned before the score expression and only advances for an actual improvement; equal/lower attempts preserve the per-game leaderboard tie-break time. Zero remains a no-op rather than creating a new leaderboard entry.

A failed database write now returns `ok: false` / HTTP 503 rather than “Score saved!”; successful no-op writes keep the compatible success response. Nonce, login, allowed-game, score cap and rate-limit behavior are unchanged. The existing frontend displays the error message from this JSON response.

This does not establish that gameplay occurred, fix prize fulfillment, or serialize monthly awards. A1/A2 remain open and A3 is only partially addressed. Both live rewards and automatic fulfillment settings are zero and unchanged. No historical score normalization, season reset or ranking-rule change.

## Verification and audit

- Predeployment live Arcade and Suite files match Git c707307 after line-ending normalization. PHP lint and whitespace checks pass; deployed/local checksums match. Fresh WordPress reports Suite 1.0.28 and Arcade 0.4.5.
- 13 isolated SQL/REST contract checks pass against staged and deployed source: atomic maximum, timestamp assignment/strict improvement, no stale pre-read, unchanged-write success, zero no-op, database failure propagation, REST 503, successful response shape, nonce, game and guest guards.
- 11 actual MySQL checks pass against staged and deployed source using disposable tables cloned from the live score schema: insert, lower/equal preservation, timestamp advancement, zero, independent games, two independently connected concurrent high/low attempts in both submission orders, and actual missing-table write failure. Fixtures use aliased candidate classes to avoid testing an older already-loaded plugin. Failure logs contain no credentials.
- Exact original three score-row digest unchanged before/after both runs. Temporary tables removed; final read finds zero fixture tables. No production leaderboard record was written by the tests.
- Live signed-in Chrome smoke: Arcade page loads; Popcorn/Projector switching returns leaderboards, combined leader appears, Start shows “Playing…” and Restart resets without score submission. Served game/loader URLs carry version 0.4.5. This is not a real end-of-game score save, prize award or gameplay-integrity test. Score-saving verification is isolated/actual MySQL, not a synthetic winning score posted publicly.

## Recovery

Original files and staged fixtures: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-arcade-scores`, outside web root. A single SSH connection was used at a time; the interrupted connection was gone before reconnecting.

Rollback archive: `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint29-rollback-files.tar.gz`. Server/local SHA-256: `6f65e1a10b81cf2898bafbf8fab500aac1524d9b792d50d45fe811d72de36bf7`. Local copy verified; cloud synchronization is not asserted. Restore Arcade PHP and Suite version; no schema/data rollback required.

## Next

Correct and sandbox-test the Arcade subscription-product API, zero-valued prize line items and one-period expiry, then atomic award claims/recovery; do not enable rewards while client-submitted scores remain unverified. Also review the frontend's unguarded asynchronous leaderboard responses during rapid game switches (source-confirmed potential stale display; no production score corruption asserted). Broader module findings and deferred advertising opportunities remain open.
