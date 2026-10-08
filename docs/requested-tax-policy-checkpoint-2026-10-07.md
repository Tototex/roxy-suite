# Requested Showings tax policy checkpoint — 2026-10-07

The customer-confirmed policy is that Requested Showings admission tickets and
the separate sponsorship fee are nontaxable; Roxy does not collect or report tax
on either. WooCommerce may calculate taxes elsewhere in the store, but mapped
ticket products must have tax status `none`, and conversion fails closed if one
is accidentally configured taxable. The deployed code records `not_collected`
and rejects a nonzero ticket or sponsorship tax ledger.

The isolated agreement regression passes 22 checks, including WooCommerce global
taxes enabled/disabled without changing the nontaxable policy, unchanged customer
totals, tampering, currency, quantities, and rejected separate tax charges. The
installed-WooCommerce fixture also verifies a $29 ticket-and-sponsorship order
with a zero tax ledger and rejects a ticket product changed to taxable.

Live WooCommerce global tax calculation is enabled. A read-only production query
confirmed all mapped adult, discount, matinee, and subscriber ticket products
have `_tax_status=none`. The agreement/schema/conversion update is deployed;
WordPress reports schema version 2 and the required column present. The homepage
and public Movie Requests page return HTTP 200; the latter renders its sign-in
state without PHP error markers. Post-deployment candidate fixtures pass 22
agreement, 7 conversion, 2 schema, 22 installed-Woo, 59 isolated attempt, 9 SQL
payment-attempt and 12 SQL creation-claim checks. Six deployed PHP files pass
lint. Existing backings remained unchanged by the fixtures.

Pre-deployment database and plugin archives are stored under
`I:\My Drive\UpdraftPlus\Roxy-Stability-20261007`; their SHA-256 hashes match
the server-side originals and the archives passed integrity checks. Google Drive
cloud synchronization was not independently verified. The one legacy pending
record is an orphan: two general tickets, $16 recorded total, June 16, 2026;
its linked request post no longer exists and it has no Woo order or payment
intent. It remains untouched and cannot be converted under the snapshot rules.
