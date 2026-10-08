# Checkpoint 54 — Explicit order-storage compatibility safeguard

2026-10-06. Suite 1.0.51. New Compatibility helper, Core diagnostic and Suite version deployed selectively.

Suite now declares WooCommerce custom order tables (HPOS) unsupported at WooCommerce's required compatibility-registration hook. Current ticket/booking transactions and membership reads still require post-based order storage. Core diagnostics visibly fail if HPOS is active and warn if storage cannot be verified; they never silently switch storage or migrate records. Full HPOS support remains C4 work, not a completion claim.

Five isolated checks pass before/after deployment; separate absent-Woo-utilities smoke passes. Actual installed WooCommerce utility signatures were inspected. After deployment its own feature registry lists Suite explicitly incompatible with `custom_order_tables`; actual storage diagnostic passes and storage option remains `no`. All ten live structural module checks pass. PHP 8.3 syntax checks pass. No order storage setting, order, membership, payment or provider state changed.

Server originals match normalized Git baselines and all three deployed hashes match candidates. Rollback originals/manifest: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-storage-compatibility/`. Manifest records the helper's original absence; restoring original Suite/Health removes its registration dependency.

Before deployment, archive contents and checksum verified at `I:\My Drive\Roxy Site Recovery\2026-10-06\roxy-checkpoint54-rollback.tar.gz`: 15,010 bytes, SHA-256 `78bb02eb8995b4d430bf5e7fcceca9c7f492c7d84d1c46fca30b574c57757857`. No cloud-sync claim. Prior sole SSH connection closed remotely before preparation; one replacement persistent connection established, with no parallel sessions or repeated retries.
