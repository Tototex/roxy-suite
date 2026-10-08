# Social draft media picker checkpoint

Selective production files: Social Admin and draft-media-picker.js. Original live files matched HEAD after line-ending normalization; private rollback copies under `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261003-054238/checkpoint8`. No database schema or publishing/approval changes.

- Removed five redundant inline draft picker/observer scripts; single external file with timestamp version. Filename display and import-button dataset assigned as literal values, not interpolated HTML attributes. Invalid/nonpositive asset IDs ignored; request failures restore retry feedback.
- Draft modal closures hold their own form, preventing later chooser from changing an earlier selection target. Legacy cached HTML is not parsed/reinserted. Hangar search stores version-2 structured results used by draft chooser. The main Hangar tab no longer restores old HTML; one fresh search repopulates safe draft cache.
- Five mocked DOM regression checks cover quoted/markup filename, unsafe legacy cache, structured cache, invalid IDs, removed handlers. JS/PHP syntax pass. Read-only staged Admin class renders actual featured, Hangar and draft views under request-local admin context; all six inline scripts compile. This does not certify publisher HTTP or claim/retry behavior.
- Live chooser opens once from editable draft, with versioned JS. Read-only Forgotten Island Hangar search returns 200 assets; close/reopen reuses structured results without provider call, still one chooser. Media Library opens. No assignment, media import, Save, approval, publish or deletion performed. Social status remains one failed/nine posted.

Remaining Social findings: publisher claims/provider success, approval snapshots, partial-platform deletion, credential handling, cleanup references and bounded work. No real Social post sent by this checkpoint.
