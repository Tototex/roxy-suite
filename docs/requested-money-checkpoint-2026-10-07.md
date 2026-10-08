# Requested Showings currency units

The installed code no longer guesses units by multiplying stored amounts below 1,000 by 100. Integer cents remain exact, including $1, $5 and $9.99. New writes mark their cents-unit version. Explicit malformed/unsupported/out-of-range request amounts require review, rather than becoming a price or a completed daily review. Decimal input uses integer parsing and checks the supported boundary; invalid edits preserve saved metadata/settings.

Free subscriber reservations validate no unused ticket prices. Paid backing validates only selected ticket types; public forms omit invalid paid options while retaining subscriber reservations. Main review caught and corrected the initial all-price validation that blocked free pledges.

## Evidence

- Six suites pass before/after installation: 36 money, 36 funding/read, 61 entitlement/conversion, 14 privacy, 17 mocked-provider status and 59 payment/creation/pledge checks. No real payment/provider used.
- 20 actual WordPress checks pass before/after, with one private draft/meta fixture. Original request/meta digests remain identical, fixture is deleted, and mail is prohibited. Existing request count was zero; no historic unit migration or customer backing was changed.
- Four files passed syntax checks before copying; installed hashes equal the local candidates. All 78 installed PHP files pass syntax checks. Public homepage still renders its existing $20 live-show ticket form.
- Exact pre-deployment rollback files were checksum verified at `I:\My Drive\Roxy Site Recovery\2026-10-07\inventory-pricing-and-requested-money`, including the current conversion worker rather than its earlier pre-telemetry version. Cloud sync is not asserted.

R5 remains in progress: unmarked historical integer metadata is interpreted under the existing cents contract, not independently proven units. Persisted default settings still cast/clamp malformed stored values and need a separate controlled-error/repair pass. Immutable agreements, live payment-provider lifecycle and complete authenticated browser backing flows are not certified by this checkpoint. Unrelated agreement work was not edited or deployed.
