# Dated source backups removed from public serving

Read-only comparison against committed source `06b7efba288b03795a565873cc619d37e4ea87f3` found 90 runtime files with matching content: 68 exact and 22 line-ending-only differences, no missing or substantive drift. Fourteen additional server files required review.

Ten extras were April 20 source backups. Reference searches in installed plugins/themes found no uses of their dated filenames. A HEAD-only request to one exact backup URL returned HTTP 200; contents were not downloaded. Such backup suffixes can expose source rather than execute it, so these files were recoverably relocated outside the public tree after verification.

- Exact ten-file archive copied to `I:\My Drive\Roxy Site Recovery\2026-10-07\legacy-suite-backups\legacy-suite-backups-20260420.tar.gz`. Remote/local SHA-256 match: `dd0a71cefd247ebdc603f350d87fa3afdc097f76d8dc79227b2e3ea63644e1b2`. Both archive listings contain the ten exact intended paths. Cloud sync is not asserted.
- Before moving, every source path was checked for exact resolution, no symlinks, no occupied recovery target, and hash equality with its archive entry. Recovery directory is outside public_html with restricted permissions. All moves were followed by absence/preserved-content checks.
- Ten backups totaling 761,419 bytes now reside under `/home1/anrvxfmy/roxy-suite-legacy-recovery-20261007`. They were moved, not permanently deleted. Active runtime files were untouched.
- The tested URL now returns HTTP 404. Repeat manifest comparison still finds all 90 runtime files content-matching, with four extras left: older Social PHP, two old picker JS copies, and an error log. Those remain pending broader reference/log-retention review; absence from ordinary bootstrap alone is not proof they are unused everywhere.

The exact maintenance helper is excluded from release/runtime packaging. This closes the tested dated-backup serving issue, not a certification that all site backups/logs are inaccessible.
