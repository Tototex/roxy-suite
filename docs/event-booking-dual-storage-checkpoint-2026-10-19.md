# Event Booking dual-storage lifecycle checkpoint — 2026-10-19

The disposable WooCommerce integration workflow now executes the Event Booking lifecycle on both legacy post-based order storage and authoritative HPOS. The fixture checks that a synthetic zero-value order creates exactly one linked private booking, decodes Woo order-item metadata, tolerates a repeated payment-complete callback without duplication, starts an adjustment cart with the correct booking identity, and intercepts email transport. Its synthetic product/order/settings/booking are cleaned up in teardown.

Hosted run [39298559668](https://github.com/Tototex/roxy-suite/actions/runs/39298559668) passed all nine jobs. The private HPOS lifecycle job reports five Event Booking checks passed on legacy storage and five on HPOS. No real payment provider or outbound email transport was used.

This closes only Event Booking's tested checkout/order-lifecycle slice. It does not certify the remaining direct post/item-table transactions in Show Tickets, Will Call, and Requested Showings; does not establish system-wide compatibility; and does not authorize enabling HPOS. The live WooCommerce storage setting remains unchanged.
