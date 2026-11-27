# Universal ERP Agent — Phase 1 Real Audit Matrix

**Authority:** T0  
**Base SHA:** `171d4b17c376529ac4c779b257e5c1ca90444643`  
**Branch:** `feature/universal-erp-agent`  
**Rule:** Menu/table ≠ complete cycle. Cell is complete only with Domain+DB+Permission+UI+test evidence, else explicit BLOCKED/PARTIAL.

Statuses: `IMPLEMENTED_AND_TESTED` | `IMPLEMENTED_NOT_TESTED` | `PARTIAL` | `NOT_IMPLEMENTED` | `BLOCKED`

---

## A) Vertical business domains

| Domain | DomainSvc / tables | Perm | UI | AI Tools | Approval | Tests | Status | Honest gap |
|---|---|---|---|---|---|---|---|---|
| Accounting / GL | AccountingRepository + vouchers/articles | accounting.* | yes | trial_balance, party_ledger, create_voucher_draft | proposal | commercial + domain gates | **PARTIAL** | Dedicated tools OK; not via generic CRUD. Period-close / full fiscal close incomplete. |
| Sales / O2C | OrderToCashDomain, SalesDomain, acc_sales_* | sales/accounting | yes | search/detail/fulfillment + confirm/reserve/deliver/invoice proposals | proposal + maker/checker | PHP E2E DATABASE_TESTED (sandbox) | **IMPLEMENTED_AND_TESTED** (sandbox) | Not PRODUCTION_VERIFIED / STAGING_VERIFIED. |
| Purchase / P2P | Purchase/3WM domains, acc_purchase_* | purchase.* | yes | search/pipeline + draft invoice; enterprise offer→PO draft bridge | proposal | DATABASE_TESTED sandbox | **IMPLEMENTED_AND_TESTED** (sandbox) | Cheque/Sayad excluded; GRNI not claimed. |
| Treasury | Settlement/treasury integrity services | treasury/cash | yes | search_cash, check_analytics, create_check | proposal | DATABASE_TESTED 10.20.x | **PARTIAL** | Bank reconciliation **NOT_IMPLEMENTED**. |
| Inventory | InventoryDomain + movements | inventory.* | yes | inventory_position, replenishment, receipt proposal | proposal | cycle4 + RC1 WMS paths | **PARTIAL** | Enterprise WMS metadata ≠ stock mutation. |
| Manufacturing | ProductionDomain + MF tools | production.view | cockpit | bom/order/readiness **read** | n/a writes | MF-1 E2E + UNVALUED contract | **PARTIAL** | Costing/WIP/FG posting **GL_BLOCKED / UNVALUED**. |
| Trade | TradeDomain cases/shipments/costs | trade.* | cockpit | search/snapshot/landed/risk + proposals | proposal | TR-1 DATABASE_TESTED sandbox | **IMPLEMENTED_AND_TESTED** (sandbox) | Full customs/VAT filing incomplete. |
| CRM | CRM lite tables + tools | crm.* | yes | 360/pipeline/followup + activity/opportunity proposals | proposal | contract + prior gates | **PARTIAL** | Not full CRM suite. |
| HR (ops records) | ErpSuiteDomain employees/leave/attendance + enterprise people | hr.* / enterprise.people.* | yes | universal catalog/search/read/report/navigate + propose | leave review maker/checker | universal AI MySQL E2E PASS (leave create/review); hire-to-pay absent | **PARTIAL** | Payroll statutory still **BLOCKED**; ops records AI-tested. |
| Payroll | — | — | — | — | — | — | **BLOCKED** | No Iranian payroll/tax/insurance engine. Do not invent. Owner/legal model required. |
| Projects | acc_projects canonical + tasks/timesheets | projects.* | yes | universal tools + dedicated project refs | n/a archive blocked for projects | suite CRUD prior; AI static | **PARTIAL** | Archive via AI forbidden (financial refs). |
| Assets | erp_fixed_assets metadata | assets.* | yes | universal | proposal | static AI | **PARTIAL** | No depreciation/GL posting claimed. |
| Service | tickets + enterprise service_plus | service.* | yes | universal | proposal | static AI | **PARTIAL** | No parts consumption / billing auto. |
| POS | registers/carts/cart_lines | pos.* | yes | universal | proposal | static AI | **PARTIAL** | Explicitly **no financial/stock posting** from carts. |
| Marketing | enterprise_growth campaigns/events/surveys | enterprise.growth.* | yes | universal | proposal/transition | static AI | **PARTIAL** | No SMS/email gateway. |
| Documents | enterprise_knowledge documents metadata | enterprise.knowledge.* | yes | universal | proposal | static AI | **PARTIAL / BLOCKED for files** | Metadata only; binary storage/OCR/e-sign **BLOCKED** until secure storage model. |
| Workflow | existing leave/expense approval + enterprise transitions | *.approve | yes | review/transition via propose_erp_operation | maker/checker | suite/enterprise domain rules | **PARTIAL** | Not a general BPM engine for all finance docs. |
| Reporting | allowlisted DSL tools + sampled erp_entity_report | ai.use + module.view | sidecar | document_analytics, periodic_business_report, erp_entity_report | n/a | static + prior analytics | **PARTIAL** | No LLM SQL; sampled ops report ≠ ledger truth. |

---

## B) Universal AI vertical slice (this patch)

| Capability | Surface | Status | Evidence |
|---|---|---|---|
| catalog | `erp_entity_catalog` → AiUniversalOperations | **IMPLEMENTED_AND_TESTED** (disposable MySQL) | `test_universal_ai_ops_php_e2e` + RBAC-filtered ops |
| search | `erp_entity_search` (suite allowlisted columns / enterprise list / core registry) | **IMPLEMENTED_AND_TESTED** | same |
| read | `erp_entity_read` + ambiguity ask | **IMPLEMENTED_AND_TESTED** | same |
| report | `erp_entity_report` sampled allowlisted fields | **IMPLEMENTED_AND_TESTED** | same |
| navigate | `erp_navigation_resolve` deep link `page+entity+edit` | **IMPLEMENTED_AND_TESTED** (URL asserted; browser **NOT_RUN**) | same |
| history | `erp_entity_history` → EnterpriseDomain::history | **IMPLEMENTED_AND_TESTED** | enterprise stale/history scenario |
| create/update + approve/reject + idempotency | `propose_erp_operation` | **IMPLEMENTED_AND_TESTED** | propose_create_approve / reject_proposal |
| review maker/checker | Suite leave review + `ErpSuiteDomain::review` | **IMPLEMENTED_AND_TESTED** | maker_checker_review |
| stale snapshot/revision | suite hash + enterprise revision | **IMPLEMENTED_AND_TESTED** | stale_* scenarios |
| cross-company / module-disabled | company scope + ModuleRegistry | **IMPLEMENTED_AND_TESTED** | cross_company / module_disabled |
| Core financial writes via generic tool | — | **BLOCKED** (by design) | dedicated proposals only |
| MCP | facade | **DISABLED_BY_DEFAULT** | Wave3 contract |
| Browser RTL proposal cards | Wave4 UX | **NOT_RUN** | no local staging browser for this path |
| PRODUCTION_VERIFIED | — | **NOT claimed** | staging gate open |

### Suite entities exposed (typed)

`employees`, `leave`, `attendance`, `projects`, `tasks`, `timesheets`, `registers`, `carts`, `cart_lines`, `tickets`, `assets`, `expenses`

### Enterprise entities exposed (typed catalog)

39 record kinds across commerce / warehouse / factory / people / service / growth / knowledge / governance (see `EnterpriseCatalog`). Approving a record ≠ GL/stock/payment/payroll.

---

## C) Explicit BLOCKED / owner decisions

1. **Payroll / Hire-to-Pay statutory** — BLOCKED until owner/legal model.  
2. **MF costing → WIP/FG GL** — remains UNVALUED / GL_BLOCKED.  
3. **Bank reconciliation** — NOT_IMPLEMENTED.  
4. **Physical document binary storage / OCR / crypto e-sign** — BLOCKED.  
5. **Generic CRUD mutating GL/stock/cash** — permanently forbidden.  
6. **Production / main-domain cPanel deploy** — NOT_AUTHORIZED from this campaign.  
7. **Live Gemini/Grok enablement** — credentials from env only; smoke may return `BLOCKED_MISSING_CREDENTIAL`; prod default OFF.

---

## D) Exit rule

No matrix cell may be labeled complete without Domain + DB + Permission + UI + test evidence. This foundation patch deliberately stops at **static contract + architecture reuse** for Suite/Enterprise universal ops, while reusing already-tested finance/trade/inventory dedicated tools. Remaining DB/browser/provider gates keep the campaign **NOT PRODUCTION_VERIFIED**.
