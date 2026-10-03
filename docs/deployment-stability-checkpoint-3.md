# Inventory integrity deployment checkpoint

Branch: stability/audit-2026-10. Suite remains 1.0.17; inventory schema 0.1.15.

Changed production files: roxy-suite.php and Inventory Store, Square, Admin. Before overwrite, all four live SHA-256 values matched committed HEAD. Only these files deployed; no production Git reset/pull or full plugin replacement.

Private rollback directory: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261003-054238/checkpoint3`. Contains original four files, `inventory-before.sql` (four Inventory tables, 65,587 bytes), and before/after row evidence. Backup is server-private, not in Git or public uploads.

Initial upgrade failure was detected by post-deploy bootstrap verification. Files immediately restored; products/vendors/orders hashes matched baseline. Fix: explicit additive column/index migration, verified repeatable against connection-local temporary tables; upgrade exceptions isolated from global checkout bootstrap. Corrected deployment passed.

Before and immediately after successful schema upgrade:

| Data | Rows | SHA-256 |
|---|---:|---|
| Products | 210 | 3f0bfaff72882c8f0f5cf72cba44fc18085bb5d0539d3854f626a900d8907b8b |
| Vendors | 14 | 771f9d4f6c2092495f06e91cd3cf6ae03b27953109eafccddd75ea244093c9a26 |
| Orders, original columns | 4 | 8881d8e479717e1183143d2868bc4b6fee96521dc6a98e6ad754c64e9dd947364 |

New nullable unique submission_key does not alter old orders. Schema option only changes 0.1.14 → 0.1.15. Existing tables verified InnoDB. Subsequent unchanged Save All legitimately updates product timestamps; all 858 editable values verified unchanged.

Verification: PHP lint, 14 integrity assertions, 14 actual temporary-MySQL assertions, seven standalone regression suites against deployed source, live unchanged Products Save All, clickable locked Pepsi review (22 items), searchable history, itemized list/checklist and Cancel controls rendered without activating real order mutations. No Square/provider writes, emails, or real vendor Submit/Cancel performed by this checkpoint.

Rollback: restore original four files; leave nullable additive schema in place (old code ignores it). Do not import SQL over live activity blindly; SQL is recovery evidence requiring a fresh scope review. Full disaster restore has not been rehearsed.

Remaining limitations: stock deltas are not a receipt ledger; lost database connection lifecycle/logging and legacy save failures need additional review. Ticket/payment/publishing concurrency is not certified by Inventory lock tests.
