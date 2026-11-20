# v10.20.1 — Treasury write-entry matrix

| Entry | Operation | Domain | Safeguards |
|---|---|---|---|
| `acc_ar_set_cash_account_gl` | Map cash account → GL | `setCashAccountGl` | asset+postable; audit `erp_ar_cash_gl_map_audit` |
| `acc_ar_set_cash_gl` | Company fallback GL | `setCashGlMap` | legacy only |
| `acc_ar_receipt_draft` | Draft receipt | `createDraftReceipt` | method whitelist; resolveCashGl required |
| `acc_ar_receipt_approve` | Approve | `approveReceipt` | maker/checker |
| `acc_ar_receipt_post` | Post GL+treasury | `postReceipt` | snapshot cash/AR GL; method; fiscal; bank_reconciled=false |
| `acc_ar_allocate` | Allocate | `allocate` | locks; over-alloc reject; idempotency hash |
| `acc_ar_receipt_reverse` | Reverse | `reverseReceipt` | historical voucher accounts; cash_tx reversed + compensating out; open period |

Methods supported: `cash`, `bank_transfer`, `pos`. Cheque/Sayad rejected (separate lifecycle).
