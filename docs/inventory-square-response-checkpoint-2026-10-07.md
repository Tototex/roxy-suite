# Inventory Square response fail-closed checkpoint — 2026-10-07

Review of the inventory parser found that a successful terminal Square response
with no `counts` field was accepted as an empty array. That could turn a
malformed/incomplete response into zero stock. The parser now requires the
inventory collection on terminal responses. A cursor-only page may continue,
but a terminal page must explicitly include `counts`, including when it is an
empty array. A fully empty catalog remains rejected before any inventory write.

The isolated response suite passes 50 checks, including preserved stock after
missing terminal counts, valid explicitly empty counts, cursor-only pagination,
empty catalog rejection, and pagination failures. The Inventory regression,
54 handler checks, and PHP syntax checks for the changed source and test pass.
No Square API call or production inventory mutation was made.

Square's current API reference describes `counts` as the current calculated
counts collection for requested catalog objects and locations:
[Batch retrieve inventory counts](https://developer.squareup.com/reference/square/inventory-api/batch-retrieve-inventory-counts).
