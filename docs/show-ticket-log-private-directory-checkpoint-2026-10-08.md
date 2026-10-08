# Show Tickets fallback log privacy checkpoint — 2026-10-08

The fallback flat-file logger writes below `wp_upload_dir()/roxy-st-logs`. It now checks that the directory exists and is writable, creates and verifies a readable `index.html`, and installs/verifies explicit Apache deny rules (including both modern and legacy syntax). Missing, empty, or weaker rules are repaired. If any guard cannot be verified, the fallback file write is skipped; WooCommerce logging remains independent.

Seven isolated checks cover path selection, index creation, complete deny rules, repair of missing/weak/allow rules, and refusing the fallback write when deny rules cannot be verified. The focused regression and changed PHP files pass PHP 8.3 locally. The regression is included in `.github/workflows/php-compatibility.yml` for the hosted PHP matrix and isolated suite.

This protects Apache deployments only. It does not prove the active production web-server/CDN access policy; the live URL behavior and deployment remain unverified. No production log files or settings were changed.
