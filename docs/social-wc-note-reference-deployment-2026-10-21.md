# Social cleanup: WooCommerce admin-note references

Date: 2026-10-21

## Change and verification

`wc_admin_notes` currently has 256 rows and its `image` field is empty, but `content` and `content_data` can hold image URL references. Social cleanup now checks those fields, including slash-escaped URLs, and fails closed if the table or query is unavailable. Regression cases cover full URLs, escaped relative paths, and query failure.

Commit `0382313201793ac68a60c9e9136f408339475891` is pushed. Full hosted workflow [39545046548](https://github.com/Tototex/roxy-suite/actions/runs/39545046548) passed all 15 jobs.

Before deployment, original live SHA-256 `8ed41f0b38b6b4afbc9f651da2112abe2676d6115199ab0f50b4c82a004566ed` was backed up at:

- `I:\My Drive\Roxy Site Recovery\2026-10-21\social-wc-note-reference-0382313\class-roxy-social-store.php.live-original`
- `/home1/anrvxfmy/deploy-backups/roxy-suite-20261021-social-wc-note-reference-0382313/class-roxy-social-store.php.live-original`

The installed file matches candidate SHA-256 `e31309838670c11b0a112405e0e491058682409c424d92d9fb7e00ec03831f88`, passes production PHP lint, and Roxy Suite reports Active. Public HEAD checks returned 200 for `/`, `/tickets/`, `/showings/`, and `/rent-the-roxy/`; anonymous `/member-check/` returned the expected 302 login redirect. No cleanup job, media deletion, provider call, or business-data mutation was performed.

Auxiliary WP-CLI class-load probes had command-quoting parse errors and are not counted as successful tests; they made no database writes or provider calls. The external-writer race and expensive full scans described in `audit-continuation-2026-10-16.md` remain open; detached-media cleanup was not run.

## Runtime source parity

After deployment, all 99 tracked runtime files were compared against the installed plugin in one read-only SSH session. Ninety-four match byte-for-byte; the only five raw-hash differences are `includes/modules/grosses/roxy-grosses.php`, Requested Showings frontend/template, and Show Tickets Door Mode JavaScript/eligibility. These are the same five already verified in the 2026-10-20 full parity checkpoint as line-ending-only differences, and none has changed in Git since that comparison. The newly deployed Social Store matches its branch source exactly. The three pre-existing untracked `.codex-*` directories were preserved.
