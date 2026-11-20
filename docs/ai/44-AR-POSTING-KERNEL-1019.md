# v10.19 — AR Posting Kernel

**Status:** KERNEL_AVAILABLE · Settlement `NOT_IMPLEMENTED` · **NOT_DEPLOYED**

## Scope

Canonical path only:

`O2C billing invoice (draft)` → `AccountingPostingDomain::postCustomerInvoice` → `acc_vouchers` + `acc_voucher_lines` (`status=final`, `source_type=o2c_ar`) + `erp_ar_postings`.

No parallel ledger tables. No cash allocation / settlement in this release.

## Prerequisites (gated by `assessReadiness`)

1. Open fiscal year covering invoice date (`acc_fiscal_years.status=open`).
2. Company AR map (`erp_ar_company_maps`: receivable, revenue, optional tax).
3. Invoice must be O2C billing document (`OrderToCashDomain::isBillingDocument`).
4. Maker/Checker: poster ≠ invoice `created_by`.
5. Permission: `accounting.vouchers.manage`.

## Invariants enforced

| # | Invariant | Enforcement |
|---|---|---|
| 1 | Debit = credit | Integer scaled cents before insert; reject if unequal |
| 2 | Decimal precision | `PurchaseMatchingDomain::scaled/fixed` |
| 3 | Company/workspace scope | All queries filter workspace_id + company_id |
| 4 | Customer + account map | Party ownership + active accounts |
| 5 | Open fiscal period | `assertFiscalOpen` |
| 6 | Atomic posting | Single transaction |
| 7 | No double post | `uq_ar_invoice` + idempotency key + race recovery |
| 8 | Source eligibility | Billing marker; void blocked; immutable after post (`workflow_status=final` + AR_POSTED note) |
| 9 | Reversal | Not implemented in 10.19 (history-preserving reverse deferred) |
| 10 | Tax/currency | Tax line only when tax>0 and tax account mapped; IRR amounts only |
| 11 | Audit + permissions | `Audit::log` + Tenant permissions |
| 12 | Outstanding | `customerOutstanding` sums posted voucher lines for party — not invoice UI totals |

## Schema additions (backward compatible)

- `erp_ar_company_maps`
- `erp_ar_postings` (unique per invoice, per idempotency key, per voucher)

## Settlement

Explicitly `NOT_IMPLEMENTED`. Do not treat invoice as paid from UI or unposted cash drafts.
