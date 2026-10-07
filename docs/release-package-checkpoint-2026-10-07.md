# Release package checkpoint — 2026-10-07

The current `stability/audit-2026-10` tree was packaged privately using the same runtime exclusions as `.github/workflows/release-on-version-push.yml`. The package contained 92 files and excluded `.git`, `.github`, `tests`, `docs`, `tools`, `.gitignore`, `RoxyEdit.md`, `.DS_Store`, and the local build directory.

The Windows archive was created only as a supplemental packaging check; it was not published, tagged, uploaded to GitHub, or installed on the live site. Archive SHA-256 for this local check was `20049e051f91c881b36ad3fcbade020fc0dda332a26e3005a5d468eedc5c9d28`.

The existing 15 release-workflow regression checks remain passing. The hosted Linux `zip -X` reproducibility step, GitHub release creation, and updater manifest consumption still require a controlled release-candidate validation. No release was created as part of this checkpoint.
