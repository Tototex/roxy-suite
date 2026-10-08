# Event capacity copy checkpoint — 2026-10-08

## Confirmed policy

The owner confirmed that 250 is the maximum capacity for all event types. The Event Booking module's default guest cap is already 250, and the live rental form reports `guestCap: 250`; its public Full Theater Rental text says “26 to 250 guests.” No booking, order, or event record was changed.

## Public copy audit

- The global Elementor footer said “up to 200 movie patrons.” Updated the footer copy to 250 and confirmed the public homepage serves the new 250 wording.
- The homepage's theater-description section also said 200. Updated it to say the current maximum is 250 patrons and confirmed the live homepage preview/public response shows the new copy.
- The About page stated that its current layout totals 200 seats. Updated the page to say its current event capacity is up to 250 guests, while preserving the separate historical/future-restoration figure of 430 seats. WordPress editor revision 31426 and the editor's reloaded source show the new wording, and WordPress reported “Page updated.” However, the public About page and unauthenticated REST representation still serve the old 200-seat copy, even after the page-cache purge. The About-page fix is therefore **not verified live** and remains open; do not claim the capacity correction is fully deployed until the public page shows 250.

## Recovery and next check

Before these WordPress content changes, the existing I: drive database backup `I:\My Drive\Roxy Site Recovery\2026-10-08\database-before-938cee8.sql.gz` was verified to decompress as a MySQL dump (SHA-256 `B5310456B9EE1B598EBBFA7FBAC843750699543A87873B283466DA01F621B008`). Cache clearing was limited to the site's existing page-cache control. Investigate why the About-page editor revision is not reflected in public `post_content`, then verify the canonical About URL and REST representation. Avoid further full-site cache purges or repeat edits until the mismatch is understood.
