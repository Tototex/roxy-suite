# Module governance checkpoint — 2026-10-07

## Outcome

Module flags now have one allow-listed source of truth. Missing legacy flags are
migrated once to the existing enabled baseline, explicit disabled values remain
disabled, unknown/future keys cannot activate code, and malformed or unverifiable
settings fail closed for that request without silently replacing the saved data.
Toggle writes are serialized with a database advisory lock, reread after lock
acquisition, protected from stale WordPress option-cache data, and confirmed by
readback. Capability grants remain limited to the existing administrator and
shop-manager roles and now run through the activation lifecycle instead of every
request, so a deliberate capability revocation is not silently undone on `init`.

## Verification

- Governance regression passed all isolated migration, cache, lock contention,
  failed-write, corrupt-data, toggle, role, and activation-lifecycle checks.
- PHP lint passed for the governance class and plugin bootstrap.
- Before deployment, the live option contained the historical six flags and the
  live roles were administrator=yes, shop_manager=yes, editor=no.
- After deployment, the option was safely normalized to all nine known module
  flags, preserving the prior six enabled modules and adding the existing
  inventory/requested-showings/social baseline. Administrator and shop-manager
  access remained intact; unknown modules remained disabled.
- The live read-only health suite continued to report Requested Showings,
  Grosses, Will Call, and Arcade as healthy. No orders, emails, payments, or
  scheduled callbacks were invoked.

## Backup and rollback

The pre-deployment bootstrap file was archived at:

`I:\My Drive\Roxy Site Recovery\2026-10-07\module-governance\roxy-c6-live-20261007.tar.gz`

Verified SHA-256:
`8883e02bdd0131110c8fb37e68456fe7f86b1b83328b85da669d58b953625835`.

## Boundary

This closes the fail-open flag/grant behavior without introducing a new role
matrix. Separate integration-admin permissions remain a future design choice.
