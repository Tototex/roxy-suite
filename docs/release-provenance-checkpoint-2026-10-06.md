# Release provenance safeguards — 2026-10-06

The master-only release workflow now refuses an already-used version tag, including a race at tag creation. It attaches a version/source-SHA manifest with sorted packaged-file SHA-256 values and the final archive hash. ZIP inputs are sorted, timestamps use the source commit and extra ZIP attributes are suppressed. Root tests, audit documents and RoxyEdit.md are excluded from runtime packages.

Main review of the smaller-model implementation: 13 local assertions execute the exact embedded tag guard and manifest programs against private mocked GitHub/filesystem fixtures. Existing tag, absent tag, authorization failure, creation race, source/version identity, sorted paths and file/archive hashes pass. No real release/tag/provider action was performed. Initial test runner did not normalize Windows line endings; corrected and rerun. No YAML parser was present in the available local runtimes; full YAML/hosted GitHub Actions and actual ZIP reproducibility are not certified. Existing workflow structure is preserved.

C2 remains in progress: the updater does not validate manifests; deployment comparison remains selective rather than release-wide. This branch does not trigger master release publication. No dependency upgrades or production code changes belong to this checkpoint.
