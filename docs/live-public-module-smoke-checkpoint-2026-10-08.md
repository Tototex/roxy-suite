# Live public module smoke — 2026-10-08

Read-only unauthenticated HTTP checks against the production site returned 200 for:

- Home, `/tickets/`, `/membership-registration/`, `/roxy-arcade/`, `/rent-the-roxy/`, `/movie-requests/`, and `/newport-roxy-theater/`.
- The ticket listing contains Show Tickets markup (`roxy-st-grid`, ticket rows, and a sold-out state where applicable).
- The private-event page contains the booking calendar and Book Now control and says the capacity is 250 guests.
- Movie Requests renders its request form and a truthful empty-state message (no active requests currently).
- The Arcade and membership-registration pages contain their module content.
- None of the returned pages contained a PHP fatal/uncaught error or the generic WordPress critical-error page.

This checks public rendering only. No form was submitted; no cart, reservation, account, payment, mail, publisher action, or scheduled hook was created or run. Authenticated admin screens, client-side browser errors, checkout completion, and provider workflows remain separate checks.
