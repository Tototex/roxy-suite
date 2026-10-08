# Inventory incomplete-cost safeguard

Deployed selectively on 2026-10-07 to the live Suite, retaining the existing stock units, suggestions and ordering rules.

- Missing, zero, negative, malformed or nonfinite purchase costs display as **Unknown**. Positive-quantity unknown-cost lines make an estimate incomplete; the displayed dollar amount is explicitly only the known subtotal.
- Review controls update this state after quantity edits. Submission independently rejects ordered items without a positive finite cost, before creating an order or sending mail. Zero-quantity unknown-cost items can be skipped.
- Existing stored order payloads use `quantity`, whereas reviews use `qty`; regression coverage checks both. Existing incomplete history is labeled without modifying its payload or saved total.
- Zero remains the legacy unknown sentinel, not a verified free-item model. Positive values are configured costs, not independently verified supplier quotes. Price source/date/confidence and separate supplier SKU remain open under I6.

## Verification

Candidate and installed PHP syntax checks passed. The broader Inventory regression passed, as did 54 isolated handler assertions before and after deployment. These include unknown-cost submission rejection and a valid order that skips an unknown-cost zero-quantity item; mail is mocked, not sent.

Actual WordPress read-only rendering passed for the dashboard, all 13 enabled vendor views and order history. Five dashboard estimates now expose incomplete costs. No real order submission, cancellation, decision, pull or email was triggered. Browser form interaction was not repeated in this checkpoint.

All 210 product, 14 vendor and four order records have identical before/after digests:

| Records | SHA-256 |
|---|---|
| Products | `5e6e79f9f5eb25e1f2d59282bd4af824a242fcbbf253d999f5e07bfb0bc58e29` |
| Vendors | `771f9d4f6c2092495f06e91cd3cf6ae03b27953109eafccddd75ea24093c9a26` |
| Orders | `1085b54158d9931af29e7b33232fc77d24a04393749c3fc64584915b26af55fa` |

Installed Admin SHA-256: `ddcebed9118541f3bdd5e3020181a8a29f41a50680be52986868b7168ec8675e`, matching local/candidate bytes. Original Admin rollback copy was checksum-verified on `I:\My Drive\Roxy Site Recovery\2026-10-07\inventory-pricing-and-requested-money` before deployment; original hash `0511046a61dbc9ec17ce2ee215e36bebea85778b3e269f362f94e8e41deedd41`. This verifies a local Google Drive copy, not cloud synchronization.
