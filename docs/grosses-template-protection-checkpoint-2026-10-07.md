# Grosses workbook-template validation checkpoint — 2026-10-07

## Implemented locally

- Uploaded templates must have an `.xlsx` filename and be a readable ZIP/OOXML package. Validation requires the package manifest, root relationships, workbook, and Grosses sheets 3 and 4; required XML is parsed with network access disabled.
- Duplicate ZIP entry names, missing/malformed required files, more than 2,048 entries, expanded content above 100 MiB, required XML entries above 8 MiB, and compressed archives above 25 MiB are rejected before the upload is moved into private template storage.
- A rejected upload is removed from the temporary WordPress uploads location so it is not left publicly addressable. Existing accepted templates and workbook-generation behavior are unchanged.
- Added an isolated WordPress fixture covering a valid package, wrong extension, non-ZIP content, missing sheet, malformed XML, duplicate entries, excessive entry count, and compressed size limit. The oversized test uses a sparse local fixture, not site data.

## Verification and limits

- `git diff --check` passes. The Windows workspace has Node and OpenSSH but no PHP executable; therefore the PHP lint and WordPress fixture have not yet run in this environment.
- No upload, production file, workbook, database record, or email was changed. This is not deployed. Run the new fixture and PHP 8.3 lint against the private candidate before considering deployment; deployment still requires a freshly verified offsite backup.
