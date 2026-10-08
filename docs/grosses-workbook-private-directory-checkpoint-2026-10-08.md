# Grosses private workbook directory checkpoint — 2026-10-08

`Workbook::protect_directory()` previously wrote `index.html` and `.htaccess`
without checking write/read results or correcting an existing access file whose
deny rules had been removed. It now fails closed if the index or access rules
cannot be verified, and replaces missing, weakened, or conflicting access
files with one canonical deny-only policy. The policy uses Apache 2.4's
`Require all denied` when `mod_authz_core` is present and falls back to Apache
2.2's `Deny from all` otherwise. Repeated checks leave the file unchanged.

Four isolated filesystem checks cover a fresh directory, partial-rule repair,
weakened-rule repair, and idempotent repeated verification. The PHP 8.3 hosted
cross-module run 37735806423 passes the new fixture and all 71 scripts in its
isolated suite. PHP syntax and the focused regression matrix pass on PHP
8.0–8.4.

This is not proof that the production web server honors `.htaccess` (for
example, an Nginx deployment does not). No production files were changed by the
fixture, and this branch-only change has not been deployed. Verify actual
unauthorized HTTP access to a harmless private fixture on the deployed host
before claiming server-level privacy is confirmed.
