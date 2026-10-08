# Member walk-up Undo live parity checkpoint — 2026-10-08

The exact-identity walk-up Undo feature was previously labeled branch-only in the stability tracker. A read-only production reconciliation shows the relevant deployed runtime is current:

- Production SHA-256 matches the branch for `includes/modules/show-tickets/includes/class-roxy-st-issuance.php` (`ae5435e667f5842037b4ba28df83db2624ecbba6c77d060cbf31d4e505b6c269`), `includes/modules/show-tickets/includes/class-roxy-st-tickets.php` (`3c0a3605f5aad9ec12184239bf97e601e21ea2114a8ee3d1c9b13a8d0ce30f8c`), and `includes/modules/show-tickets/assets/js/door-mode.js` (`ab5a1255ffbcf56ab4c0e90193cdf13b1273f462d33fdc87f541a0e58c2584d3`).
- WP-CLI confirms `roxy-suite` is active and the `wp_ajax_roxy_st_member_walkup_undo` action is registered.
- The hosted PHP 8.0–8.4 matrix, private MySQL transaction fixture, PHP 8.3 isolated suite, and Door Mode JavaScript interaction regression passed in [run 37841981322](https://github.com/Tototex/roxy-suite/actions/runs/37841981322).

No live visit, ticket, order, or attendance row was created or changed. No deployment was needed. This is strong source/runtime-registration evidence, not an authenticated live staff-screen workflow test; that remains open, as do historical requested-versus-actual attendance attribution and external writers outside the managed lock protocol.
