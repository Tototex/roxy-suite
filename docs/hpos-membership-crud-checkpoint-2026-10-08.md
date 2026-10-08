# HPOS membership CRUD checkpoint — 2026-10-08

## Scope

Partial remediation for audit finding C4. Member lookup no longer joins subscription post and postmeta tables directly. It pages Woo Subscriptions API results in stable ID-descending batches of 100, searches subscription billing fields and user identity fields, and returns no partial matches if a query page fails or is inconsistent. Member photo and trade/comp metadata reads and writes now use subscription CRUD objects, including customer uploads and the subscription photo handler. The member dashboard no longer primes subscription metadata through the post-only metadata cache.

## Verification

- `git diff --check` passes locally.
- Isolated regression coverage exercises multi-page member search, supported statuses, query failure, and the large subscription dashboard parity fixture.
- Full PHP syntax and isolated cross-module suite are delegated to the repository's GitHub Actions workflow; results will be recorded here after completion.

## Deliberate limits

This does not certify HPOS. The current post-edit subscription metabox and member dashboard's subscription edit link still target legacy admin screens and require a separate UI/backend review. The existing WooCommerce compatibility declaration continues to report HPOS as unsupported. Do not enable HPOS based on this checkpoint. No production deployment or data migration was performed.
