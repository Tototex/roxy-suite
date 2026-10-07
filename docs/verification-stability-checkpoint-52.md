# Checkpoint 52 — Membership history retention

2026-10-06. Test-only verification against deployed Suite 1.0.49; no production code change.

Existing history reads persisted showing walk-up scan quantities recorded active at admission, not current subscription eligibility. Current eligibility remains a separate prerequisite for new admission.

Eighteen isolated assertions pass, including canceled and expired subscription history remaining visible while a new admission is refused without another scan write. These use fake subscriptions/database, not actual membership lifecycle transitions. Twelve actual temporary-MySQL assertions pass against installed source: quantity aggregation excludes lookups/inactive scans, missing/deleted subscription history remains, write failure reports failure and all actual scan rows remain unchanged. Temporary log table removed. Both commands exit 0. First new isolated run had a fixture-only undefined check variable; corrected and rerun successfully.

T10's checkbox behavior was already corrected; retention now has explicit isolated canceled/expired and installed deleted-subscription evidence. Real full WooCommerce Subscriptions expiration/renewal/Undo lifecycle and physical Door/NFC remain outside this verification. No admission, real membership, mail or provider state changed.
