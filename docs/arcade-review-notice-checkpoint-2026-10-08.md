# Arcade review-notice deduplication checkpoint

## Scope

Prevent a scheduled or manually re-run Arcade monthly worker from sending duplicate manager review messages for the same month, candidate user, and score. This does not award a prize, alter reward enablement, or change the review requirement.

## Change

- Build a stable notification identity from the validated month, positive user ID, and nonnegative candidate score.
- Atomically reserve that identity with WordPress `add_option()` before calling the mail transport. The unique option name makes concurrent workers contend for one claim.
- Record a successful send as `sent`. A false return or thrown transport error becomes `uncertain`; the reserved claim prevents automatic retries because delivery may have occurred despite the reported failure.
- If the claim cannot be recorded, do not send. The review candidate remains available in Arcade settings for manual inspection.

## Verification

The isolated Arcade review-gate regression now checks that two worker runs send only one message for the same candidate and that an uncertain first send is not attempted again. Run the PHP 8.0–8.4 syntax matrix and PHP 8.3 cross-module regression suite after pushing this checkpoint. This branch is not deployed; no production email or prize action was performed.

## Remaining

Legacy award-history reconciliation and broader prize creation crash/reconnect fault coverage remain open. Rewards remain behind manager review and should stay disabled until the overall audit is complete.
