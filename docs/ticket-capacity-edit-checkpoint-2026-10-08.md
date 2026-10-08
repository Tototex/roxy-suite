# Ticket capacity-edit checkpoint — 2026-10-08

## Change

Showing capacity is now saved under the same showing-scoped seat lock used by checkout, seat reservations, and walk-up admission. While holding that lock, the save path reads fresh Woo reservations and member walk-up admissions. A new limit below committed occupancy is rejected; malformed/non-whole input and unavailable lock/storage/readback also fail closed. The whole showing form submission is withheld on failure, so unrelated metadata from that submission is not partially saved. Capacity equal to occupancy remains valid, and zero continues to mean no seats when configured. An omitted field preserves the current saved capacity (or uses the configured default for a new showing).

## Verification

- The ticket-publication regression adds checks for shared-lock use, capacity reduction below paid/held plus walk-up occupancy, acceptance at exact occupancy, malformed numeric/array input, lock failure, and no partial form save; all pass.
- The existing ticket-publication regression group passes, including duration, publication, product identity, stale-cart, and bounded cleanup checks.
- No live showing, capacity, order, or admission was changed.

## Remaining boundary

The lock coordinates Roxy-managed writers only. A third-party integration that writes Woo order tables directly without invoking the supported reservation/confirmation hooks can still bypass it. Ticket product mapping mutations also need to share the seat lock, and provider/Blocks/guest runtime coverage remains incomplete. This checkpoint does not claim universal last-seat protection or close T1.
