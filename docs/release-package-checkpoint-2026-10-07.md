# Release package checkpoint — 2026-10-07

The working folder was packaged privately using the same runtime exclusions as `.github/workflows/release-on-version-push.yml`. That Windows package contained 92 files, including unfinished local runtime files not committed to GitHub. It is **not a canonical release candidate**. The archive excluded `.git`, `.github`, `tests`, `docs`, `tools`, `.gitignore`, `RoxyEdit.md`, `.DS_Store`, and the local build directory.

The Windows archive was created only as a supplemental packaging check; it was not published, tagged, uploaded to GitHub, or installed on the live site. Archive SHA-256 for this local check was `20049e051f91c881b36ad3fcbade020fc0dda332a26e3005a5d468eedc5c9d28`.

The existing 15 release-workflow regression checks remain passing. No release was created as part of this checkpoint.

## Independent committed-source verification

Source commit `649a09e81d24a32fe20317c89e4c37e3c8cfb56d` was exported with `git archive`, so dirty/untracked files cannot enter the package. Source tar SHA-256 `ecf22685faf6d7cf487b2f2c82d74a821d21d5b82927874cdbd195c925dbf1ba` matched after transfer. `tools/verify-release-package.sh` built twice on Linux using the workflow exclusions, commit timestamp `1791389723`, sorted file paths, and `zip -X`. Both archives passed ZIP integrity tests, excluded development files, contained 90 committed runtime files, and matched SHA-256 `4c20253628e2f0aa034f3246477568428c55ba604241014d59d51683afb83cb9`.

The private files remain in `/tmp/roxy-release-verification-Utrp72X1`; they were not installed, tagged, or published. This verifies private Linux ZIP reproducibility for that exact source snapshot, not a hosted GitHub Actions run, release creation, or updater manifest consumption. Those remain open.
