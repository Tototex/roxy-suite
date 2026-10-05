# Checkpoint 18 — guarded core storage conversion and offsite recovery

2026-10-05. User explicitly authorized freeing server space, using their Roxy Google Drive as recovery storage, converting core database tables, and live functional testing. No Suite production source was replaced in this checkpoint; live Suite remains 1.0.17, Inventory schema 0.1.15.

## Recovery and cleanup

- Original server inventory: 150 Updraft archive files, 5,233,487,206 bytes. Ninety copies already on `I:\My Drive\UpdraftPlus` matched server SHA-256 checksums. The other sixty were copied to `I:\My Drive\Roxy Site Recovery\2026-10-05\Updraft server archives` and all matched.
- Removed exactly 148 nonzero, verified server archives (5,233,487,206 bytes), not the Updraft directory, configuration, or logs. Recovery copies remain on I:. Two zero-byte September database archives were preserved as evidence; they are not usable backups.
- Fresh complete database backup plus maintenance-window full and four-table backups stored privately on the server and copied to `I:\My Drive\Roxy Site Recovery\2026-10-05\Database`. Both maintenance exports returned success, passed gzip integrity checks, and matched offsite SHA-256 checksums. Offsite compressed files also decompressed completely: full database 88,495,433 bytes; four-table export 98,359,534 bytes (different export formatting).
- Maintenance full SHA-256: `4b7e1f5d629b759d789a8a30241d69b0b7e16637732e2b6d75ec5fd1deba0a8c`.
- Four-table SHA-256: `892bfea5941d8fd499dfed49a53b379a2826a314e6b90043ec18cd6d9e9861ec`.
- Recovery manifests and verification receipts are on I:, not Git; no customer data, SQL dumps, credentials, or authorization tokens were committed.

## Conversion and data verification

Temporary HTTP and background CLI write pause installed only during maintenance. Own maintenance CLI explicitly exempted. Public browser confirmed the maintenance message; pause removed and maintenance mode deactivated after all checks.

| Table | Original rows | Before | After |
|---|---:|---|---|
| NnW_posts | 17,499 | MyISAM | InnoDB |
| NnW_postmeta | 707,231 | MyISAM | InnoDB |
| NnW_woocommerce_order_items | 25,125 | MyISAM | InnoDB |
| NnW_woocommerce_order_itemmeta | 205,397 | MyISAM | InnoDB |

Actual SQL backup restored into four isolated, explicitly owned tables. Every restored record matched the ordered primary-key baseline digest. Test copies converted to InnoDB and matched again. Live conversion required the successful test copies and verified offsite backup receipts; every original table matched before and after conversion. No other tables converted, no HPOS enabled, no unrelated plugin upgrades.

Actual live four-table transaction inserted private probes with no WordPress hooks, emails, payments, or public posts. A second connection could not see uncommitted probes. Rollback removed all four probes; complete original record digests still matched afterward. Unused auto-increment gaps are expected. Disposable restore-test tables were removed only after ownership-receipt validation.

CLI invocation note: WP-CLI parses a dash-prefixed approval argument as an option. Live approval was passed explicitly through `wp eval` with `$args=["convert-live","--approved-live-conversion"]`, requiring the same guarded script. The rejected `eval-file` invocation performed no conversion.

## Live checks and backup follow-through

- Real WooCommerce readers loaded recent orders/line quantities, products, published showings/metadata/permalinks, and active memberships both before and after conversion.
- Signed-in browser: Inventory, Social Posts, Event Booking, Requested Showings, Grosses, Arcade settings, and Ticket Ops loaded with expected headings and no fatal error. No Social publication, real vendor submission/cancellation, or Arcade enablement.
- Public browser: home, tickets, subscriptions/gifts, advertising, about, account, movie requests, and rentals loaded. Booking modal returned thirty time choices including disabled booked times; no booking submitted.
- Inventory history still contains four orders; saved itemized order and checklist render. Read-only evidence: 210 product rows, fourteen vendors, four orders, expected schema/submission identity column present. Ticket eligibility read-only sample: fifty valid-state records, forty-two paid tickets allowed, zero unexpected paid-ticket rejection.
- Authorized one-ticket `tototest` checkout: order 30646, total $0.00, ticket 30647 issued. Order canceled with explicit test notes; ticket state confirmed `cancelled`, not checked in. Only test cart item removed; browser confirms empty cart. Cart retention after order-received reproduced and remains an open issue, not a conversion fix.
- Existing stability regression suite passed against deployed source (capacity boundaries, bulk forms, DST schedule, release backoff, minimum PHP). Page loads and this checkout do not certify all remaining concurrency/provider/accounting cases.
- `updraft_delete_local` changed from 0 to 1, leaving schedules, Google Drive connection, and retention rules unchanged. Actual database-only backup `a039de905adc` succeeded, uploaded to Google Drive, and deleted its local archive successfully. Its 14,040,876-byte copy appeared in the user's original I: Updraft folder and fully decompressed (88,639,669 bytes). Existing backup-report email policy remained in effect; no Inventory order email sent.
- Account quota after cleanup/conversion: approximately 3,662 MB / 10,240 MB; Updraft directory approximately 3.9 MB. This is account quota, not disk filesystem free space.

## Remaining work

This removes the nontransactional core-storage prerequisite. It does not itself implement ticket/booking/member atomicity, repair historical reporting, or close all audit findings. Continue the findings tracker; investigate reproduced checkout cart retention and preserve the existing Elementor compatibility issue as unresolved. Advertising contracts/renewals/reporting remain deferred until stability work is complete.
