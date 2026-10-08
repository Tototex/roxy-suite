# Will Call order-query safety — 2026-10-08

The Will Call list builder now rejects a failed or malformed WooCommerce order
query before caching any derived list. The admin view reports that no partial
list was shown. A failed fresh query during attendance save returns a clear
conflict and performs no attendance write. The aggregation stores an integer
order count instead of retaining a second map of every matched order ID.

The regression fixture uses 201 distinct orders and customer identities. It
checks eligible statuses and the 18-month cutoff, varied item quantity and
partial refunds, customer/order aggregation, query arguments, false and thrown
query failures, and that failed fresh validation cannot write attendance. The
WooCommerce date fixture implements its date wrapper API, caught by the first
hosted run and corrected before verification.

GitHub Actions run 37733864850 passed runtime PHP lint and both showing-selector
and Will Call regressions on PHP 8.0–8.4. No production code was deployed and no
live orders or attendance were changed. This does not resolve T15's indexed
showing/customer-query and cold-load latency work; it also does not claim an
atomic snapshot while WooCommerce hydrates changing orders.
