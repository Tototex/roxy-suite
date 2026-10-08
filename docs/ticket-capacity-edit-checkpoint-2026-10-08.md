# Ticket capacity-edit checkpoint — 2026-10-08

## Change

Showing capacity is now saved under the same showing-scoped seat lock used by checkout, seat reservations, and walk-up admission. While holding that lock, the save path reads fresh Woo reservations and member walk-up admissions. A new limit below committed occupancy is rejected; malformed/non-whole input and unavailable lock/storage/readback also fail closed. The whole showing form submission is withheld on failure, so unrelated metadata from that submission is not partially saved. Capacity equal to occupancy remains valid, and zero continues to mean no seats when configured. An omitted field preserves the current saved capacity (or uses the configured default for a new showing).

## Verification

- The ticket-publication regression checks shared-lock use, capacity reduction below paid/held plus walk-up occupancy, acceptance at exact occupancy, malformed numeric/array input, lock failure, and no partial form save.
- Ticket product synchronization clears the showing cache and rechecks readiness after taking the same showing-scoped lease used by seat claims, then verifies ownership before changing products or canonical product mappings. A failed lease schedules up to five one-minute retries; exceptions, retry-scheduling failure, cache-stale unpublication, overlapping sync cleanup, and recovery after a simulated ownership loss are covered by regression tests.
- The existing ticket-publication regression group passes, including duration, publication, product identity, stale-cart, and bounded cleanup checks.
- No live showing, capacity, order, or admission was changed.

## Remaining boundary

The lock coordinates Roxy-managed writers only. WordPress product CRUD spans multiple SQL statements; if the database connection is lost mid-update, an earlier product write may persist before ownership loss is detected. A bounded retry attempts to reconcile the product set, but those writes are not one atomic transaction. A third-party integration that writes Woo order tables or ticket metadata directly without invoking supported hooks can also bypass the lock. Provider/Blocks/guest runtime coverage and indexed ledger work remain incomplete. This checkpoint does not claim universal last-seat protection or close T1.
