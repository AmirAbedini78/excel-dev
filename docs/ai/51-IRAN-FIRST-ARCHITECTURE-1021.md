# Iran-First ERP Architecture & Business Profiles (v10.21)

## Product identity
ERPSMART is an Iran-first, modular, multi-company ERP. Core entities are industry-neutral; profiles guide defaults only.

## Permanent constraints
- Persian RTL UX; Jalali presentation with unambiguous canonical date storage.
- Functional accounting currency: **IRR**. Toman is **display/input only** (factor 10) — never silent multiply/divide in posting.
- Iranian fiscal years + period closes (`acc_fiscal_years`, `acc_period_closes`).
- Company-scoped COA, cost centers, parties, warehouses.
- Full auditability; no parallel ledgers per industry.

## Business profiles (`BusinessProfileDomain`)
| Code | Focus | Priority cycles |
|---|---|---|
| trading | wholesale / import-export | P2P, O2C, landed cost, treasury |
| manufacturing | industrial | BOM/MRP, production, costing |
| retail | distribution | O2C, inventory |
| service | contracting | contracts, billing |
| hybrid | mixed | selective |

Profiles store on `erp_company_business_profiles`. They do not fork GL or party masters.

## Localization status
| Topic | Status | Notes |
|---|---|---|
| Jalali UI | PARTIAL | Existing Jalali helpers; continue round-trip tests |
| IRR ledger | DATABASE_TESTED | Posting/settlement IRR |
| Toman display | FOUNDATION | Explicit conversion helpers only |
| Taxpayer/e-invoice | NOT_STARTED | Requires verified legal source before enable |
| Sayad cheque | DATA_MODEL_ONLY | `acc_checks`; not cleared bank funds |
| VAT rates | NOT_STARTED | No invented statutory rates |

## Non-claims
No production deploy. No unverified compliance submission. No external bank/Sayad integration claimed.
