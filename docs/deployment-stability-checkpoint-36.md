# Checkpoint 36 — showing publication and stale ticket carts

2026-10-05. Suite 1.0.35. Selective Show Tickets CPT, Products, Frontend, loader, new Eligibility helper and Suite version. No schema/settings/historical reconciliation.

Shared status/profile/canonical pointer checks protect direct cart additions, Woo purchasability, classic and installed Store API checkout. Draft/private/pending/future/trashed/deleted showings, unpublished/missing products, obsolete duplicates and removed/profile-changed options fail closed; existing unrelated products/variations remain unaffected. Zero-priced configured tiers remain valid. Stale cart errors are deduplicated.

Public handle_add requires a published showing before touching the cart and never synchronizes, restores or publishes products. Admin saves remain the explicit synchronization path. Nonpublic sources cannot create published products. Withdrawal and deletion callbacks deactivate canonical/duplicate product identities to draft rather than deleting historical order references; failures are logged, while the independent eligibility gate still blocks unavailable showings.

Schedule generation preserves draft/pending/private status; published children require the CPT publish capability. Future sources generate drafts because no WordPress scheduled publication date is copied. Next-weekend duplication uses the same rule. Child insert errors/false stop generation, attempt removal of earlier new children, leave the source unmarked, and release recursion guard via finally. This is not transactional protection of all metadata/taxonomy writes or cleanup failures.

Medium ticket selection/admin impact. Time cutoff is deliberately not solved here: user confirmed sales until show ends; showing schema has no end field. A pending user choice covers editable default two-hour end versus mandatory explicit end. Existing start-based listing/product cleanup remains open T7. Arbitrary writers and concurrent publication/payment changes still need final-state coordination. No unauthorized dependency upgrade or real payment.

## Verification

- Explicit PHP 8.3 lint passes. Twenty-two standalone assertions against actual candidate/deployed classes cover hook wiring, canonical/unrelated/missing products, all nonpublic statuses, public endpoint early rejection without cart/product mutation, profile/tier changes, free tier, deduplicated stale-cart/direct-add errors and lifecycle deactivation.
- Five schedule groups cover authorized/unauthorized publication, nonpublic/future sources, synchronous save recursion, shared duplicate rule and failed batch cleanup. Shared duplicate rule uses a code contract plus actual helper; full duplicate admin workflow remains T16 work.
- Twelve installed WP/Woo/Store API checks before/after deployment: actual metadata, duplicate identity, purchasability, direct/stale cart gates, withdrawn/reinstated Store API validation, actual WP product-update callbacks and retained metadata. Physical fixture posts remain draft throughout; publication simulated by request-local get_post_status filter. No fixture publicly listed, order/payment/customer session save. Test-owned post/meta rows removed in finally. Other CLI checkout validators isolated; no CAPTCHA/security changes in production.
- Pricing regression groups and 12 installed-Woo pricing checks rerun after publication deployment. Live browser published October 30 ticket still adds at $20 and reaches checkout with $0 tototest total. No Place order; test coupon and ticket removed afterward.
- All six deployed SHA-256 values match local files: Suite `9a8aa2b323fd048d07ab03a1aa52996f610a53f8b944e3fe354ef8dc88bc660d`; loader `148279aa564d1c21782988451f056ad3f770922aa8d2326c8636578ed283f06c`; Eligibility `87638aa07d8eb2796de8d005bc0f4ac3ab4a0c1e17dde99f0b845841a9861bbf`; Products `c1edbace787f866b6ef01b2803d87a793ab44fd25f9caf3598c3b60776406414`; Frontend `d4bce3db64cbbf8057402a34a0666f9e5760eed85df8ffbf36ded23d2460ba34`; CPT `1c1559aebf70e1e2de870a748fa878694d106d598260bbd9c46bb2155ff1bb96`.

## Recovery

Three changed live class baselines normalized-content hashes match HEAD; only line endings differ. Original loader/Suite preserved from verified checkpoint 35. All five live originals compared byte-for-byte again immediately before replacement. New helper copied and loader wired before Frontend dependency replacement.

Server `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-ticket-publication/originals`. Archive copied before deployment to `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint36-rollback-files.tar.gz`; server/I SHA-256 `127daa0991400d0b0cb1f03585deb5a79a5712002d43df51038c367118480a98`. Cloud sync not asserted.

Restore old Frontend, Products and CPT class files first while Eligibility remains available; restore module loader and Suite originals next; then remove new Eligibility helper. No data rollback needed for deployment. No production showing withdrawn for testing.
