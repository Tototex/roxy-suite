# Live Roxy Suite structural health checkpoint — 2026-10-08

Ran the production `RoxySuite\Health::run_structural()` entry point through WP-CLI. It returned 10 modules: Core/Environment, Show Tickets, Requested Showings, Will Call, Member Check, Event Booking, Arcade, Grosses, Inventory, and Social Publisher. All 10 reported `pass`; there were no warnings or failures.

The structural path checks installed integrations, module tables/types/settings, and scheduled-hook presence. Source review confirms it does not execute cron jobs, send email, submit payment/order requests, publish Social content, or modify plugin business records. This result supplements the byte-for-byte source parity and public page smoke, but does not certify all authenticated workflows or provider operations. The full Health “functional” path was not run because Event Booking can contact Sling in direct mode.
