# Newport Roxy Website Editing Runbook

Read this file before changing or deploying the Newport Roxy WordPress site.

## Project

- Local repository: `C:\Users\joker\Documents\GitHub\roxy-suite`
- GitHub remote: `https://github.com/Tototex/roxy-suite.git`
- Primary branch: `master`
- Production site: `https://newportroxy.com`
- Public booking page: `https://newportroxy.com/rent-the-roxy/`
- WordPress installation: `/home1/anrvxfmy/public_html`
- Live plugin: `/home1/anrvxfmy/public_html/wp-content/plugins/roxy-suite/`

## Bluehost SSH access

- Host/IP: `50.6.19.98` (`newportroxy.com`)
- Port: `22`
- Username: `anrvxfmy`
- Local private key: `C:\Users\joker\.ssh\codex_bluehost_roxy`
- Public-key record name in Bluehost: `id_rsa.pub`

Use key authentication only. Do not put private-key contents, passwords, Square tokens, Sling credentials, or WordPress credentials in this repository or in chat.

PowerShell connection:

```powershell
ssh -i C:\Users\joker\.ssh\codex_bluehost_roxy anrvxfmy@50.6.19.98
```

## SSH connection limits

Bluehost access is intentionally rate-limited to prevent lockouts.

- Use no more than two simultaneous SSH connections.
- Prefer one persistent SSH session for most work.
- Never run parallel SSH or SCP commands.
- Upload files sequentially, then close the session.
- Avoid rapid reconnect attempts.
- If authentication fails, stop; do not repeatedly retry.
- If the server starts refusing connections, wait for the block to clear before trying again.

## Safe deployment workflow

1. Inspect the local worktree and preserve unrelated user changes.
2. Edit and test locally first.
3. Run relevant JavaScript syntax checks, PHP lint where available, and a browser smoke test.
4. Inspect the corresponding live files before overwriting them. The live plugin worktree may contain unrelated uncommitted production edits.
5. Create a timestamped backup under `/home1/anrvxfmy/deploy-backups/`.
6. Upload only changed files, one at a time.
7. Verify the live page, served assets, version query string, and the affected workflow.
8. Commit and push local changes only when explicitly requested or when part of the current task.

Example backup pattern:

```text
/home1/anrvxfmy/deploy-backups/roxy-suite-YYYYMMDD-HHMMSS/
```

## Asset and cache rules

The public site currently serves event-booking assets from the top-level plugin asset directory:

```text
/home1/anrvxfmy/public_html/wp-content/plugins/roxy-suite/assets/event-booking/
```

The module directory also contains a fallback/source copy:

```text
/home1/anrvxfmy/public_html/wp-content/plugins/roxy-suite/includes/modules/event-booking/assets/
```

When changing the booking frontend, keep both copies synchronized. Verify the top-level `assets/event-booking/` copy because that is the actively served copy. Bump the event-booking version when an asset change must invalidate browser/CDN caches.

Calendar availability requests must remain non-cacheable POST requests. Do not restore cacheable GET requests for date-range availability.

## Booking system notes

- Event booking code is under `includes/modules/event-booking/`.
- The booking frontend uses FullCalendar from jsDelivr.
- The booking page uses a 48-hour lead-time rule by default; same-day and near-term times can correctly appear disabled.
- Booking data is returned through the `roxy_eb_calendar_blocks` AJAX action.
- Existing showings and private bookings are stored in the event-booking/WooCommerce-related WordPress data and should be checked before assuming data is missing.
- A successful booking last week does not prove every date is eligible; availability depends on lead time, showings, blocks, operating hours, and existing reservations.

## Verification checklist

- Open the booking page in a clean or refreshed browser session.
- Confirm the calendar loads without JavaScript errors.
- Click a future date and confirm the modal opens with time choices when the date is eligible.
- Test “Book now” from the current date; it should find the next eligible date rather than silently showing an empty time list.
- Test both month and week views.
- Confirm showings and rentals retain their intended colors.
- Exercise the public AJAX endpoint with two different date ranges to detect stale cached responses.
- Confirm the live asset version and content match the deployed files.
- For production deploys, verify backups exist before upload.

## Monitoring

The event-booking module includes a daily health-check job intended to verify the booking page, frontend JavaScript asset, and availability response. It emails the configured Event Booking internal email address when a failure is detected, with at most one notice per day while the issue persists.

After deployment, verify that the WordPress cron hook `roxy_eb_daily_health_check` is scheduled and that the admin health dashboard reports it as scheduled. WP-Cron depends on site traffic unless the hosting account has a real server cron configured.

## Recovery

If a deployment causes a regression, stop further uploads, keep the backup directory, and restore only the affected file(s) from the timestamped backup. Re-test the public booking page and document the failure before making another change.
