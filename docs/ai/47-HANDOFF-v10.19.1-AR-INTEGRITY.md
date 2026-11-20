# ERPSMART AUTONOMOUS ENGINEERING HANDOFF — v10.19.1

**NOT_DEPLOYED**

## Baseline / merge
- Baseline: `f928a44988e5f1accea0228a2b629e4804210e85`
- Branch: `feature/erp-ar-integrity-v10.19.1`
- PR: https://github.com/AmirAbedini78/excel-dev/pull/11
- Merge SHA: `7df6776eb0dcce749dee5d62969db4001b727a9e`
- CI: https://github.com/AmirAbedini78/excel-dev/actions/runs/44733556265

## Bugs reproduced & fixed
| ID | Issue | Fix |
|---|---|---|
| A2 | Draft O2C invoice hard-delete orphaned `erp_o2c_invoice_links` | `cancelDraftBillingInvoice` / `deleteOrCancelSalesDraft` — void + cancel links |
| A3 | Weak request fingerprint (header totals only) | Full line+link+identity `invoiceRequestHash`; header/line reconciliation |
| A4 | Fiscal year only | `assertFiscalOpen` locks FY + `acc_period_closes` closed/locked |
| A5 | Weak account map | `account_type` + `allow_posting` + distinct AR/revenue/tax |
| A6 | Nonnegative outstanding / false zero | `signedScaled`; statuses OPEN/SETTLED/CREDIT_BALANCE/UNAVAILABLE; historical AR accounts |
| A7 | Broad PDO race recovery | Duplicate-key only + matching `request_hash` |

## Tests
- `tests/test_ar_posting_contracts_1019.py`
- `tests/test_ar_integrity_php_e2e_1019_1.py` (PHP+MySQL)
- `tests/test_ar_php_e2e_1019.py` regression

## Settlement
Still `NOT_IMPLEMENTED`.
