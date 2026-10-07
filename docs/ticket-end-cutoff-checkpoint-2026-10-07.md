# Ticket end cutoff — 2026-10-07

Reviewed and refined Luna's bounded implementation before deployment. Initial candidate would have disabled every existing showing lacking a new duration; it was not installed. Final implementation preserves legacy future sales and introduces `_roxy_duration_minutes` as an optional verified runtime.

When a valid duration exists, shared product/cart/direct-add/form/listing guards use start timestamp plus elapsed minutes and close at the exact end. Strict local datetime parsing accepts existing minute/second formats and rejects impossible dates/DST gaps. Missing duration keeps the former start-time cutoff; malformed supplied duration fails closed. Product cleanup compares timing directly, preserving running shows and future nonpublic shows. Listing and cleanup read 100-row pages instead of loading every showing at once.

Admin duration input is optional for legacy records. Missing/invalid input cannot erase a valid saved runtime; bad input displays a notice. Generated showings inherit the verified runtime. No runtime was guessed or added to real showings.

Before/after installation: 52 isolated actual-source publication/cutoff checks pass. Candidate and installed metadata/Woo/Store API fixture runs each pass 17 assertions, including future missing-runtime compatibility, running-show purchase eligibility, ended-show rejection, and withdrawal. Private fixture posts remained drafts and were removed by fixture cleanup; no order/payment created. Existing seven cart-pricing groups and five schedule-child status groups pass. Four installed runtime hashes match local candidates; all four pass PHP 8.3 syntax checks.

Live browser: existing October 30 comedy page renders $20 GA; one guest test ticket adds to an initially empty cart and reaches checkout with the correct $20 total. No checkout was submitted. `tototest` correctly refused to apply without the restricted checkout email; this run is **not** a completed $0 purchase test. Test ticket removed and cart explicitly verified empty. No unrelated cart item existed or was removed.

Rollback files were verified on `I:\My Drive\Roxy Site Recovery\2026-10-07\checked-counts-and-ticket-cutoff` before installation. This extends verified-duration shows, not all existing shows: actual durations/end times still need an authoritative source or manager entry. User was asked where these are recorded. T7 remains in progress for that rollout and broader concurrent publication/payment paths.
