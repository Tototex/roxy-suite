# Stability checkpoint 1 — deployed 2026-10-02/03

Version: 1.0.17. Branch: `stability/audit-2026-10`. This is a selective remediation checkpoint, not completion of the full audit.

Rollback baseline: `910530d3bc77e17ac2dc267bac2bdadd6b5fae71`, preserved on GitHub as `backup/pre-audit-stability-2026-10-02`. Changed production files backed up privately under `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261003-054238/`; no full production Git reset or bulk overwrite performed.

The following production files were compared against local SHA-256 after normalizing CRLF to LF; all eleven matched. This normalization allows Windows/Git and Linux source comparisons without treating line endings as drift.

- `includes/class-roxy-suite-health.php`
- `includes/class-roxy-suite-updater.php`
- `includes/modules/event-booking/includes/availability.php`
- `includes/modules/inventory/includes/class-roxy-inventory-admin.php`
- `includes/modules/inventory/includes/class-roxy-inventory-scheduler.php`
- `includes/modules/inventory/includes/class-roxy-inventory-store.php`
- `includes/modules/show-tickets/includes/class-roxy-st-cpt.php`
- `includes/modules/show-tickets/includes/class-roxy-st-frontend.php`
- `includes/modules/show-tickets/includes/class-roxy-st-tickets.php`
- `includes/modules/will-call/roxy-will-call.php`
- `roxy-suite.php`

Verification: deployed PHP lint; four isolated regression suites; live inventory 143-row save; unchanged vendor save; booking calendar; current events in Door Mode and Will Call; actual checkout/check-in/undo and cumulative Woo refund tests. Authorized $0 test orders 30621 and 30623 canceled; four tickets canceled/refunded; test cart empty. Next inventory job confirmed non-repeating at 2026-10-04 06:00 UTC (October 3, 23:00 local).

Known limitations and all remaining findings: [remediation tracker](stability-2026-10.md). Do not use diagnostic pass counts as end-to-end certification. No gateway money was refunded, no Social post was published, and no real vendor order was changed during this checkpoint.
