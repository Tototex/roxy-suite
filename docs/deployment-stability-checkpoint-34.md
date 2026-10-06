# Checkpoint 34 — locked reservations and revisioned paid changes

2026-10-05 local date. Suite 1.0.33. Selective Event Booking repository/availability/Woo/admin/calendar handler plus new reservation helper. No schema/settings changes, real bookings, customer payments, emails or staffing-provider requests.

## Corrections and impact

- B2: one connection-owned room lock serializes managed repository creation, schedule/status edits and calendar-block writes. InnoDB transaction, checked engine/outer-transaction probe, guarded INSERT/UPDATE/DELETE and readback prevent unverified writes. Rechecks final occupied interval under the lock; payment order identity prevents duplicate reservations. Metadata-only pizza/Sling changes retain existing occupancy without a needless conflict recheck. Manual blocks cannot silently overlap accepted reservations; overlapping blocks themselves remain valid unioned exclusions.
- Failed availability reads are WP_Error, not empty lists. Public slot checks fail closed; calendar handler returns 503; admin lists show errors. Payment completion distinguishes unreadable storage from actual conflicts and requests manager review without an inappropriate automatic conflict refund. Repository creation losing an actual availability race uses the checked booking-item refund path.
- Old admin forms include a revision and cannot overwrite a newer booking. Missing-row updates/deletes cannot report success; unchanged valid updates still succeed.
- B3: new adjustment checkout stores version 1 plus a hash of meaningful booking state. At payment completion, validate paid status, booking status, matching user/email, edit cutoff, revision and final room availability. Apply changes and record adjustment order identity within one room transaction. Replay is a no-op, including notes/email/reminders. Multiple adjustments to the same booking in one order require review. No-charge/invoice edits also check their snapshot under the lock.
- Reminder/Sling work and success notes/email happen after commit. Rejected paid changes remain unapplied, with a failed-adjustment order note and internal manager-review notice directing payment/refund reconciliation; no blind refund or auto-retry. Legacy unversioned pending adjustments fail safely. Live SELECT found one historical adjustment line, zero legacy unapplied paid and zero legacy pending lines; no old data rewritten.
- Paid adjustment reconstruction now retains special-charge label/amount. An existing primary booking always prevents a repeated callback from entering the primary creation/conflict-refund path, even when it also processed an adjustment.
- Moderate/high booking-path impact. Cross-module Show Tickets/Requested Showings writers do not yet use this room lock; cancellation versus provider processing still needs coordinated state handling. These remain B2 work. Existing unrelated/admin/provider database transactions are refused rather than implicitly committed. No distributed gateway transaction or arbitrary-writer guarantee claimed. Post-commit notification failure is not a resumable outbox.

## Evidence

- Seven changed deployed PHP files lint with explicit PHP 8.3; fresh WordPress loads Suite 1.0.33 and reservation guard.
- `tests/booking-reservations-mysql.php`: 44 checks before and after deployment, loading actual candidate/deployed functions under fixture names. Two disposable copies of booking/block schemas only; actual independent MySQL connection tests contention. Query filter releases ownership at the exact guarded UPDATE; no mutation follows. Invalid SQL after a prior update rolls everything back. Missing-table read checks test public false/calendar error/strict payment error. External transaction is not committed.
- Real repository/availability/adjustment logic checks duplicate orders, overlaps, unchanged/missing updates, invalid intervals, metadata edits, block creation/edit/deletion, stale admin/no-charge forms, wrong owner, unpaid/legacy/stale/conflicting/cancelled/cutoff adjustments, special charges, atomic applied identity and replay. Synthetic order objects and fake mail/reminder boundaries prevent customer/provider work. This is not a new card/invoice browser checkout or SMTP/Sling integration test.
- Original booking/block row digests identical after each run. Only test-owned tables dropped; no production row deleted. Fixture scripts staged outside the public website.
- Existing 23 stability, 20 booking refund/cancellation and six booking asset checks pass. Installed Woo fake-gateway refund fixture rerun separately; no real gateway contacted. Scope is regression of touched paths, not all remaining Suite findings.
- Live browser calendar shows real Showing/Reserved blocks. Book now opens October 8 choices with booked times disabled. Modal cancelled without cart submission. Admin list and existing booking #48 edit form load normally; no Save/Cancel/Pizza controls submitted. Existing Elementor compatibility issue remains separately open; no dependency update in this checkpoint.

## Recovery

All live baselines compared before replacement. Availability differed from Git only by line endings; normalized full text matched, with no production customization overwritten.

Server originals/stage: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-booking-reservations`. Before deployment, archive copied to `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint34-rollback-files.tar.gz`; server/local SHA-256 `1dbc69909886e4a300051052f5513f7e512647e702d580719992d6c2fda8dea1`. Local copy verified, cloud sync not asserted. Original archive paths are `originals/{repository,availability,shortcode,admin-pages,woo,roxy-suite}.php`. Restore the five booking files and Suite to their corresponding live paths; remove the new reservations.php only after restoring repository/Woo to their previous include-free versions. No data rollback needed.

Final deployed SHA-256:

- reservations.php: `db3215322c217b8292a1e343064c38fb455d10b2143201e5fa06389850356042`
- repository.php: `28e72bf05eb5bf76479dc025dbfd50c6b23ce317adfe8b4defd21387443a0d1d`
- availability.php: `b686d00b2bfbf6ee3b722db2bf0625a3c8492c10bea7567139e7d37aeb4a5704`
- woo.php: `e6c45c286cc83c7a6e0af647ef210bf813576889d76616c706418a6a85bb2e48`
- shortcode.php: `62b05c9bfd8fe9046c806d57ed845641059f904d734d89cfa0da88fad52190b9`
- admin-pages.php: `2d3e61a02c52c01970a98a58a079eeedf92fdd955402dc43cc30f1ba5ee158cb`
- Suite: `4a4dcd1e867a55e3e13b4a24646370ee2142688a28386d273a76d3696ab4abcf`
