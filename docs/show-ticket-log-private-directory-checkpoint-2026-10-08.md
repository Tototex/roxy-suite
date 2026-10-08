# Show Tickets fallback log privacy checkpoint — 2026-10-08

The fallback flat-file logger writes below `wp_upload_dir()/roxy-st-logs`. It now checks that the directory exists and is writable, creates and verifies a readable `index.html`, and installs/verifies explicit Apache deny rules (including both modern and legacy syntax). Missing, empty, or weaker rules are repaired. If any guard cannot be verified, the fallback file write is skipped; WooCommerce logging remains independent.

Seven isolated checks cover path selection, index creation, complete deny rules, repair of missing/weak/allow rules, and refusing the fallback write when deny rules cannot be verified. The focused regression and changed PHP files pass PHP 8.3 locally. The regression is included in `.github/workflows/php-compatibility.yml` for the hosted PHP matrix and isolated suite.

The live directory URL and a HEAD request for the log file both returned `403 Forbidden` from Apache on 2026-10-08; the log body was not requested or read. This verifies the currently deployed access boundary, but not that the candidate logger change is deployed or that all upload paths/CDN edges are covered. No production log files or settings were changed.
