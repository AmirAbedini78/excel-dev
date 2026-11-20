# ERPSMART HANDOFF — v10.18.1 O2C Integrity Hotfix

**NOT_DEPLOYED**

## Baseline / merge
- Pre-feature main: `cbfd218ab15f55408628acc0baea3a0a242dee3a` (v10.18.0 / PR #7)
- Feature branch: `feature/erp-o2c-hotfix-v10.18.1`
- PR: https://github.com/AmirAbedini78/excel-dev/pull/8
- Merge SHA: `2f7d5b15e37ca6093d76d9d7233ba03332c9fe31`
- Post-merge CI: green (Commercial MVP Gate)

## Bugs reproduced & fixed
| ID | Bug | Fix |
|---|---|---|
| A1 | Unique `(delivery,sales_line)` blocked partial invoices | Dropped delivery-line unique; `uq_o2c_invoice_line` + SUM≤delivered + invoice idempotency |
| A2 | Billing invoice fulfillable as another order | `BILLING_NOTE_MARKER` + `isBillingDocument` gates on confirm/reserve/deliver |
| A3 | Availability computed before reservation locks | `erp_o2c_stock_locks` + GET_LOCK + hard SQL revalidation under lock |
| A4 | Qty gates before delivery idempotency | Early idempotency + `request_hash`; reject key reuse with different payload |

## Schema / compatibility
- Additive O2C tables/indexes; versioned migrate; no production deploy

## PHP + MySQL E2E
- `tests/test_o2c_php_e2e_1018_1.py` via `erpsmart-o2c-php:8.2` + MySQL `:3307`
- A1–A4 PASS on sandbox; concurrent reserve via independent PHP processes
- CI workflow E2E job: local only if workflow OAuth scope blocks push (source gate still mandatory)

## Changed files (high level)
- `app/Core/OrderToCashDomain.php`, `app/Core/SalesDomain.php`
- Harness under `tests/harness/*`
- Docs 40/42/43 + capability master

## Remaining risks (lower priority)
- Delivery reversal/cancellation incomplete
- Multi-warehouse reservation replacement edge cases
- Staging/prod not verified

## Next
v10.19 AR posting kernel (after this merge) — see `45-HANDOFF-v10.19.0-AR.md`
