# Square refund webhook event capture checkpoint — 2026-10-08

## Change

- Added an anonymous WordPress REST receiver for Square `refund.updated` notifications. The endpoint verifies Square's HMAC-SHA256 signature using the raw request body, configured notification URL, and encrypted webhook signature key; invalid signatures fail closed.
- Added an additive verified schema (`verified-2`) with a unique event ID and minimal refund fields. Duplicate deliveries with identical payloads are acknowledged idempotently; a conflicting signed duplicate is not stored and is logged without disclosing payload data. Database write failures return a retryable server error.
- Added an encrypted Square webhook signature-key setting and displayed the exact REST notification URL to configure in Square.
- Stored provider event creation time separately from the Square refund object's own `created_at` and `updated_at`. A `refund.updated` event can evidence the provider status change, but the event timestamp is not a guarantee of when a cardholder's bank posts funds. This receiver does not change Grosses reports, ticket counts, collections, or refund-day calculations.

## Verification

- Focused fixtures cover Square's published HMAC example, valid signature, normalized UTC timestamps and cents, identical retry, conflicting duplicate, invalid signature, unrelated signed event, malformed JSON, and unconfigured endpoint.
- Square defines the signature input as signature key + configured notification URL + raw request body and requires constant-time comparison; its refund event reference documents status updates. See [Square webhook signature validation](https://developer.squareup.com/docs/webhooks/step3validate) and [refund.updated reference](https://developer.squareup.com/reference/square/webhooks/refund.updated).
- Schema-bootstrap fixture verifies that the version is not stamped when a required table/index is missing and that a retry succeeds. Credential regression verifies that the new signature key is encrypted and blank settings saves preserve existing keys.
- Hosted workflow [37765206700](https://github.com/Tototex/roxy-suite/actions/runs/37765206700) passed PHP 8.0–8.4 syntax, PHP 8.3 full isolated regressions, release manifest checks, and deterministic package validation.
- Local PHP execution was unavailable. No production deployment, Square Developer Console subscription setup, live webhook delivery, or live financial reconciliation was performed.

## Remaining

- Configure the signature key and create a Square `refund.updated` subscription using the displayed URL after deployment; validate the exact URL through Square's test delivery.
- Use persisted status-transition evidence to improve the refund-day projection only after matching it against the current refund identity/status/amount and defining safe fallback behavior. `updated_at` remains the existing explicitly labeled proxy; current financial dashboards remain unchanged.
- Reconcile future webhook capture against Square's refund list and inspect missing/duplicate deliveries. Do not rewrite historical financial totals or auto-resend reports.
