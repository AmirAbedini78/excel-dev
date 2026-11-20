# ERPSMART Capability Delivery Master (Gap Matrix)

**Maintained:** 2026-11-20 (system-2)
**Repo:** `C:\Projects\excel-dev`
**Merged baseline (pre-v10.18):** `f34c03a6bfcea9dd53a9a77abb40de91e9d55d08` (includes PR #5 v10.16 + PR #6 v10.17)
**In-flight / this release:** v10.18.0 Order-to-Cash on `feature/erp-o2c-v10.18.0` (**NOT_DEPLOYED**)

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
| P2P Three-Way Matching | `DATABASE_TESTED` (PR #5 merged) | `PurchaseMatchingDomain`, `PurchaseMatchingModule` | `erp_p2p_vendor_bills/lines` + canonical purchase/receipt | `page=p2p` | purchase view/manage; module `p2p` | finance+procurement | `test_p2p_three_way.py`, CI | Draft invoice only; no GL/payment; **staging/prod NOT verified** |
| P2P Fail-Closed policy + amend + concurrency | `DATABASE_TESTED` (PR #6 merged, **NOT_DEPLOYED**) | same + policy/revision/exception tables | `erp_p2p_company_policy`, `erp_p2p_bill_revisions`, `erp_p2p_policy_exceptions` | `page=p2p` | + `accounting.purchase.exception` | module enabled + enforce flag | `test_p2p_hardening_1017.py`, `test_p2p_mysql_integration_1017.py`, doc `38-*`/`39-*` | Staging/production NOT verified; GL still off |
| Manual purchase invoice entry | Fail-Closed when enforced | `AccountingIndustrialModule::savePurchase` | `acc_purchase_docs` | accounting UI | purchase manage (+ exception) | p2p enforce | guarded assert + exception audit | Exception path still allows audited bypass |
| AI purchase invoice draft | Fail-Closed when enforced | `AiToolRegistry::createPurchaseDraft` | `acc_purchase_docs` | AI proposal exec | AI + purchase | p2p enforce | same domain assert | Same |
| **O2C Confirm → Reserve → Deliver → Draft Invoice** | `DATABASE_TESTED` (sandbox) / AR `BLOCKED` | `OrderToCashDomain`, hardened `SalesDomain` | `acc_sales_*`, reservations, deliveries, stock moves, `erp_o2c_*` | industrial sales | `accounting.sales.*` | inventory | `test_o2c_contracts_1018.py`, `test_o2c_mysql_integration_1018.py`, doc `40-*` | AR/settlement not implemented; delivery reversal Gap; staging/prod NOT verified |
| O2C AR posting | `NOT_STARTED` (explicit `BLOCKED`) | — | vouchers | — | — | fiscal open + mappings | — | Do not enable until kernel proven |
| O2C Settlement / cash allocation | `NOT_STARTED` (`NOT_IMPLEMENTED`) | — | cash txns | — | — | posted AR | — | Do not fake paid status |
| Enterprise operational extensions | `CRUD_PILOT` (+ PO draft bridge) | Enterprise* | `erp_ext_*` | `enterprise_*` | enterprise.* | opt-in | enterprise tests | No stock/GL/payroll post |
| Workflow Studio | `CRUD_PILOT` | WorkflowDomain | `erp_wf_*` | workflows | workflow.* | opt-in | workflow tests | No auto GL/inventory |
| WMS FEFO/serial | `CRUD_PILOT` metadata | enterprise_wms | `erp_ext_*` + stock ledger | enterprise_wms | enterprise warehouse | inventory | enterprise tests | No FEFO engine |
| MRP / Quality / Maintenance | `CRUD_PILOT` | mfg + enterprise_factory | BOM + `erp_ext_*` | production / factory | mfg/enterprise | items | manufacturing tests | No finished-goods post |
| Financial Core / Iran localization | Partial | Accounting core | vouchers etc. | industrial | finance | — | finance docs | No legal-complete claim |
| HR / Payroll | `CRUD_PILOT` / payroll NOT legal-ready | HR + enterprise_people | employees + `erp_ext_*` | hr | hr.* | — | enterprise tests | Payroll calc NOT_STARTED |
| Revenue channels | `DATA_MODEL_ONLY`/`CRUD_PILOT` | enterprise_sales/growth | `erp_ext_*` | enterprise | enterprise | sales | enterprise tests | No POS close/storefront |
| Platform / AI writes | Partial | Copilot engine | AI jobs | AI | AI | — | release_gate | AI must not post GL/stock/payment |

---

## B. Phases

### Phase 0 — v10.17: P2P Hardening — **MERGED (PR #6), NOT_DEPLOYED**
Fail-closed entry points, amend rejected bills, MySQL concurrency sandbox. Staging/production unverified. GL/AP posting remains draft-only Gap.

### Phase 1 — v10.18: Order-to-Cash — **IN IMPLEMENTATION / THIS BRANCH**
Confirmed SO → reservation → delivery → draft customer invoice with source links. AR posting BLOCKED; settlement NOT_IMPLEMENTED. See `docs/ai/40-O2C-ARCHITECTURE-1018.md`.

### Phase 2 — v10.19 (proposed): O2C AR kernel
Only after fiscal-period, mapping, balanced voucher, and idempotent posting proofs. Then settlement allocation.

### Phase 3–8
Unchanged program: WMS → MRP/QC → Financial Core → HR/Projects → Revenue → Platform/AI. Do not start until predecessor merged and gates pass.

---

## C. Non-claims

- No `PRODUCTION_VERIFIED` capability.
- No Moadian / payroll / VAT compliance complete.
- No cPanel deploy from this workstream.
- v10.17 merged ≠ staging/production verified.
- Invoice UI totals ≠ proven AR ledger balance.
