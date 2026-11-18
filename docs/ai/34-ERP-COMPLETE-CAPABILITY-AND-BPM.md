# ERPSMART 10.14.0 — Business Process Studio + Full ERP Target Architecture

**Status:** engineering delivery, additive/opt-in workflow pilot. **Baseline:** exactly GitHub `main` Merge Commit `ecb7d98db03c4e360086658f1cc74e6736dc5ae2` (v10.13.0). **This is NOT an Odoo/Sepidar/Holoo-complete ERP.** The financial/posting capabilities still need integrated MySQL acceptance, regulations and business-domain test cases. No autonomous Agent changes in this release.

## Provenance / source comparison

Primary public reference pages, reviewed 2026-11-18:
- Sepidar official: https://www.websepidar.com/Sepidar/ — accounting, cash/treasury, warehouse, customers/sales, fixed assets, payroll, attendance, manufacturing, distribution, foreign orders, taxpayer communication, 5 business packages.
- Dasht (Sepidar's retail offering): https://www.websepidar.com/Dasht/index.html — POS/cart/invoice/settlement, receipt, barcode, discounts, reconciliation and retail reporting; business patterns not proprietary UI.
- Odoo 19 official app taxonomy: https://www.odoo.com/documentation/19.0/applications.html — finance, sales, supply chain, HR, services, website, marketing, productivity, automation/Studio, IoT, BI.
- Odoo supply chain: https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp.html — manufacturing orders, BOM, MPS/MRP, lot/serial, stock issue/receipt, costing, QA, purchase, warehouse.
- Odoo accounting: https://www.odoo.com/documentation/19.0/applications/finance/accounting.html — accounts, reconciliation, tax, inventory valuation, reports and localized rules.
- ERPNext: https://docs.frappe.io/erpnext — projects, accounting, supply chain and manufacturing workflows.
- Rahkaran reference: https://rahkarsepanta.com/ — enterprise finance, manufacturing, distribution, HR, supply chain.
- Holoo/Torfehnegar public channel https://t.me/s/Holoo_Software — job-specific bundles, sales, warehouse, service/guarantee, cheque and operational documents. This is **not** full internal documentation, and all non-public menus must be treated as unverified.

We compare user-visible capabilities and business process patterns only. No proprietary vendor code, branding or copied UI designs.

## Current delivery: functional, company-scoped approval engine

### Workflow Studio page `index.php?page=workflows`

- **Definitions**: unique code, name, classification, description, `draft → active → retired`; stages freeze after activation. A single active definition may have 1–12 ordered stages.
- **Stage**: title, explicit user assignee or qualified approver group, `due_hours` 1..720, mandatory note. Active Workspace membership is checked before setting a named approver.
- **12 opt-in process templates**: purchase approval, sales exception/discount, stock count difference, manufacturing release, payment request, expense claim, leave, project scope change, trade release, service escalation, asset disposal, CRM contract exception.
- **Cases**: explicit creation linked to a real company-scoped reference; `open/approved/rejected/cancelled`, step, due date, revision, original initiator. List filters and a live priority/overdue queue.
- **Actions**: transaction + MySQL `SELECT FOR UPDATE` + optimistic revision + unique event idempotency. Reviewer must be authorized, and not the initiator. Rejections require reasons; partial stage approval advances one stage. No direct SQL from browser.
- **Event history**: actor, timestamp, prior/new states, stage and note; immutable events (no update/delete UI). Only source-based tenant-scoped reference types are permitted.
- **Cross-module bridges**: HR leave and employee expense dedicated templates update ONLY original `pending` request's `approved/rejected` status after the process rejects or completes, with `hr.approve` / `expenses.approve`, in the same transaction. They do not transfer money, pay salary, issue stock or create financial postings. Other workflow cases **reference** the canonical document but do NOT transition its accounting status.
- **Navigation**: launch workflow from HR leave, project, task, expense, ticket and fixed-asset list. Use separate workspaces/roles for segregation-of-duties acceptance.

### Additive schema (4 tables)

- `erp_workflow_definitions`: `workspace_id`, `company_id`, `code`, `category`, `template_key`, `effect_type`, `status`.
- `erp_workflow_steps`: numbered definition stages, allowed current assignee, deadline hours, note requirement.
- `erp_workflow_cases`: linked canonical entity, amount for reference only, state and revision, unique case code, due/closed timestamp.
- `erp_workflow_events`: append-only case history and unique request-hash for idempotent retries.

No new financial ledger. No replicated customers, warehouses, projects or items. The existing company/user/role schema is authoritative.

## Business-specific menu and table coverage roadmap

**Legend:** `Existing` = code present before 10.14 and may be pilot-limited. `New` = implemented in 10.14. `Planned` = engineering backlog only, not on the live menu.

| Domain | Target professional menus / tabs | Sources of truth | Status / missing transaction cycle |
|---|---|---|---|
| General ledger | chart of accounts, journal, cost centres, analytic dimensions, fiscal periods, multi-currency, trial balance, balance sheet, P&L, year-close, audit trail | existing `acc_*` | Existing pilot; fiscal close/FX/complete consolidation unverified |
| Treasury | accounts, cashboxes, receipts, payments, bank transfers, cheques, deposited/presented/cashed/bounced/endorsed, bank reconciliation, liquidity forecast | existing accounting | Existing partial; bank feed/matching/posting gaps |
| Tax | VAT, withholding, sales tax, Taxpayer e-invoice staging, submission/retry/response, fiscal documents, legal reports | existing accounting | Existing partial; legal certification/integration not claimed |
| Sales | quotations, approvals, sales orders, reservations, delivery, invoices, returns, AR collection, commissions, margin, subscriptions | `acc_sales_docs`, lines, stock | Existing pilot, transaction completion incomplete |
| CRM | account 360, leads, opportunities, pipeline, contact history, activity, quote links, customer credit, segmentation, support | `acc_parties`, CRM tables | Existing pilot; approvals via New Workflow |
| Retail POS | terminals, sessions/shift, barcode, carts, hold/suspend, invoices, split payment, discounts, refunds, cash-up, printer/tax | `erp_pos_*` + `acc_sales_docs` | Existing draft only; settlement/stock/tax gaps |
| Procurement | purchase requests, RFQ, quotes, 3-way-match, PO, arrival, GRN, supplier bills, returns, AP due/aging | `acc_purchase_docs`, stock ledger | Existing pilot; New approval cases, no automatic PO release |
| Warehouse | products/UoM, categories, multi-site/bin, warehouse transfers, stock take, lot/serial, expiry, reservation, picking, reorder, stock valuation | `acc_items`, `acc_warehouses`, stock ledger | Existing partial; full bin/serial/valuation gaps |
| Manufacturing | BOM versions, routing, centers, capacity, MPS/MRP, WO, issue, WIP, scrap, QC, finished receipt, costing/variance, batch trace | `erp_production_*` | Existing **draft**; New release-approval process, no production posting |
| Trade/logistics | vendors, FX, foreign orders, LC/TT, shipments, customs, ETA, landed cost, transport contracts, demurrage | existing trade tables | Existing pilot; New review process, no automatic clearance |
| HR | employees, org chart, positions, attendance, leave, recruitment, contract, reviews, benefits, training, payroll, insurance/tax | `erp_employees`, leave, attendance | Existing employee/leave; New workflow integration; full payroll Planned |
| Project delivery | WBS, task, time, resources, milestone, budgets, claims, profitability, earned value, approvals, expenses | `acc_projects`, `erp_project_tasks`, timesheets | Existing partial; New change approval; real project costing Planned |
| Field service | warranty, equipment, SLA, tickets, dispatch, repair, parts, service contract, customer portal | `erp_service_tickets`, parties | Existing tickets; New escalations; warranty/accounting Planned |
| Fixed assets | asset master, tags, custody, capitalization, depreciation, impairment, transfers, revaluation, disposal | `erp_fixed_assets`, finance | Existing registry only; New disposal review; depreciation/GL Planned |
| Marketing | campaigns, lead attribution, email/SMS, segmentation, consent, automation, website/ecommerce | CRM | Planned integrated workflows |
| Contracts | templates, amendment, approval, e-sign, expiry, billing milestones, purchase/sales master agreements | workflow cases + files | New approval foundation; lifecycle/counterparty contract table Planned |
| Documents | file versioning, classified attachments, access, OCR, signing, records retention | existing library | Existing basic files, regulated DMS Planned |
| BI/management | operating metrics, budgets, profitability, cash forecasting, drill-down, consolidated reports | existing `acc_*`, operational tables | Existing dashboards; cross-ledger closing validation Planned |
| Platform/security | workspaces, company scopes, roles, permissions, workflow policy, audit, backups, retention, queues | Tenant + ModuleRegistry + Workflow | Existing tenant access + New workflow; load/security review Pending |
| AI & automation | read tools, typed proposals, approval, execute/retry, audit, explainability, workflow-aware skill planning | PHP registry + Python worker | Existing read/copilot & limited proposals; NOT extended in this patch |

## Integrity and release acceptance

- **Modules:** Workflow Studio disabled by default, activated by Platform Admin per Workspace. Grant `workflow.view`, `workflow.manage`, `workflow.approve` to selected roles; owner role gets them on initial schema migration. For expense/leave propagation approver also needs the matching business approval permission.
- **Separation of duties:** a case starter cannot approve/reject their own request even as Platform Admin. Two different users are required to test. Named assignees must be active members, and have the workflow approve permission.
- **Data:** Keep records in distinct DEMO Workspace and company, no fake external bank integration or automated stock/accounting posting. Use `WorkflowDomain::validateSubject` to check cross-company object IDs and separate business read permissions.
- **Migration:** first web request to version 10.14.0 creates 4 new tables. This is additive. Back up MySQL *before* deployment; source rollback does NOT roll back schema or business records. Test on staging first.
- **Tests:** PHP pure behavior tests and source/contract suite run through `scripts/release_gate.py --require-php --require-node`; database concurrent approval and cross-tenant isolation require separately provisioned MySQL test fixtures.
- **Credentials:** no API secrets or business data added to repo. Existing Gemini/Grok Worker is not modified or rebuilt by this patch.
- **No client auto-approval:** script follows explicit user actions; an approval case does not implicitly allow AI to write to ERP.

## Critical phase plan (not implemented yet)

1. Financial close and statutory ledger: period state, fiscal localization, balanced document posting with idempotent reversals, bank-check lifecycle, reconciliation, tax integration.
2. Inventory and procurement: document-level 3-way match, warehouse reservation/receipt and stock valuation with serial/lot and audit.
3. Manufacturing: BOM release→work order→issue→yield/QC→receipt→WIP costing→GL, with transactional cross-document consistency and reversal.
4. Payroll and HR: employee contracts and statutory parameters, attendance close, payroll run, payslip, insurance/tax, bank file, payroll GL posting.
5. Retail and trade: POS shift→collection→sale→delivery→returns, tax and payment settlement; trade/FX and logistics adjustments.
6. Connected workflow executor: typed, versioned, permit-checked transition hooks for these exact domain operations; dry-run, signed approval, atomically committed postings, compensation/reversal.
7. Full UI and locale: dense screen specifications with Persian/Jalali input, validations, search/filter, pagination, exporting, mobile and multi-tenant benchmarks.

Until these are implemented, any claim of **full Odoo/Sepidar feature parity** is inaccurate.
