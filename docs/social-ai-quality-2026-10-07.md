# Social AI Quality Checkpoint - October 7, 2026

## Scope

Review the fourteen current drafts, improve reusable film context and prompting,
and test local models before replacing captions. Do not approve or publish.
The user explicitly authorized rewriting the manually edited October 14 Angel
and the Badman draft (193). The five already-approved rows are out of scope.

The larger outbound-worker migration is deferred in `social-ai-backlog.md`.

## Changes

- Added an editable Film References table to the AI settings tab. References
  include title, release year, genre, synopsis and an HTTPS source URL.
- Prefer the showing's own synopsis, then a confirmed reusable reference, then
  legacy supplied context, then a uniquely matched Wikipedia film article.
- Automatic lookup rejects ambiguous film versions and non-film matches. It
  retrieves the selected article's premise/plot section when available.
- Seeded confirmed 2026 references for Angel and the Badman, Wildwood and Street
  Fighter using official studio/distributor sources. This avoids the 1947 Angel
  and the Badman and older Street Fighter adaptations.
- Separate the creative caption from the application-generated dated schedule.
  Friday retains all upcoming shows, Saturday excludes Friday, and Sunday
  excludes Friday and Saturday. Dates use `Fri, Oct 16 at 2:30 PM` format.
- Use generic voice patterns, day-specific briefs, accurate afternoon/evening
  wording, structured JSON and a short creative-text limit.
- Reject missing film/theater invitations, unsupported offers, outside-food
  claims, misleading evening wording and unusable output. Strip preambles,
  generated schedule lines, Markdown emphasis and invisible format characters.
- Added a no-write preview path and revision-checked generated-caption
  replacement. Manual, approved and remotely started posts are protected.

## Evaluation Status

The existing llama3.2 model and a tested qwen3:8b model still produced invented
details and weak copy with better context. Neither set of candidates was
applied. gemma3:12b is installed locally, fits entirely on the GPU at an
8192-token context, and is now the selected production model.

Reviewed several preview batches across all fourteen drafts, then targeted
revisions for repetitive openings, awkward phrasing, unsupported details and
genre-inappropriate jokes. Each day now has a different opening structure.
The final reviewed captions were saved to drafts 193-206. All fourteen live
captions matched their reviewed candidates and passed the canonical schedule
gate. Media and all other substantive fields were unchanged. All fourteen
remain drafts, and the five already-approved rows (188-192) were byte-for-byte
unchanged. Nothing was approved or published by this work. Draft 193 retains
its manual-edit protection after the explicitly authorized one-time rewrite.

SSH intermittently refused new connections. A single reused authenticated
connection allowed evaluation, deployment and verification to finish.

An experimental response-schema pattern caused invalid JSON with Gemma and
was removed before acceptance. A simple one-field schema, repetition penalty,
application-side length/format checks and invisible-character cleanup worked
better. The final reusable prompt/backend/editor changes are deployed.

Human review remains important: the mechanical checks do not prove every
creative sentence is factually correct or that every joke lands. Approved
captions remain protected from background rewriting.

## Verification

Local passing checks: film context 17; editable references 8; structured caption,
quality, preview and retry 25; AI snapshot 12; schedule 25; bulk editor server 11.
Film-reference and bulk-editor desktop/mobile browser checks pass. All three edited production
PHP files pass syntax checks. Live isolated MySQL snapshot regression passed
41 checks without changing production draft rows.

## Backups and Sources

- Code: `/home1/anrvxfmy/deploy-backups/social-ai-quality-code-20261007/`
- Original unposted rows/options:
  `/home1/anrvxfmy/deploy-backups/social-ai-quality-20261007-224739.json`
- Showing context metadata: timestamped `social-ai-quality-contexts-*.json` in
  the same backup directory.
- Angel: https://www.angel.com/movies/angel-and-the-badman
- Wildwood: https://vvsfilms.com/movie/wildwood/
- Street Fighter: https://www.paramountpictures.com/movies/street-fighter

Ollama's ordinary chat endpoint does not browse independently. Retrieval is
performed by the website before generation. Adding browsing tools would need
an application tool loop and a search provider; prompting it to "look up" a
film alone cannot supply facts. Ambiguous versions require a confirmed source.
