# Booking cancellation revision checkpoint

## Change

Cancellation must call payment providers outside the room-reservation transaction. It now carries the booking snapshot revision into the final status update. If an administrator/customer changes the booking while a provider refund is in flight, the final cancellation update fails as stale rather than overwriting those new values. Existing refund idempotency claims prevent a retry from submitting the same gateway refund twice; the booking stays reserved and requires a deliberate retry/review after the concurrent change. The Woo full-refund hook now respects cancellation failure and does not clear reminders or run cancellation follow-up after a missing order/provider/save failure.

## Verification

An isolated regression changes guest count and booking revision while the refund provider call is being simulated. It verifies the cancellation does not replace the concurrent edit, does not enqueue cancellation side effects, and does not make a second provider call. The actual Woo refund hook is also exercised with a missing linked order to verify it retains the reminder and booking. Existing missing-order, declined-refund, successful-cancel and post-refund-save-failure/retry cases remain. Hosted PHP matrix/full-suite verification is pending. No live booking or payment was changed.

## Remaining

This narrows a cancellation race but does not establish a complete inventory of every booking/reservation writer or every external provider callback race. It is not production deployment certification.
