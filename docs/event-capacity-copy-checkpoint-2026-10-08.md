# Event capacity copy checkpoint — 2026-10-08

## Confirmed policy

The owner confirmed that 250 is the maximum capacity for all event types. The Event Booking module's default guest cap is already 250, and the live rental form reports `guestCap: 250`; its public Full Theater Rental text says “26 to 250 guests.” No booking, order, or event record was changed.

## Public copy audit

- The global Elementor footer said “up to 200 movie patrons.” Updated the footer copy to 250 and confirmed the public homepage serves the new 250 wording.
- The homepage's theater-description section also said 200. Updated it to say the current maximum is 250 patrons and confirmed the live homepage preview/public response shows the new copy.
- The About page's Elementor Text Editor widget (not the standard WordPress revision content) stated that its current design totals 200 seats. Updated that widget to say its current event capacity is up to 250 guests, preserving the 430-seat historic/future-restoration figure and all other paragraph text. Published in Elementor and verified both the unauthenticated REST rendered page and the canonical public About URL: they now contain the 250-guests wording and no longer contain the old 200-seats statement.

## Recovery and next check

Before the copy changes, the existing I: drive database backup `I:\My Drive\Roxy Site Recovery\2026-10-08\database-before-938cee8.sql.gz` was verified to decompress as a MySQL dump (SHA-256 `B5310456B9EE1B598EBBFA7FBAC843750699543A87873B283466DA01F621B008`). The earlier standard WordPress revision did not change the Elementor widget; this was caught during public verification and corrected in the actual Elementor source. No full-site cache purge was needed for the final correction. No booking, order, or event record was changed.
