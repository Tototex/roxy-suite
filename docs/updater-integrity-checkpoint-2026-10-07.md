# Verified update archives

The installed updater now requires an exact versioned ZIP and matching release manifest before offering a Suite update. It verifies the downloaded archive's SHA-256 before WordPress unpacks it, rejects stale unverified cached packages, preserves other plugins' updates, and no longer forces plugin activation after installation. Missing/invalid manifests fail closed with a short retry backoff.

Main review caught and corrected two bulk-update ownership cases: an explicit unrelated plugin must win over a mixed plugin list, while a configured Suite archive at a different version must be rejected rather than bypassing verification.

## Evidence

- 34 isolated updater checks pass before/after installation, including malformed provider fields, manifest size/file limits, traversal/duplicate paths, tampered bytes, provided download paths, stale cache, outages and mixed updates.
- Five actual WordPress checks pass before/after, using an isolated updater namespace and HTTP-intercepted release/manifest. A real private ZIP is accepted, altered bytes rejected, other plugin updates preserved, and fixture ZIP/cache removed. No provider request, real update or installation occurred in this fixture.
- Existing stability regression passes with its release fixture updated to the new manifest contract. All 78 installed PHP files pass syntax checks after this checkpoint.
- Installed SHA-256 `2121e23a4b951f1f82266fccbdb8d5a428def6b6791665b037a20b2f7a25c760` matches local/candidate. Original `5d9087e4c6d132461b492faea5964d7dd5228ee08ed2bef6f52ebb25717d49dd` matched the verified I-drive rollback file before deployment: `I:\My Drive\Roxy Site Recovery\2026-10-07\arcade-and-updater\class-roxy-suite-updater.php`. Cloud sync is not asserted.

This verifies consistency with a manifest from the same GitHub release, not independent cryptographic publisher authentication. Per-file/source hashes are format-validated; the downloaded ZIP's whole-archive hash is checked. No new hosted release/tag/master push was made. Hosted release execution and the unexpected server-file review remain open under C2. Older releases without manifests will not be offered by this updater.
