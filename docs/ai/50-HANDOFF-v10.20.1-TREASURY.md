# ERPSMART HANDOFF — v10.20.1 Treasury Integrity

**NOT_DEPLOYED**

## Baseline / merge
| Item | Value |
|---|---|
| Pre-merge main | `f5025b3c2ad362d853a13e060257f825e28d5431` (v10.20.0) |
| Branch | `feature/erp-treasury-integrity-v10.20.1` |
| PR | [#13](https://github.com/AmirAbedini78/excel-dev/pull/13) |
| Merge SHA | `b2cc0fb6fdda0608b5d6900f7a697e8976c582e1` |
| Feature commit | `16e864a` |
| Post-merge CI | [44742035933](https://github.com/AmirAbedini78/excel-dev/actions/runs/44742035933) SUCCESS |
| Branch CI | [44741841659](https://github.com/AmirAbedini78/excel-dev/actions/runs/44741841659) SUCCESS |

## Bugs fixed
| ID | Issue | Fix |
|---|---|---|
| A2 | Company-wide cash GL for all banks | Per `acc_cash_accounts.gl_account_id` + mapping audit |
| A3 | Reverse used current map | Snapshot + original voucher lines |
| A4 | Cash tx stayed posted | Mark reversed + compensating out tx |
| A5 | Hardcoded method=bank | Explicit cash / bank_transfer / pos; cheque blocked |
| A6–A8 | Idempotency + balances | Fingerprints + `customerBalanceBreakdown` |

## Tests
- `tests/test_treasury_integrity_php_e2e_1020_1.py` (PHP+MySQL Docker)
- `tests/test_treasury_integrity_contracts_1020_1.py`
- Settlement regression E2E

## Staging smoke (not executed)
1. Disposable staging DB only.
2. Migrate through 10.20.1.
3. Two bank accounts → two GL accounts; post receipt; remap; reverse → historical GL.
4. Partial allocate 400/1000 then 600; over-allocate reject; reverse → invoice reopen.
5. Confirm cash tx reversed + compensating; no bank-reconciled claim.

**Production deploy: NOT AUTHORIZED.**
