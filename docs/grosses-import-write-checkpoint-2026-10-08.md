# Grosses import-writer checkpoint — 2026-10-08

## Change

The Grosses import batch/file write helpers now report database failures rather than discarding them. Failed inserts return `0` even if `$wpdb->insert_id` still contains an earlier ID. Invalid parent batch/file IDs are rejected before attempting a write. Path/status/finalization updates return `false` on database error or an unconfirmable/missing row and `true` on success; unchanged existing rows are still successful.

Repository-wide tracing found no callers for these import write methods, so this hardens a dormant API without changing a live import workflow. The read-only latest-batch field remains in use by the admin overview.

## Risk

Low. Methods that previously returned `void` now return a boolean that existing callers may safely ignore; ID-returning methods retain their integer contract. The focused test exercises each writer against injected failures and successes, and checks single-attempt behavior and stale-ID protection.

## Verification

- `git diff --check` passes.
- PHP CLI is not installed in this workstation; hosted PHP 8.0–8.4 syntax matrix and PHP 8.3 cross-module suite are the execution check after push.
- No importer, database row, report, email, or production site was changed.
