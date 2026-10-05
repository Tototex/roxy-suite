# Checkpoint 27 — linked member reservation Undo

2026-10-05. Suite 1.0.26 selectively deployed: Issuance, Tickets, member-log renderer, Suite version. No database schema change, real admission, provider charge/refund, inventory/vendor order/email or public publishing.

## Correction

New reserved member admissions link only the tickets changed by that operation to the exact inserted member-visit ID and subscription. Ticket changes, visit insertion and link writes commit together. An earlier QR or walk-up arrival is not relinked. Bare `check_in_ticket(..., 'member')` now refuses bypass of the coupled member reservation service; normal QR and Will Call callers retain their sources.

Explicit ticket Undo for a linked member admission locks its visit row and checks subscription, showing, order customer, active quantity and reserved-admission source. It decrements one person, clears that ticket's admission/link, and saves before/after visit evidence, Undo time and actor on the ticket in the same checked transaction. The final person leaves a zero-quantity inactive visit rather than deleting the record. Repeat Undo makes no change; readmission creates a new visit link while preserving prior Undo history. Membership expiry does not prevent a valid explicit Undo or grant a new admission.

Staff Manual Check-in cards show the latest saved member Undo summary. Historical member-source tickets with no exact link refuse automatic Undo and explain the need for reconciliation; no matching by name, timestamp or guessed subscription. Member Check Log now exposes Source, Quantity and Showing alongside its existing fields, filter, pagination and CSV export.

## Verification and re-audit

- Changed PHP lint and whitespace checks pass. Existing live files matched Git e2a4e3e after line-ending normalization before backup; four deployed file hashes match local bytes. Fresh WordPress reports 1.0.26.
- Actual Woo/WCS/MySQL member reservation fixture expanded from 15 to **33 assertions**, rerun against staged and deployed source. Tests include exact links, rejection of the bare member shortcut, missing/mismatched links, link-write failure, visit-update failure, late ticket-Undo failure, all-or-none rollback, decrement once, repeat prevention, saved audit/card rendering, readmission, expired membership, zero-quantity retained history, and actual dashboard monthly/lifetime totals after Undo. Two independent member Undo requests yield one transition and preserve an unrelated QR arrival; QR Undo never decrements the member visit. Disposable records cleaned up and original ticket/log digests preserved.
- Actual walk-up **16**, ticket issuance/admission/group/refund/Undo **42**, checkout holds **30**, transaction/lease MySQL **23**, standalone ticket eligibility **19**, Door/member **11**, member lookup/log/navigation/dashboard **13** assertions pass. Critical suites rerun against deployed source. Broader checkout holds, subscriber entitlement and mixed walk-up/reserved behavior remain intact.
- Initial injected late-Undo failure test did not match the backtick-quoted metadata column, so it failed to inject the intended SQL failure. Corrected the test predicate; verified rollback of visit quantity and saved audit as well as the ticket. This was an isolated private fixture; no live customer changed. No production guard was weakened to satisfy the test.
- Read-only original evidence remains **1,230 tickets / 18,898 ticket metadata / 128 member logs / 391 Will Call summaries** unchanged. Private member fixtures remaining **0**. Currently checked-in tickets explicitly marked member-source but missing a positive visit link: **0**; this is not a claim that all historical visit data is reconciled.
- Browser skill verified the live Member Check Log's ten columns and 50-row page, Manual Member Admit instruction, Door Mode showing selector and public ticket form. Actual staff card rendering for the new historical warning and saved Undo summary verified on private fixture records; no customer Undo clicked or physical camera/NFC scan performed.
- Live signed-in classic checkout with one comedy ticket and `tototest`: **$0 order 31163 / ticket 31164**. Receipt, valid loaded QR and durable hold/confirmation verified; cart cleared. Explicit test note retained; canceled afterward with ticket ineligible/unadmitted. Initial add-to-cart attempt left the cart empty; verified that state before retrying once. Final cart/order contained exactly one ticket, and checkout was submitted only once.

## Risk / limits

No automatic historical rewrite or inferred linking. Existing legacy walk-up quantities and requested-versus-actual visit anomalies remain review work. Walk-up entries without tickets still have no new Undo control. Source/status changes by unrelated raw writers and cross-order tickets sharing one visit require further coverage; row locking is implemented, but no cross-order shared-visit contention fixture is claimed. Refund-after-attendance policy remains unchanged and awaits user clarification. Database reconnect fault injection, generic order-item helper races, HPOS and paid/guest/Blocks workflows remain separate open findings.

Undo quantity changes correctly alter the existing dashboard totals for the original visit month; Undo time is retained on the ticket. This is deliberate explicit reversal, not an automatic refund adjustment. The original visit timestamp/source and zero-quantity record are retained. Audit snapshots include the original visit evidence already stored privately on the site; no personal data exported into this repository.

## Recovery

One persistent SSH session. Server originals/stage: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261005-member-undo`, outside web root.

Verified rollback archive: `I:\My Drive\Roxy Site Recovery\2026-10-05\checkpoint27-rollback-files.tar.gz`. Local/server SHA-256: `f573bb8e82cfbed1ae99edae8398dedb0688c4275c32075bef0f85b45316a439`. Local copy verified; cloud synchronization not asserted.

Restore old Tickets before old Issuance, then member renderer and Suite version. No schema reversal. New link/audit metadata may remain, but review any genuine linked member admissions before rolling back because the previous version's Undo does not reconcile their visits.

## Next

Read-only review of legacy attendance anomalies, explicit reconciliation workflow where identity is provable, and indexed/bounded reporting queries. Broader audit stays open; advertising contracts, renewals and monthly reporting remain deferred until stability work is complete.
