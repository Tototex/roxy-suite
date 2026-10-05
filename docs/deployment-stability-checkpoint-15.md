# Social approval snapshot and background-race checkpoint

Selectively deployed Store, Campaigns, AI, Hangar and Admin. Pre-deploy hashes matched current Git checkpoint. Originals retained under `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261003-054238/checkpoint15`. Campaign final manual-edit guard deployed after its additional fixture passed. No schema, provider credential or public-post changes.

## Behavior

- Complete row revision/status checked under the same connection-owned worker lock before saving background text/media. A manager edit or approval invalidates a delayed result, including edits within one second. Approval links and Save forms carry the revision of the actually displayed row.
- Editing an approved draft revokes approval. Completed manual captions use `ai_status=manual`; pending/generator work cannot rewrite or auto-approve them, including when AI is disabled. Existing ready/manual captions are preserved during campaign regeneration.
- AI text and readiness save together. Invalid/failed AI goes to Needs Review, never Ready/automatic approval. Successful stale AI work only queues a fresh snapshot when the current row is still pending/unapproved and has no error/publication evidence. Both completion orders tested: generated-AI-only changes may safely rebase a pending media assignment; media-first completion retries AI against fresh context. Manual edits never qualify for that media rebase.
- Auto-approval limited to new Draft rows, assigned Hangar media and usable public media, appropriate AI readiness, with no last error or recorded remote/container IDs. Needs Review is excluded. Explicit review approval has additional client confirmation; server-confirmed POST strengthening remains S1 work.
- Draft/status/delete/clear-media mutations cannot race a publisher. Posted/partially published rows cannot have their payload edited. UI disables frozen Save buttons and reports stale save/status failures. Read-maintenance does not modify approved/posted payloads.
- A newly imported, never-assigned upload is cleaned up on stale assignment; existing selected media is not touched. Old selected uploads are intentionally retained on replace/clear/delete until reference-aware S7 cleanup is implemented; this trades temporary disk retention for avoiding deletion of shared media. No existing production upload deleted by this checkpoint.
- Audit caught numeric database values returning as strings: durable read-back now compares scalar values canonically while retaining strict NULL checking. Without that correction, an actually saved numeric attachment ID could falsely be treated as failed. Assignment fixtures pass after repair.

## Verification

- Five changed sources linted. 32 actual Store/Campaign snapshot SQL checks pass against staged and deployed code using a connection-local temporary clone of the Social table. Two connections test worker contention; real production post rows unchanged.
- Twelve isolated actual AI-worker checks pass: success, HTTP/network/JSON/empty response, approval/edit during work, late failure after approval, media-first retry, completed/reviewed/wrong-campaign skips. No real AI provider used.
- Existing 33 Publisher and 18 actual publish Store SQL checks pass after the shared helper changes. Twelve broader standalone regression suites rerun against deployed source with exit zero: publisher, Social secrets/account, Square/Grosses secrets, core stability, inventory integrity/decision, ticket eligibility, Will Call, member Door Mode and requested-showing privacy.
- Actual staged/deployed renderer checks: ten row revisions, ten frozen Save controls, stale-save error message and unchanged original rows.
- Live browser functional check used private Facebook-only, media-free, unapproved fixture 186, dated 2099-12-31. First tab saves version one and displays success. Second tab with old revision attempts a stale save; server rejects it, displays error and preserves version one. State stays Draft, manual caption. No Approve/Post/Remove clicked.
- Fixture 186 removed after validating marker/date/status/absence of remote IDs, with final row backup retained privately as `checkpoint15/private-ui-draft-186.json` (0600). Browser confirms fixture absent. Original full Social-row SHA-256 prefix remains `328fe6881273de264a615d7c0b77e06c`.
- Live Social page loads ten original rows; public Tickets page loads normally without fatal error. No new checkout was necessary because these changes do not alter checkout. Existing Elementor compatibility issue remains separately tracked; this is not a blanket JavaScript-error-free claim.

S4 resolved for current worker/admin paths. S1 review POST confirmation, S5 factual showtimes, S7 reference-aware ownership/retention and S10 bounded jobs/pagination remain open. Core MyISAM conversion and already-admitted refund attendance policy still await explicit user direction. No real public posts, vendor orders, reports or emails sent by these tests.
