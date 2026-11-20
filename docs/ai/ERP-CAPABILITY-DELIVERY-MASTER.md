# ERPSMART Capability Delivery Master (Gap Matrix)

**Maintained:** 2026-11-20 (system-2)
**Repo:** `C:\Projects\excel-dev`
**In-flight baseline:** `f928a44988e5f1accea0228a2b629e4804210e85`
**In-flight:** v10.19.1 AR integrity on `feature/erp-ar-integrity-v10.19.1` (**NOT_DEPLOYED**)

Status vocabulary (exactly one per capability):

| Status | Meaning |
|---|---|
| `NOT_STARTED` | No usable domain/UI/tests for the cycle |
| `DATA_MODEL_ONLY` | Tables/metadata exist; no safe transactional cycle |
| `CRUD_PILOT` | Create/edit/search + permissions; no posting/ledger effects |
| `INTEGRATED_DRAFT` | Links to canonical docs as draft; no GL/stock/payment post |
| `SOURCE_TESTED` | Source/contract proofs for bounded cycle |
| `DATABASE_TESTED` | Disposable MySQL sandbox proofs (+ concurrency where claimed) |
| `STAGING_VERIFIED` | Staging MySQL + smoke E2E passed with backup plan |
| `PRODUCTION_VERIFIED` | Live evidence with rollback rehearsed — **none claimed below** |

**Rule:** existence of a table, menu, or contract test ≠ Production Complete.

---

## A. Cycle matrix (current evidence)

| Capability / Cycle | Status | Primary files | Tables | Page / route | Permissions | Prerequisites | Tests / evidence | Gap |
|---|---|---|---|---|---|---|---|---|
| P2P Three-Way Matching | `DATABASE_TESTED` (PR #5) | PurchaseMatching* | `erp_p2p_*` | `page=p2p` | purchase | finance | p2p tests | Draft only; staging/prod NOT verified |
| P2P Fail-Closed | `DATABASE_TESTED` (PR #6, **NOT_DEPLOYED**) | policy/amend | policy tables | `page=p2p` | + exception | enforce flag | 1017 mysql | GL still off |
| **O2C Confirm→Reserve→Deliver→Draft Invoice** | `DATABASE_TESTED` + PHP E2E | `OrderToCashDomain`, `SalesDomain` | `acc_sales_*`, `erp_o2c_*` | industrial sales | sales.* | inventory | `test_o2c_*` | staging/prod NOT |
| **O2C AR posting kernel + integrity** | `DATABASE_TESTED` (sandbox) | `AccountingPostingDomain` | `erp_ar_*`, vouchers, period_closes | sales + vouchers | vouchers.manage + map | open FY + period + typed map | `test_ar_*1019*` | staging/prod NOT; reversal deferred |
| O2C billing cancel (no orphan links) | Fixed in 10.19.1 | `cancelDraftBillingInvoice` | link `status` | sales | sales.manage | draft billing | integrity E2E A2 | — |
| O2C Settlement | `NOT_STARTED` (`NOT_IMPLEMENTED`) | — | cash | — | — | integrity AR | — | v10.20 candidate |

---

## B. Phases

### v10.18.1 — MERGED (PR #8), NOT_DEPLOYED
### v10.19.0 — MERGED (PR #9), NOT_DEPLOYED
### v10.19.1 — IN FLIGHT — AR integrity hardening
### v10.20 — Settlement (only after 10.19.1 merges)

---

## C. Non-claims

- No `PRODUCTION_VERIFIED` capability.
- No cPanel deploy from this workstream.
- Settlement is explicitly NOT_IMPLEMENTED until v10.20 gates pass.
