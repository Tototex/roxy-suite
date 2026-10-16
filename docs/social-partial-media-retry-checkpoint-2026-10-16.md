# Social partial-media retry checkpoint — 2026-10-16

## Finding and safeguard

The Social publisher could upload one or more unpublished Facebook album photos or Instagram carousel children, then receive a later HTTP 400 and return an ordinary failure. Because successful child IDs existed only in local memory, retry could create duplicate unpublished provider objects. Parent album/carousel creation failures had the same problem. Those partial outcomes now require `needs_review`, preventing an ordinary retry from uploading the children again. The review state does not claim duplicate public posts occurred.

Facebook Story start responses now persist a returned video ID before validating the upload URL. Missing URLs and unexpected upload hosts retain that pending identity and require review rather than permitting a blind second start request.

## Verification

- `tests/social-publisher-regression.php` verifies child-upload and parent-creation failures for both Facebook albums and Instagram carousels enter review and cannot be blindly retried.
- `tests/social-facebook-stories-regression.php` verifies an incomplete Story-start response retains its ID and a retry makes no additional Meta request.
- Hosted run [38878796995](https://github.com/Tototex/roxy-suite/actions/runs/38878796995) passes all eight jobs, including PHP 8.0–8.4 syntax, the full PHP 8.3 regression suite, private MySQL, browser, and WordPress/Woo integration jobs. No Meta requests were made by these fixtures.
- Changes are committed and pushed to `stability/audit-2026-10` as `8ce44a2` and `72bd8a9`.

## Live deployment boundary

No live files were changed. The installed Publisher and Facebook Stories files contain live-only edits not present in Git. The exact originals were copied to `I:\My Drive\Roxy Site Recovery\2026-10-16\social-partial-upload-retry\` and `/home1/anrvxfmy/deploy-backups/roxy-suite-20261016-social-partial-upload-retry/`; the I: copies were hash-verified against the production originals. The live-only changes include removing publish-attempt-token checks and making Facebook Story processing run for every video regardless of the Story checkbox. Deployment is paused pending the owner’s decision on whether those behaviors should be preserved or replaced with the branch version. Do not overwrite the live files wholesale.
