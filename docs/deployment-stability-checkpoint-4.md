# Ticket / Will Call deployment checkpoint

Selective production files: Show Tickets `class-roxy-st-tickets.php` and `will-call/roxy-will-call.php`. Suite remains 1.0.17; no database schema migration. Before upload, both live files matched Git HEAD after CRLF→LF normalization; byte differences were line endings only.

Original file backups: `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261003-054238/checkpoint4`, preserving relative paths. Tests remain in private stage, not uploaded publicly. Restore these two copies to roll source back; do not change real order statuses or import unrelated tables.

Evidence:

- PHP syntax; 15 Will Call PHP assertions; existing ticket eligibility/refund and showing selection suites; JavaScript syntax and eight mocked DOM/queue assertions. Browser is separate from mocked DOM evidence.
- Staged read-only sample of 50 actual tickets: 42 paid allowed, 5 missing/refunded blocked, no unexpected eligible paid blocks. Sample does not certify every historical ticket.
- Live authorized `tototest` checkout 30629, three general tickets, $0. Test note guards the private fixture script; it refuses nonzero, non-tototest, nonmarked or unexpected orders.
- Manual check-in first ticket; stale Will Call page rejects edit; refresh reads actual admission; native numeric controls admit other two, preserving source of manual first. One-ticket zero-dollar refund removes eligibility of one ticket; deletion plus installed Woo refund-deleted hook reconciles it. Fixture does not call gateways or restock inventory.
- Final order canceled; tickets 30630–30632 canceled/ineligible/unchecked with blank source. Cart contains no test item; live Will Call shows zero test purchases. Refund-deletion test permanently deletes only its own zero-dollar fixture refund; no funds moved. Canceled test order and notes remain as evidence.
- Follow-up JavaScript safeguards prevent older queue completion deleting newer operation, isolate queues by site/user/session, expire after two hours, reject stale baselines, serialize each rendered row, and treat server rejection differently from network loss. Expired/legacy unscoped queue data is not replayed.

Remaining: simultaneous server admissions/issuance; mixed legacy+new ticket customer reconciliation; historical subscription attendance; other Sales/Capacity refund/cache paths; attendance-after-refund policy. No broad finding is certified solely by this checkpoint.
