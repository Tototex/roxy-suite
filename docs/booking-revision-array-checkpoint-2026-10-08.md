# Event Booking revision snapshot checkpoint — 2026-10-08

The booking revision fingerprint included `woo_adjustment_order_ids` by blindly casting every field to a string. Database rows normally contain JSON text, but an array-valued row could emit a PHP warning and produce the wrong comparison input. Revision snapshots now retain scalar values as strings and encode non-scalars as JSON, avoiding warnings while ensuring collection changes alter the revision.

The booking/refund regression includes a stable-snapshot and changed-adjustment-ID assertion. The focused suite passes locally under PHP 8.3 with no warnings; PHP 8.0 compatibility is covered by the hosted workflow. No bookings, orders, refunds, or production data were changed.
