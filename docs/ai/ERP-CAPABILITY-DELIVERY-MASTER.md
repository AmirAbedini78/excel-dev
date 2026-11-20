# ERPSMART Capability Delivery Master (Gap Matrix)

**Maintained:** 2026-11-20 (system-2)
**Repo:** `C:\Projects\excel-dev`
**Merged baseline:** `origin/main` includes v10.16 via PR #5 merge `c1db53244599763e8c2a07dd33b8a90e061a09f1`
**In-flight:** v10.17.0 P2P Hardening on `feature/erp-p2p-hardening-v10.17.0` (**NOT_DEPLOYED**)

Status vocabulary (exactly one per capability):

| Status | Meaning |
|---|---|
| `NOT_STARTED` | No usable domain/UI/tests for the cycle |
| `DATA_MODEL_ONLY` | Tables/metadata exist; no safe transactional cycle |
| `CRUD_PILOT` | Create/edit/search + permissions; no posting/ledger effects |
| `INTEGRATED_DRAFT` | Links to canonical docs as draft; no GL/stock/payment post |
| `TRANSACTIONAL_TESTED` | Source/contract (+ ideally concurrency) proofs for bounded cycle; no production DB proof |
| `STAGING_VERIFIED` | Staging MySQL + smoke E2E passed with backup plan |
| `PRODUCTION_VERIFIED` | Live evidence with rollback rehearsed — **none claimed below** |

**Rule:** existence of a table, menu, or contract test ≠ Production Complete.

---

## A. Cycle matrix (current evidence)

| Capability / Cycle | Status | Primary files | Tables | Page / route | Permissions | Prerequisites | Tests / evidence | Gap |
|---|---|---|---|---|---|---|---|---|
| P2P Three-Way Matching | `TRANSACTIONAL_TESTED` | `PurchaseMatchingDomain`, `PurchaseMatchingModule` | `erp_p2p_vendor_bills/lines` + canonical purchase/receipt | `page=p2p` | purchase view/manage; module `p2p` | finance+procurement | `test_p2p_three_way.py`, PHP contract, CI | Draft invoice only; no GL/payment |
| P2P Fail-Closed policy + amend + concurrency | `TRANSACTIONAL_TESTED` (local MySQL sandbox) | same + policy/revision/exception tables | `erp_p2p_company_policy`, `erp_p2p_bill_revisions`, `erp_p2p_policy_exceptions` | `page=p2p` policy/amend UI | + `accounting.purchase.exception` | module enabled + enforce flag | `test_p2p_hardening_1017.py`, `test_p2p_mysql_integration_1017.py`, doc `38-*` | Staging/production NOT verified; GL still off |
| Manual purchase invoice entry | Fail-Closed when enforced | `AccountingIndustrialModule::savePurchase` | `acc_purchase_docs` | accounting UI | purchase manage (+ exception) | p2p enforce | guarded assert + exception audit | Exception path still allows audited bypass |
| AI purchase invoice draft | Fail-Closed when enforced | `AiToolRegistry::createPurchaseDraft` | `acc_purchase_docs` | AI proposal exec | AI + purchase | p2p enforce | same domain assert | Same |
| Enterprise operational extensions | `CRUD_PILOT` (+ PO draft bridge) | Enterprise* | `erp_ext_*` | `enterprise_*` | enterprise.* | opt-in | enterprise tests | No stock/GL/payroll post |
| Workflow Studio | `CRUD_PILOT` | WorkflowDomain | `erp_wf_*` | workflows | workflow.* | opt-in | workflow tests | No auto GL/inventory |
| Order-to-Cash full cycle | Partial | SalesDomain / CRM | `acc_sales_*` | sales/crm | sales | inventory | sales tests | Not end-to-end complete |
| WMS FEFO/serial | `CRUD_PILOT` metadata | enterprise_wms | `erp_ext_*` + stock ledger | enterprise_wms | enterprise warehouse | inventory | enterprise tests | No FEFO engine |
| MRP / Quality / Maintenance | `CRUD_PILOT` | mfg + enterprise_factory | BOM + `erp_ext_*` | production / factory | mfg/enterprise | items | manufacturing tests | No finished-goods post |
| Financial Core / Iran localization | Partial | Accounting core | vouchers etc. | industrial | finance | — | finance docs | No legal-complete claim |
| HR / Payroll | `CRUD_PILOT` / payroll NOT legal-ready | HR + enterprise_people | employees + `erp_ext_*` | hr | hr.* | — | enterprise tests | Payroll calc NOT_STARTED |
| Revenue channels | `DATA_MODEL_ONLY`/`CRUD_PILOT` | enterprise_sales/growth | `erp_ext_*` | enterprise | enterprise | sales | enterprise tests | No POS close/storefront |
| Platform / AI writes | Partial | Copilot engine | AI jobs | AI | AI | — | release_gate | AI must not post GL/stock/payment |

---

## B. Phases

### Phase 1 — v10.17: P2P Hardening — **IN IMPLEMENTATION**
Fail-closed entry points, amend rejected bills, MySQL concurrency sandbox. GL/AP posting remains draft-only Gap.

### Phase 2 — v10.18: Order-to-Cash
After v10.17 merge + gates.

### Phase 3–8
Unchanged program: WMS → MRP/QC → Financial Core → HR/Projects → Revenue → Platform/AI. See prior roadmap; do not start until predecessor merged.

---

## C. Non-claims

- No `PRODUCTION_VERIFIED` capability.
- No Moadian / payroll / VAT compliance complete.
- No cPanel deploy from this workstream.
