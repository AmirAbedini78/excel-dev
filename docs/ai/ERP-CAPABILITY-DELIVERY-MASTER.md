# ERPSMART Capability Delivery Master (Gap Matrix)

**Maintained:** 2026-11-20 (system-2)
**Repo:** `C:\Projects\excel-dev`
**Merged baseline (pre-v10.18.1):** `cbfd218ab15f55408628acc0baea3a0a242dee3a` (v10.18.0 via PR #7)
**In-flight:** v10.18.1 O2C integrity hotfix on `feature/erp-o2c-hotfix-v10.18.1` (**NOT_DEPLOYED**)

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
| **O2C Confirm→Reserve→Deliver→Draft Invoice** | `DATABASE_TESTED` + **PHP domain E2E** (sandbox) | `OrderToCashDomain`, `SalesDomain` | `acc_sales_*`, `erp_o2c_*`, stock locks | industrial sales | sales.* | inventory | `test_o2c_*1018*`, `test_o2c_php_e2e_1018_1.py` | AR/settlement blocked; staging/prod NOT verified |
| O2C partial invoicing | Fixed in 10.18.1 | invoice links unique per invoice line | `erp_o2c_invoice_links` | sales | sales | delivery | PHP E2E A1 | — |
| O2C billing-doc isolation | Fixed in 10.18.1 | `isBillingDocument` | marker + links | sales | sales | — | PHP E2E A2 | — |
| O2C concurrent reservation | Fixed in 10.18.1 | stock locks + GET_LOCK + revalidate | `erp_o2c_stock_locks` | — | — | — | PHP E2E A3 | — |
| O2C delivery idempotency | Fixed in 10.18.1 | early idempotency + request_hash | `erp_o2c_delivery_idempotency` | — | — | — | PHP E2E A4 | — |
| O2C AR posting | `NOT_STARTED` (`BLOCKED`) | — | vouchers | — | — | fiscal+mappings | — | v10.19 candidate |
| O2C Settlement | `NOT_STARTED` | — | cash | — | — | posted AR | — | after AR |

---

## B. Phases

### v10.17 — MERGED (PR #6), NOT_DEPLOYED
### v10.18.0 — MERGED (PR #7), NOT_DEPLOYED — integrity bugs found post-merge
### v10.18.1 — IN FLIGHT — integrity hotfix + real PHP E2E
### v10.19 — AR posting kernel (only after 10.18.1 merges)

---

## C. Non-claims

- No `PRODUCTION_VERIFIED` capability.
- No cPanel deploy from this workstream.
- Invoice UI totals ≠ proven AR ledger balance.
