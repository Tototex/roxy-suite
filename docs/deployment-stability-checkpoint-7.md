# Booking assets checkpoint

Six selective production files: module bootstrap, shortcode loader, module JS/CSS and legacy top-level JS/CSS compatibility mirrors. All originals matched HEAD after normalized line endings. Private rollback copies at `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261003-054238/checkpoint7`. No settings/database changes.

- One served canonical module asset set in both Suite and standalone; file timestamps invalidate cached URLs. Top-level URLs remain usable for previously cached HTML; regression test prevents mirror drift. Runbook updated.
- Canonical script includes existing pizza-window/date-load error handling previously stranded in fallback copy. Retains both showing gold and booking turquoise colors.
- Explicit zero prices, lead hours and pizza-window start are not replaced with defaults; missing values still default. Six Node fixture checks; PHP/JS syntax checks.
- Live October 4 session: updated module JS URL, Book now selects October 6 with available times; changed date to November 7 returns its distinct availability; 08:00 disables pizza Yes with explanation; 11:30 enables it. Cancel closes form, week/month navigation checked. No cart/booking/order submitted; production prices untouched.
- Booking health-check cron remains scheduled. Existing Elementor compatibility issue is separate; not upgraded here. Failed-date guard inspected in source, but no live network fault injected.
