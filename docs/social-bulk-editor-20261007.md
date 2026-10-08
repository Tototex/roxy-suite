# Social drafts bulk editing

Actions return to the selected status view instead of dropping to All. Redirects use a validated `return_status` or the Social page referrer; mutation status is distinct from the return filter. Drafts is the default view, All is last, and Needs Review is available directly.

Save All appears above and below the table. Row save buttons are hidden; existing row forms/nonces and revision checks are reused by authenticated AJAX. Only changed editable rows are saved. Successful saves return to the same tab; partial failures retain unsaved edits and identify affected rows. Stale controls on saved rows are disabled until reload, and unsaved edits trigger the browser's navigation warning. Editing an approved row still resets it to Draft, requiring approval again; untouched approved rows are not saved.

Hangar selection validates the current row revision and returns its new revision to the picker, avoiding a stale save after an import. Caption saving now unslashes WordPress request text so apostrophes are preserved.

Verification: 11 isolated actual Admin assertions for rendered controls, return status, stale saves and apostrophes; Playwright against actual PHP-rendered fixture for multi-row saves, unchanged-row exclusion, top/bottom buttons, tab preservation and partial failures. No live draft was edited or published by these tests. Remote PHP 8.3 assertions/lint passed; all three deployed files match staged bytes. Original Admin and picker are backed up at `/home1/anrvxfmy/deploy-backups/social-bulk-editor-20261007/`. The new `draft-bulk-editor.js` must be included with the Admin file in future deployments.
