# ERPSMART HANDOFF — v10.20.1 Treasury Integrity

**NOT_DEPLOYED**

## Baseline
- `f5025b3c2ad362d853a13e060257f825e28d5431` (v10.20.0)
- Branch: `feature/erp-treasury-integrity-v10.20.1`

## Bugs fixed
| ID | Issue | Fix |
|---|---|---|
| A2 | Company-wide cash GL for all banks | Per `acc_cash_accounts.gl_account_id` + audit |
| A3 | Reverse used current map | Snapshot + original voucher lines |
| A4 | Cash tx stayed posted | Mark reversed + compensating out tx |
| A5 | Hardcoded method=bank | Explicit cash/bank_transfer/pos; cheque blocked |
| A6–A7 | Balance semantics | customerBalanceBreakdown; historical accounts |

## Tests
- `tests/test_treasury_integrity_php_e2e_1020_1.py`
- Settlement regression E2E
