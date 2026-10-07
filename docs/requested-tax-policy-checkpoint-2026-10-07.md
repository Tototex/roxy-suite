# Requested Showings tax policy checkpoint — 2026-10-07

The approved customer-facing policy is now explicit in the agreement candidate:
ticket and sponsorship amounts are final whole-dollar prices, and any applicable
tax is absorbed by Roxy. Taxes enabled in WooCommerce must not add a separate
customer charge. Agreement data records `tax_absorbed_by_roxy`; a nonzero tax
line is rejected as inconsistent with the agreed total.

The isolated agreement regression passes 22 checks, including tax-enabled and
tax-disabled quotes, unchanged customer totals, tampering, currency, quantities,
price changes, and rejected separate tax charges.

The helper is not deployed alone. The live conversion path still needs the
agreement snapshot persisted with each new backing and applied to the Woo order
line totals before conversion is enabled. This avoids claiming protection while
the existing conversion path could still read current product prices.
