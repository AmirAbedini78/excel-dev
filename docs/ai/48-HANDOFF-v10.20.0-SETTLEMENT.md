# ERPSMART AUTONOMOUS ENGINEERING HANDOFF — v10.20

**NOT_DEPLOYED**

## Baseline
- Pre-feature main: `7df6776eb0dcce749dee5d62969db4001b727a9e` (v10.19.1 / PR #11)
- Branch: `feature/erp-ar-settlement-v10.20.0`

## Behavior
- Draft → approve → post customer receipt (Maker/Checker)
- GL: Dr Cash GL · Cr AR (canonical vouchers)
- Subledger allocations to posted AR invoices (partial/full)
- Invoice status OPEN / PARTIALLY_SETTLED / SETTLED
- Receipt reversal + allocation reverse; compensating voucher
- `bank_reconciled=false` always on post (receipt ≠ bank reconciliation)

## Tables
- `erp_ar_receipts`, `erp_ar_allocations`, `erp_ar_company_maps.cash_gl_account_id`

## Tests
- `tests/test_ar_settlement_contracts_1020.py`
- `tests/test_ar_settlement_php_e2e_1020.py`
