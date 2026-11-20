# ERPSMART Capability Delivery Master (Gap Matrix)

**Maintained:** 2026-11-20 (system-2)
**Repo:** `C:\Projects\excel-dev`
**Merged baseline (v10.19.0):** `3d9385ec2f8c2405ec6187c1e6bf49403e97e61f` (PR #9)
**Prior O2C hotfix:** `2f7d5b15e37ca6093d76d9d7233ba03332c9fe31` (PR #8) · **NOT_DEPLOYED**

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
| **O2C Confirm→Reserve→Deliver→Draft Invoice** | `DATABASE_TESTED` + **PHP domain E2E** | `OrderToCashDomain`, `SalesDomain` | `acc_sales_*`, `erp_o2c_*`, stock locks | industrial sales | sales.* | inventory | `test_o2c_*1018*`, `test_o2c_php_e2e_1018_1.py` | staging/prod NOT verified |
| O2C partial invoicing | Fixed in 10.18.1 | invoice links unique per invoice line | `erp_o2c_invoice_links` | sales | sales | delivery | PHP E2E A1 | — |
| O2C billing-doc isolation | Fixed in 10.18.1 | `isBillingDocument` | marker + links | sales | sales | — | PHP E2E A2 | — |
| O2C concurrent reservation | Fixed in 10.18.1 | stock locks + GET_LOCK + revalidate | `erp_o2c_stock_locks` | — | — | — | PHP E2E A3 | — |
| O2C delivery idempotency | Fixed in 10.18.1 | early idempotency + request_hash | `erp_o2c_delivery_idempotency` | — | — | — | PHP E2E A4 | — |
| **O2C AR posting kernel** | `DATABASE_TESTED` (sandbox) · `KERNEL_AVAILABLE` | `AccountingPostingDomain` | `erp_ar_*`, `acc_vouchers`, `acc_voucher_lines` | industrial sales + vouchers | vouchers.manage + map | open fiscal + AR map | `test_ar_*1019*` | Staging/prod NOT; reversal deferred |
| O2C Settlement | `NOT_STARTED` (`NOT_IMPLEMENTED`) | — | cash | — | — | posted AR proven | — | after AR staging |

---

## B. Phases

### v10.17 — MERGED (PR #6), NOT_DEPLOYED
### v10.18.0 — MERGED (PR #7), NOT_DEPLOYED
### v10.18.1 — MERGED (PR #8), NOT_DEPLOYED — O2C integrity hotfix + PHP E2E
### v10.19.0 — MERGED (PR #9), NOT_DEPLOYED — AR posting kernel (settlement blocked)

---

## C. Non-claims

- No `PRODUCTION_VERIFIED` capability.
- No cPanel deploy from this workstream.
- Invoice UI totals ≠ paid; outstanding derives from posted voucher lines only when AR posted.
- Settlement is explicitly NOT_IMPLEMENTED.
