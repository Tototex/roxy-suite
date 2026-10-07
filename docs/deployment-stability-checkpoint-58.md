# Checkpoint 58 — Durable pledge save receipts and uncertainty gate

2026-10-06. Suite 1.0.55 selectively deployed; readback hashes match.

A request/user uncertainty marker now commits before each backing INSERT. If the insert, acknowledgement, exact row readback or receipt commit cannot be confirmed, another pledge for that user/request is blocked for reconciliation rather than silently duplicated. The marker is removed only in the transaction committing a checked completion receipt. Exact normalized payload replay follows the existing five-minute policy independently of WordPress transients, verifies the saved backing and returns its ID; expired successful receipts permit a new deliberate identical submission. Corrupt/future-dated receipts fail closed. No provider call occurs in these transactions.

Uncertainty markers deliberately do not expire or self-clear. Manager recovery must inspect actual backing rows and payment/conversion evidence before clearing a gate; no blind retry/automatic recharging has been added. Historical backings remain unchanged and receive no guessed receipts.

## Verification

Before/after: 60 actual handler/repository/conversion isolated assertions, 59 actual payment/creation/pledge-helper isolated assertions, 12 temporary-MySQL INSERT/readback assertions (membership/request eligibility and receipt gate virtualized), and 12 actual private-request SQL creation/receipt assertions pass. The actual receipt fixture uses a virtual backing ID to exercise metadata commit, not an actual backing INSERT. Original records unchanged and owned fixture/table removed. The simulated commit-then-lost-acknowledgement case saves one row then blocks identical/changed retries; expired, corrupt, missing-row and missing-transient receipt cases are covered. No real provider request, payment, customer email, showing approval or vendor order was invoked. Four changed files pass PHP 8.3 lint; all ten installed modules pass structural diagnostics.

Broader installed 1.0.54 pass immediately before this deployment: all 77 PHP sources pass PHP 8.3 lint; 48 staged isolated PHP programs pass against installed source. Initial broad batch used a wrong Sales file path (47 passed/one invocation failed); corrected Sales path rerun passes 16 assertions. This is not full authenticated browser, physical Door or paid-provider certification. Public Requested Showings page reloads unchanged after 1.0.54; 1.0.55 post-deploy public smoke remains recorded separately when checked.

Initial replay fixture parsed spaced SQL NULL as a literal string, making readback appear mismatched. Corrected parser and reran; actual temporary-MySQL readback also passes. Smaller-model read-only review found no immediate duplicate/recovery defect after those corrections. R4 still requires complete provider reconciliation and installed lifecycle/publication coordination. R3 agreement helper has 21 isolated passing cases but is deliberately unintegrated/undeployed pending complete quote, currency and sponsorship-tax design.

## Recovery

Three originals and new-helper absence manifest: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261006-pledge-replay/`.

Verified before deployment: `I:\My Drive\Roxy Site Recovery\2026-10-06\roxy-checkpoint58-rollback.tar.gz`, 8,659 bytes, SHA-256 `19bf1bd559d0efe8431797dd5ca6bcbcafd0a28706f5c88829be75eb25178dc5`. No cloud-sync claim, schema migration, historical rewrite or automatic receipt cleanup.
