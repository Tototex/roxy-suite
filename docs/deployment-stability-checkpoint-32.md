# Checkpoint 32 — private, isolated Grosses email CSVs

2026-10-05. Suite 1.0.31; selective Reporter and Suite version deployment. No database/schema/settings changes, reporting pulls, real emails, orders or payments.

## Correction and risk

- G10, email CSV portion: date/title-based files in public uploads could collide between simultaneous sends, overwrite attachments and delete another send's file. Each daily/live attachment now has its own cryptographically random, exclusively created 0700 directory under resolved system temporary storage, outside ABSPATH/WP_CONTENT_DIR. Live host storage resolves to `/tmp`. Files are exclusively created and protected with 0600 permissions.
- Friendly CSV filenames retained; dates/titles cannot introduce path separators, and long titles keep the `.csv` extension. Columns, paid-ticket counts, gross formatting, concessions options, recipients, subjects and bodies unchanged.
- Checked row writes, flush and close reject incomplete attachments. Writer exceptions clean up; email construction, absent recipients, false mail results and provider exceptions all pass through finally cleanup. A shutdown callback cleans unsent attachments. Cleanup accepts only exact files registered by the current request, is idempotent and cannot remove another request's directory. Cleanup failures are logged and retried at shutdown.
- Low-to-moderate impact: attachment storage/lifecycle only. Unusable or website-local system temporary storage fails closed rather than exposing reports. No change to mail delivery providers or recipient policy. Existing mail behavior already requires attachments to be consumed synchronously before wp_mail returns; asynchronous attachment queueing is not newly supported.
- Abrupt process kill/host failure may leave a private temporary file; shutdown cleanup is not guaranteed after SIGKILL. No broad old-file sweep added. Read-only check found no `.csv` files directly in the old uploads/roxy-grosses folder; nothing was deleted there.
- Workbook/snapshot generation, advertiser workbooks and template uploads have different lifecycles and remain open under G10. This checkpoint does not certify their privacy/concurrency or fix the whole finding.

## Verification

- PHP 8.3 lint passes for Reporter/Suite; exact final deployed Reporter SHA-256 `87d7070b09127ece8a648edb6d4955d008b731934bd4f3d4289b1ec6c7118233`, Suite `27484f9e302239ed3922ff48e54975970aac9862436641419f31b8c444e0797a`. Fresh WordPress confirms 1.0.31. Host default CLI is now PHP 8.5; explicit PHP 8.3 used for standalone compatibility checks.
- `tests/grosses-attachments-regression.php`: 31 synthetic checks against candidate and deployed source. Overlapping same-date lifetimes, private permissions/location, independent cleanup, traversal-like dates, long names, quoted/multiline titles, daily totals, live concessions on/off, write/flush failures, writer exceptions, permission failure, unsafe/unavailable temporary root, no recipient, mail success/false/throw, unowned file refusal and separate-process shutdown cleanup.
- `tests/grosses-attachments-wordpress.php`: actual WordPress Settings/body/recipient construction and attachment handling before/after deployment. pre_wp_mail intercept returns success before delivery, captures only synthetic attachment contents, verifies privacy and removal. No real email or report-history write. This verifies integration to the mail boundary, not SMTP delivery.
- 15 existing CSV handler checks pass against changed Reporter: all datasets/default export 5,001 rows, escaping/currency preserved, denial/bad nonce/late failure return no partial CSV.
- Existing 23 stability, 37 Square-response and nine Grosses secrets/dashboard tests pass against deployed Suite.
- Actual reporting SELECT parity passes: movies 2,029, live 66, rentals 19, legacy 815. No financial source mutation.
- Chrome: Movies totals remain $487,229.50 ticket gross / $370,252.61 concessions; Live Shows totals $25,152.50 / $33,087.48. Saved Reports opens; report #81 preview retains three rows, 21 paid tickets and $164.00. No send, pull or edit clicked. No captured admin console errors at saved-report check.
- Public homepage renders current movie/event, ticket-price/showtime headings. Console shows the previously recorded Elementor Pro/core `softDeprecated` compatibility error and a view-transition abort; frontend is not certified error-free. No dependency upgrades attempted. No fresh checkout/booking/admission test warranted by attachment-only changes.

## Recovery

Live originals matched prior Git source exactly before deployment; second audit adjustment verified the intermediate deployed hash before replacement. Server originals/stage outside web root: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-report-attachments`.

Before any deployment, original Reporter/Suite archived and copied to `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint32-rollback-files.tar.gz`. Server/local SHA-256 both `b3a004272641a23ae89d53cf7b9fdf9bbd9b7d2a1826db6b47a16c258d624dc0`. Archive entries `reporter-original.php` and `suite-original.php` restore their respective live files. No data rollback required. Local I: copy verified; cloud synchronization not asserted.

Only one persistent SSH connection used. Regression fixtures staged outside the public website, not deployed as publicly accessible scripts.
