# ERPSMART Capability Delivery Master (Gap Matrix)

**Maintained:** 2026-11-19 (system-2)
**Repo:** `C:\Projects\excel-dev`
**Baseline at matrix write:** `origin/main` = `aed25a29afe6862e291b4b7c87f82cfdeee5ba13` (merged PR #4 / v10.15)
**In-flight release:** v10.16.0 P2P Three-Way Matching — branch `feature/erp-p2p-three-way-v10.16.0` / HEAD `12f280809990f0a449f38c590cbe98bd09df01a6` (feature `5da53bf…`) / PR #5 (**not merged**; **NOT_DEPLOYED**; CI release-gate success on HEAD)

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
| P2P Three-Way Matching (PO→Receipt→Vendor Bill→draft invoice) | `TRANSACTIONAL_TESTED` (source + CI green; staging DB not verified) | `app/Core/PurchaseMatchingDomain.php`, `app/Modules/PurchaseMatchingModule.php` | `acc_purchase_docs/lines`, `acc_inventory_receipts/lines`, `erp_p2p_vendor_bills`, `erp_p2p_vendor_bill_lines` | `index.php?page=p2p` | `accounting.purchase.view/manage`, `procurement.view`; module `p2p` opt-in | finance + procurement (+ inventory via depends) | `tests/test_p2p_three_way.py`, `tests/php_p2p_contract_test.php`, `scripts/p2p_preflight.php`, doc `36-*`, Actions run 44601762317 | No GL/AP/payment; manual invoice bypass remains; rejected bill no resubmit same number; no MySQL concurrency suite on system-2 |
| Enterprise operational extensions (8 modules / 41 tables) | `CRUD_PILOT` (+ one `INTEGRATED_DRAFT` PO bridge) | `EnterpriseDomain.php`, `EnterpriseModule.php`, `EnterpriseCatalog.php` | `erp_ext_*` (41) | `enterprise_*` pages | `enterprise.*.view/manage/approve` | modules opt-in | `tests/test_enterprise_extensions.py`, `tests/php_enterprise_suite_contract_test.php`, doc `35-*` | No stock/GL/payroll/payment posting; RFQ→draft PO only |
| Workflow Studio | `CRUD_PILOT` | `WorkflowDomain.php` + module | `erp_wf_*` (4 additive) | workflow pages | workflow permissions + pilot opt-in | — | `tests/test_workflow_studio.py` | No auto GL/inventory from case approval |
| Purchase Order / Receipt (canonical) | `TRANSACTIONAL_TESTED` (existing) | `InventoryDomain.php`, Accounting purchase paths | `acc_purchase_*`, `acc_inventory_receipts*`, `acc_stock_movements` | accounting / inventory | purchase + inventory perms | finance/inventory | existing suite + P2P guards | Concurrent PO edit not globally locked for all editors |
| Manual purchase invoice entry | `INTEGRATED_DRAFT` / bypass risk | `AccountingIndustrialModule.php` | `acc_purchase_docs` | accounting UI | purchase manage | — | delete guards added for P2P deps | **Can bypass three-way policy** until v10.17 policy |
| Order-to-Cash (Quote→SO→Reserve→Deliver→Invoice→AR→Settle) | `CRUD_PILOT` / partial transactional | `SalesDomain.php`, CRM modules | `acc_sales_*`, stock moves | sales / CRM | sales perms | inventory | sales fulfillment agent tests | Partial delivery/invoice policy incomplete; AR from UI totals not always ledger-proven |
| WMS Lot/Serial/FEFO/Barcode | `DATA_MODEL_ONLY` → `CRUD_PILOT` metadata | Enterprise WMS records | `erp_ext_*` locations/lots + `acc_stock_movements` | `enterprise_wms` | enterprise wms | inventory | enterprise tests | No FEFO engine, no negative-stock hard policy proven, no barcode runtime |
| MRP / Quality / Maintenance / PLM | `CRUD_PILOT` / pilot | Manufacturing pilot + enterprise_factory | BOM/plan + `erp_ext_*` | mfg / enterprise_factory | mfg + enterprise | items | `tests/test_manufacturing_pilot.py` | No finished-goods post, WIP, costing |
| Financial Core (double-entry, periods, AR/AP, bank, VAT IR) | Partial existing + gaps | Accounting core | `acc_vouchers*` etc. | accounting | finance | — | finance docs/tests partial | No claim of full period lock / Moadian / multicurrency complete |
| HR / Payroll / Project-to-Invoice | `CRUD_PILOT` / NOT for payroll | enterprise_people + HR core | employees + `erp_ext_*` | HR / enterprise | HR perms | — | enterprise tests | Payroll calc / insurance / tax **NOT_STARTED** as legal-ready |
| Revenue channels (POS, eCom, Subscriptions runtime) | `DATA_MODEL_ONLY` / `CRUD_PILOT` | enterprise_sales / growth | `erp_ext_*` | enterprise pages | enterprise | sales | enterprise tests | No POS close, no storefront, no recurring biller |
| Platform / Documents / Analytics / AI write tools | Partial | Copilot `engine/`, docs metadata | AI jobs + `erp_ext_*` docs | AI / enterprise_knowledge | AI + enterprise | — | AI suite + release_gate | AI must not post GL/stock/payment; write = proposal→approval only |

---

## B. Planned phases (ready-to-execute; do not start code until prior PR merge + gates)

### Phase 1 — v10.17: P2P Hardening + AP/Financial Boundary
**Entry gate:** PR #5 merged OR baseline SHA includes v10.16; staging MySQL available for concurrency tests; accounting posting API audited.
**Exit gate:** Fail-closed three-way policy on all invoice entry paths OR explicit audited exception; rejected-bill revision path; tolerance/exception queue; GL/AP only if canonical posting proven atomic+balanced — else remain draft and document Gap.
**Smallest safe next slice after merge:** (1) central invoice-entry policy flag Fail Closed for companies with `p2p` enabled; (2) MySQL concurrency tests for dual approve; (3) rejected-bill amend with history.

### Phase 2 — v10.18: Order-to-Cash
Depends on inventory reservation/delivery correctness and invoice draft policy. Reuse `SalesDomain` + sales docs; no parallel AR ledger.

### Phase 3 — v10.19: WMS
Depends on `acc_stock_movements` audit; FEFO only with real expiry lots.

### Phase 4 — v10.20: MRP + Quality + Maintenance + PLM
Depends on WMS consumption/receipt transactions.

### Phase 5 — v10.21: Financial Core + Iran localization (truthful)
Depends on posting kernel + period lock; legal claims require accountant sign-off.

### Phase 6 — v10.22: HR / Project / Service / Payroll
Payroll remains draft until legal rules validated.

### Phase 7 — v10.23: Revenue channels
Only after O2C + inventory + accounting drafts are stable.

### Phase 8 — v10.24+: Platform / Workflow / Docs / Analytics / AI
AI writes stay proposal→human approval→deterministic execution.

---

## C. Evidence links for v10.16 delta

| Item | Evidence |
|---|---|
| Domain methods | `PurchaseMatchingDomain::migrate`, `approveOrder`, `submit`, `approve`, `reject`, match qty/price helpers |
| Module actions | `p2p_approve_order`, `p2p_submit`, `p2p_approve_bill`, `p2p_reject_bill` |
| Schema version | `RuntimeCache::SCHEMA_VERSION = '10.16.0'` |
| Doc | `docs/ai/36-P2P-THREE-WAY-MATCHING-1016.md` |
| Handoff | `docs/ai/37-HANDOFF-v10.16.0-P2P.md` |

---

## D. Explicit non-claims

- No `PRODUCTION_VERIFIED` capability in this matrix.
- No Moadian / payroll / VAT compliance complete.
- No cPanel deploy / production migration executed from system-2 for v10.16.
- PHP CLI absent on system-2; PHP contract success must come from GitHub Actions, not local PASS.
