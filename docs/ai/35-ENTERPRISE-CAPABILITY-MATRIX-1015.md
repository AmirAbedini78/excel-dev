# ERPSMART 10.15 — Enterprise Capability Matrix and Operational Contract

**Source baseline:** GitHub `AmirAbedini78/excel-dev` main, `9b0b51b79a0f51cd534e139210eca29aeca5080c`.

**Scope:** 8 opt-in workspace modules, 39 typed operational record kinds and **41 additive tables** (`erp_ext_*` 39 + `erp_ext_events` + `erp_ext_links`). **Status: operational CRUD + controlled state transitions + scoped canonical references + one purchase draft bridge; not full parity with Odoo, Sepidar or Holoo.** This release does not change AI tools, worker or finance post routines. Do not represent a saved draft as an executed payment, stock move, payslip or invoice.

## References and competitive research (coverage, not copying)
- Odoo Apps, documentation and architecture: https://www.odoo.com/page/all-apps ; https://www.odoo.com/documentation/20.0/applications.html ; https://www.odoo.com/documentation/20.0/developer.html ; https://github.com/odoo/odoo
- سپیدار (Sepidar) Iranian accounting bundles: https://www.websepidar.com/Sepidar/ (finance, inventory, sales, payroll, asset, domestic legislation); separate localization acceptance is required.
- Business capabilities supplied by the owner in Persian, 19 Nov 2026, are the feature map. This version implements a bounded subset below with original ERPSMART UI and schema.

## Actual new menus and record categories

| Workspace / URL page | Additive record forms | Linked canonical master data | What currently works | What does NOT yet work |
|---|---|---|---|---|
| Sales and Trade / `enterprise_sales` | price_lists, price_rules, subscriptions, rentals, vendor_rfqs, vendor_offers | Parties, Items, RFQ | Create/edit/search, pricing data and subscription schedules, purchase offer -> real **draft** purchase order with source link | Automatic recurring billing, rental delivery/return, bank reconciliation |
| WMS / `enterprise_wms` | locations, lots, reorder_rules, cycle_counts, count_lines, transfers | Items, Warehouse, Lots | Shelf/bin, batch/expiry metadata, min-max rules, count records and transfer requests | No stock movement/quant mutation, no FEFO picking engine, barcode hardware, valuation |
| Manufacturing & Quality / `enterprise_factory` | work_centers, work_orders, quality_checks, maintenance, engineering_changes | Production plan, BOM, Item, Employee, Asset | Work-center setup, recording operations/inspections and maintenance requests | No actual finished goods posting, WIP, production costing, CAPA/QMS, OEE calculation |
| Human Resources / `enterprise_people` | positions, applicants, contracts, shifts, appraisals, leave_allocations | Employees | Hiring pipeline records, employment terms, scheduled shifts, review and allocation entries | No labor-law payroll calculation, insurance, tax reports, kiosk or biometrics |
| Field Service / `enterprise_service_plus` | repair_orders, field_visits, appointments, service_contracts | Ticket, Party, Employee | Work orders for repair and field work, appointments and SLA contract definitions | No inventory parts consumed, live scheduling/map dispatch, warranty/field billing |
| Marketing / `enterprise_growth` | campaigns, events, event_attendees, surveys, survey_responses | Parties and campaigns | Campaign data, attendance lists, survey form text and response tracking | No mailing gateways, SMS/WhatsApp sending, live event checkout, survey branching |
| Documents & Knowledge / `enterprise_knowledge` | documents, knowledge_articles, contract_register, signature_requests | Parties, Employee, contract register | Document metadata, authored knowledge, contracts and signature request registers | No actual uploaded binary, OCR, cryptographic e-signatures, full-text index |
| Administration / `enterprise_governance` | fleet, visitors, esg_metrics | Employee | Fleet records, visitor logs, ESG metric capture | No automatic carbon calc, fleet telematics or visitor access hardware |

Each group is a separate optional module, scoped to workspace and company. New permissions `<group>.view/manage/approve` under `enterprise.*` are granted to the **workspace owner** only during migration; other roles need explicit grants. The server enforces module flag, CSRF, scope, reference ownership, input validators and per-record revision checks. Merely hiding a button is never considered authorization. New records are never physically deleted: archival is controlled and blocked when active enterprise children depend on them. An append-only event entry is written transactionally for create/update/transition/archive.

### State machine
- `draft -> active | submitted | cancelled`; sensitive requests (RFQ, offer, transfer, stock count, manufacturing work, ECO, employment/commercial contract, repair, signature) cannot skip review to active
- `submitted -> approved | rejected` (distinct reviewer, dedicated approve permission)
- `approved -> active | closed`
- `active -> closed`
- `rejected -> draft | cancelled`
- `closed/cancelled` terminal; editing allowed only draft/active/rejected, archive only draft/rejected/closed/cancelled.

**Caution:** `approved` is an internal record review status, **not** a posted financial or inventory state. The workflow engine can link to six new entities as a source but does not automatically commit accounting or warehouse movement from case approval. Existing leave and expense workflow behavior is unchanged.

### Only transaction bridge in this release
When an approved supplier offer belongs to an approved RFQ and the current user has both commerce-management and purchase-management permission, `createPurchaseDraft` atomically creates a canonical `acc_purchase_docs` record (`purchase_order_goods`, `draft`, `not_sent`), one `acc_purchase_lines` record, and an idempotent source link in `erp_ext_links`. The source offer/RFQ stays unchanged, and retries return the same draft. No posting to `acc_vouchers`, `acc_stock_movements`, `acc_cash_transactions`, tax services, bank, production or payroll. The draft must still be reviewed in the actual accounting section and includes the verified RFQ destination warehouse; requires further accounting review before fulfillment.

## Detailed module parity gap against Odoo's application families

| Odoo family | Source system | 10.15 state | Main missing cycle |
|---|---|---|---|
| CRM / Sales / Point of Sale | existing ERP + new prices | Partial | Quote -> reservation -> invoice -> payment -> returns, POS register close and refunds |
| Subscription / Rental | New operational records | Partial | recurring scheduler, invoicing, fulfillment, refundable deposits |
| Accounting / Invoicing / Expenses / Sign | existing accounting + expense workflow + metadata | Partial | GL linkage, cash/bank reconciliation, maturity, deferrals, fiscal/FX closing, Iranian tax/Moadian, signature evidence |
| Inventory / Barcode / Purchase | existing purchase stock ledger + WMS metadata | Partial | verified stock quant, inbound/outbound routes, serial/lot allocation, FEFO/FIFO, stock count adjustment and multiwarehouse transfers |
| Manufacturing / Quality / Maintenance / PLM | existing BOM pilot + operations registry | Partial | real work-center capacity, work order execution, QMS release, costs and accounting posting |
| Employees / Recruitment / Payroll / Time Off | HR core and new recruitment/contract data | Partial | payroll rules by Iranian effective date, insurance/tax, accrual, staffing integration |
| Project / Timesheet / Field Service / Planning / Helpdesk | existing tasks/timesheets + dispatch records | Partial | schedule optimizer, customer signatures, field parts and service profitability |
| Marketing / Email / SMS / Surveys / Events | new campaign/attendee records | Partial | consent, audience engine, actual sending, A/B, attribution, online forms |
| Website / eCommerce / Blog / Forum / eLearning / Live Chat | existing main ERP web UI only | **Not implemented** | external/public website, checkout, learning, forum, live chat |
| Discuss / Knowledge / Documents / Studio / VoIP / IoT | metadata and knowledge records | Partial | secure file storage and versioning, true e-sign, collaboration hub, dynamic field & view designers, device gateways |
| AI Agents / OCR / Automations / MCP | existing read-oriented AI worker | Partial | typed write tools with human approval and domain validation, OCR workflows, MCP parity |
| Data Cleaning / Dashboards / BI | existing analytical features | Partial | broad reporting cube, reconciled KPI definitions and supervised deduplication |
| Localization and security | workspace/company auth existing | Partial | audited object-level policy for all new operations, IR financial/legal compliance, restore rehearsals |

**No claim of full feature parity** or readiness for legal payroll, real-time inventory or monetary accounting without end-to-end DB acceptance. For actual *Order-to-Cash*, *Procure-to-Pay*, *Hire-to-Pay*, *Plan-to-Produce*, *Return-to-Repair*, extra transactional posting services and integration tests are necessary.

## Test protocol before live deployment
1. Git baseline exact: `9b0b51b79a0f51cd534e139210eca29aeca5080c`; vendor `.env`, private config and runtime `storage` unchanged.
2. Install source patch in Windows worktree via guarded installer. Run Python release gate and PHP contract test in CI.
3. On a **separate staging workspace and DB**, check migration: 41 new tables, existing old schema intact; verify `erp_ext_events` append-only.
4. Create an item/party in canonical system and reference it from a new record; foreign-company ID must fail.
5. Create a vendor RFQ, offer and approvals with **different actors**. Create purchase order draft; retry must return same `doc_id`; no stock or voucher change.
6. Validate unauthorized write/approve attempts, forged company/workspace, CSRF, stale `revision`, closed-edit, duplicated scoped code, HTML injection text and invalid dates.
7. Confirm `storage`, backups, all other ERP modules unaffected. Database migration is not rolled back by restoring files.
8. Do not enable `enterprise_*` on live until acceptance. Provision permissions explicitly by roles.

## Release/rollback
- Bounded file installer does not touch DB, Docker, deploy, Git push, secrets. It backs up only target code files outside repository.
- Schema migration is additive on first request **after** source deployment and is not reversed by reverting code. Take a tested MySQL backup before exposing new code. There is no automatic production rollback for tables.
- Under `.cpanel.yml` existing `scripts` and `tests` are not deployed to document root, by design.
