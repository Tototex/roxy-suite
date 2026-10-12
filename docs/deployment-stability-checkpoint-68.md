# Stability checkpoint 68 — member admission Undo deployment

On 2026-10-12, deployed only `show-tickets/includes/class-roxy-st-tickets.php` to the live Roxy Suite plugin. This is the exact-identity walk-up Undo change already covered by hosted private-MySQL and Door Mode interaction tests in run [37817872852](https://github.com/Tototex/roxy-suite/actions/runs/37817872852), plus the follow-up fixes for refunded-ticket Undo, explicit Will Call staff attribution, and ambiguous legacy aggregate attendance. The latest code/test run [38277431749](https://github.com/Tototex/roxy-suite/actions/runs/38277431749) passes the PHP 8.0–8.4 syntax matrix and full isolated PHP 8.3 suite; private WordPress/Woo and MySQL fixture jobs were reported as still completing at the time of deployment.

Before replacement, the live file hash exactly matched the file extracted from the verified offsite predeploy archive on `I:\My Drive\UpdraftPlus\Roxy-Stability-20261012` (`3C0A3605F5AAD9EC12184239BF97E601E21EA2114A8EE3D1C9B13A8D0CE30F8C`). The archive SHA-256 was verified as `96DB966140E34B6620694F95FBE01729F18FBBC5E816BA96BC02BAB957043602`. The deployment kept exact original and candidate copies outside the public webroot at:

`/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261012-member-undo-3d6a430b47714724a8061e909e8ee477`

The candidate passed remote PHP lint before replacement. After atomic replacement, the installed file hash was `8A676D11E3CAB596FDD31F8AB6F03BB2952293E8CB1D92EAE59F732EF332E6DE`; it matched the candidate backup, and remote PHP lint passed. The staged temporary copy was then removed and its absence verified. Deployment used sequential SSH sessions; no parallel sessions were opened.

Read-only live smoke checks after deployment:

- WordPress Ticket Ops admin rendered successfully. The Door Mode UI, event selector, and scan area loaded. No camera, scan, Admit, Undo, or other state-changing control was activated.
- The public Tickets page rendered current listings and ticket tiers. Every quantity remained zero; no cart, checkout, or order was created.

No member, ticket, attendance, order, payment, report, email, or site setting was changed by the smoke checks. This is not a complete admission workflow test; the private fixture coverage is the functional evidence. M1/M3 remain in progress because legacy historical attribution and arbitrary external writers remain unresolved. Do not infer or rewrite old attendance counts.
