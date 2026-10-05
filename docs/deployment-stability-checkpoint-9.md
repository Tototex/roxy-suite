# Social credential checkpoint

Selective production files: new Social Secrets helper, module bootstrap, Hangar, Meta and Admin. Four existing files matched HEAD; private originals at `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261003-054238/checkpoint9`. Helper deployed first, bootstrap next, dependent classes afterward. Rollback dependent classes before bootstrap. Unreferenced helper may remain safely when rolling back.

- New saves use `roxy:v2:` AES-256-GCM with random 12-byte nonce, 16-byte tag and fixed associated context. Old CBC is read compatibly without rewriting credentials; unknown envelope/corrupt GCM/wrong key fails closed.
- Hangar checks encryption before updating user/password; empty/unavailable encryption cannot replace saved values. Meta encrypts supplied secrets before updating settings. OAuth/verification encryption failure is explicit and cannot save blank ciphertext; unreadable app secret blocks OAuth request. Hangar unreadable password blocks login request. Reconnect notices expose unreadable saved values without erasing them.
- Fifteen isolated real-OpenSSL checks plus two with `openssl_encrypt` disabled pass; no real WP options/salts loaded by fixture. New ciphertext, tampered tag/body, wrong salt, malformed payload, legacy read, save/read and salt-rotation notice states tested.
- Live original credentials remain readable through new classes. Before/after five-option aggregate hash equals `a77970e5c7f1da6b715812fb0f8646a5dab468c062935bfe83690a28ed6b2d96`; no real password/token rewritten. Live Meta page shows credentials saved; Hangar page loads without error notice. No provider authorization, Verify accounts, credential Save or publish clicked.

Remaining S9: legacy CBC migration and Grosses plaintext fallback/migration. Database-write atomicity for multi-option saves remains a separate concern. No full S9 resolution claimed.
