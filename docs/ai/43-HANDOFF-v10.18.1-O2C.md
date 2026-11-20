# ERPSMART HANDOFF — v10.18.1 O2C Integrity Hotfix

**NOT_DEPLOYED**

## Baseline
- `cbfd218ab15f55408628acc0baea3a0a242dee3a` (v10.18.0)
- Branch: `feature/erp-o2c-hotfix-v10.18.1`

## Bugs reproduced & fixed
| ID | Bug | Fix |
|---|---|---|
| A1 | Unique `(delivery,sales_line)` blocked partial invoices | `uq_o2c_invoice_line` + SUM≤delivered + invoice idempotency |
| A2 | Billing invoice fulfillable | `BILLING_NOTE_MARKER` + `isBillingDocument` gates |
| A3 | Availability before locks / empty lock set | `erp_o2c_stock_locks` + GET_LOCK + hard SQL revalidation |
| A4 | Idempotency after qty gates | Early idempotency + `request_hash` |

## PHP E2E
`tests/test_o2c_php_e2e_1018_1.py` via `erpsmart-o2c-php:8.2` — A1–A4 PASS locally; CI job `o2c-php-mysql-e2e` added.

## AR/Settlement
Still BLOCKED / NOT_IMPLEMENTED.
