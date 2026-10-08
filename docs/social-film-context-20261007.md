# Social film context and Heart of the Beast repair

The first Heart of the Beast draft (187) was approved and published October 7, 2026. Facebook video 1082438237989642 reports publishing complete/published; Instagram media 17962673802207817 returns reel DeNS-jakezw.

All five user-edited captions retained their creative body and selected media. Weekday-only schedule lines were replaced with canonical abbreviated dated showtimes; redundant time mentions in the Monday/Friday prose were removed to pass the existing guard. Saturday now includes the remaining Sunday showing. The first draft was approved; the second remains draft; Friday through Sunday retained approval. Original rows are backed up at `/home1/anrvxfmy/deploy-backups/heart-social-before-20261007-211148.json`.

AI now uses a showing's description/excerpt as film context, or a bounded Wikipedia lookup requiring one exact-title film result. Missing, ambiguous, or unavailable context stops AI generation for review rather than generating title-based guesses. The Forgotten Island-only hardcoded context was removed. The source is supplied as facts, not instructions, and ambiguous/remake identity still requires a manager-provided synopsis.

Automatic Hangar selection requires a full-title or bounded acronym match in the filename/name. Heart of the Beast accepts HOTB/HOB; the unrelated Universal filename is rejected. Legitimate generic filenames can require manual selection. Existing manually chosen media was not replaced. Correct dated schedule lines now accept leading emoji; stale or undated schedules still fail validation.

Fourteen isolated actual-code assertions cover source availability/ambiguity, title/acronym matching, correct schedules, leading emoji, wrong times and missing dates. Local/server PHP 8.3 tests pass; both deployed files pass lint and match staged bytes. Previous plugin files are backed up at `/home1/anrvxfmy/deploy-backups/social-film-context-20261007/`.
