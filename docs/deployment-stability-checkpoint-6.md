# Door Mode member admission checkpoint

Selective production files: Show Tickets ticket class and Door Mode JS. Private rollback originals under `/home1/anrvxfmy/deploy-backups/roxy-suite-stability-20261003-054238/checkpoint6`; live originals matched Git HEAD after line-ending normalization. No schema change.

- Validation requires explicit `auto_admit=1` before member admission. Off/missing flag is read-only verification; JS sends current checkbox state and supplies an explicit Admit Member button.
- Reserved members: only eligible tickets considered; actual changed ticket count logged/reported. All-invalid reservation returns failure without inventing walk-up attendance. Log failure returns zero successful admission in response, not requested quantity.
- Seven isolated PHP assertions exercise real validation/admission methods with mocked WP/orders/log; unrelated statistics/cache calls bypassed. Three JS assertions execute actual script with mocked DOM/network, covering off/on and manual button. PHP syntax and JS syntax pass. Existing ticket eligibility/refund suite passes against deployed class.
- Live Door Mode loads updated versioned JS, Auto Admit checkbox can be turned off, event selector refresh checked. No actual member admitted, no camera/NFC end-to-end scan claimed.

Reserved-ticket writes preceding a failed member-log insert are still not transactionally atomic. Concurrent server admission/retry, capacity and undo reconciliation remain open. Do not equate these quantity/UI fixes with resolving those separate findings.
