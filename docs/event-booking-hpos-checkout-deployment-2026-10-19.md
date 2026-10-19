# Event Booking HPOS checkout compatibility deployment — 2026-10-19

- Removed the two blanket legacy-order-storage checks from Event Booking's initial booking and booking-adjustment checkout handlers. Production remains on its current WooCommerce storage mode; this deployment did not change the HPOS setting.
- The live file differed from the tested local source only by those two checks. No live-only code was overwritten.
- Pre-deployment live SHA-256: `b510d290282ad888de2de928f9294d507025d831244cf2b92e33d791e8a73a57`.
- Verified byte-for-byte rollback copies:
  - `I:\My Drive\Roxy Site Recovery\2026-10-19\event-booking-hpos-checkout\woo.php.original`
  - `/home1/anrvxfmy/deploy-backups/roxy-suite-20261019-event-booking-hpos-checkout/woo.php.original`
- Tested candidate and installed live SHA-256: `1d4aff576b77794b610c466cc029a225b30de4c13e8a796676fb85622885cfd8`.
- Candidate, temporary install, and installed production file all pass PHP lint (production PHP 8.5.11).
- Hosted workflow [39297547218](https://github.com/Tototex/roxy-suite/actions/runs/39297547218) passed all nine jobs, including PHP 8.0–8.4, Social browser regressions, private MySQL lifecycle fixtures, Requested Showings/Woo lifecycle, and the private authoritative-HPOS lifecycle. The latter used only a disposable zero-value fixture; email transport was intercepted and no payment provider was used.
- Post-deployment read-only checks: Roxy Suite remains active; public `/`, `/tickets/`, and `/showings/` each returned HTTP 200.
- No live order, booking, payment, customer, cart, email, or HPOS configuration was modified. I-drive cloud synchronization is not asserted.
