# ERPSMART 10.13 — ERP Operations Suite MVP

**Baseline:** exact merged `main` commit `9dccfad723a701aa3731b9082634ce514b4565b5` (PR #1, v10.12.0).  **Delivery type:** additive, opt-in extension. **Status:** offline tests passed; production MySQL and workflow acceptance pending.

## Product truth and provenance

ERPSMART is an ERP+Business Copilot platform for Iranian business and manufacturing companies. The authoritative accounting, customer, item, sales, warehouse and project data are the existing `acc_*` modules. This delivery does **not** claim feature equivalence, interoperability or legal certification with Sepidar, Hamkaran/Rahkaran, Holoo, Odoo or ERPNext. The comparator documentation is used only for architecture and user-workflow benchmarking; no third-party proprietary source code, trademark presentation or UI design was copied.

| Source | Observed design lesson | Result in this patch |
|---|---|---|
| Sepidar, official https://www.websepidar.com/Sepidar/ | Iranian accounting, sales, warehouse, payroll, assets, manufacturing are linked business packages | Keep account/item/party identifiers and financial truth canonical |
| Dasht, https://www.websepidar.com/Dasht/ | Retail POS needs fast sale, stock and reporting | Implement POS basket with guarded conversion to canonical **draft** preinvoice; payment/stock later |
| Rahkaran/System Group partner overview https://rahkarsepanta.com/ | Finance, HR, manufacturing and supply-chain must share business state | Tenant/company scope, module flags, cost/project references |
| Holoo / retail-market patterns | Low-friction cashier and SMB workflows | Compact RTL forms, no technical IDs required except optional links |
| ERPNext https://docs.frappe.io/erpnext/project and https://docs.frappe.io/erpnext/timesheets | Project task → employee timesheet → costs requires one project master | Reuse `acc_projects`, add scoped tasks and hours |
| Frappe HR https://docs.frappe.io/hr/payroll-entry | Payroll processing requires attendance, salary structure, approvals and posting | Attendance/leave groundwork only; **no payroll posting** |
| Odoo 19 https://www.odoo.com/documentation/19.0/applications/sales/point_of_sale.html and https://www.odoo.com/documentation/19.0/applications/finance/accounting.html | POS receipts, invoice, tax, stock and GL are distinct state changes | Draft conversion is atomic; final sale, tax transmission and payments are explicitly out of scope |

The literature review is selective and grounded in the cited documents; it is not an exhaustive study of every Iranian ERP or repository.

## What is really implemented

**New opt-in pages:** `?page=operations`, `hr`, `projects`, `pos`, `service`, `assets`, `expenses`.

**New tables:** `erp_employees`, `erp_leave_requests`, `erp_attendance`, `erp_project_tasks`, `erp_timesheets`, `erp_pos_registers`, `erp_pos_carts`, `erp_pos_cart_lines`, `erp_service_tickets`, `erp_fixed_assets`, `erp_expense_claims`. All store `workspace_id`, `company_id`, `is_archived`, actor and timestamps; relevant business references are validated in server-side domain methods. `acc_projects` remains the sole projects master. 12 editable record types, 11 additive tables.

**Functional UX:** create and edit records, scoped reads, soft archive where safe, per-module `view/manage`, independent approval permission for leave and expense requests, CSRF protection and audit events. Active-company selection uses the existing `AccountingRepository::companyId`.  Approval does not trigger salary, fund transfer or GL entries.

**POS integration:** cashier can make a register, basket, items and prices (only from existing items), then explicitly create an `acc_sales_docs`/`acc_sales_lines` **preinvoice** in status `draft`. Operation is transactional, idempotent at cart state level (`SELECT FOR UPDATE`, `converted`, `linked_sales_doc_id`) and requires both `pos.manage` and `accounting.sales.manage`. No posted sale, reservation, receipt, stock movement, cash journal or tax submission. Conversion must be accepted in staging with real MySQL before production activation.

**Dashboard:** company-level factual counts of active employees, overdue tasks, open tickets, pending leave and expense claims, in-use assets and pending POS baskets, filtered by module/role visibility. The new counts are *not* claims of overall financial health or accounting reconciliation.

**Data:** opt-in CLI seed `scripts/seed_erp_suite_demo.php` adds synthetic but plausible employee, project, task, time, attendance, leave, expense, asset, ticket and optional POS draft fixtures in a pre-existing active company named `DEMO - ...` or `دمو - ...`. It uses a transaction and sentinel guard. It does NOT create a new live company, credentials, GL entries, payroll, stock movements or payments.

## Architecture and boundaries

- PHP 8.1+ on cPanel is required; no Node, React or Python added to web runtime.
- `ErpSuiteDomain` is the only handler of new SQL; schema and editable fields are an **allowlist**, never supplied as SQL identifiers from browser input.
- Workspace/Company ownership validated for all foreign keys. No free-form SQL tools, Agent write tool or auto-approval added here.
- `Tenant::requirePermission` enforces view/manage/approve; UI controls are **not** permission enforcement.
- Unsafe deletion of financial history is forbidden; records are archived. The canonical `acc_projects` row cannot be archived from this UI to protect accounting references.
- Existing `AiToolRegistry`, Worker, Grok/Gemini configurations and tool permission design are unchanged. Business Copilot still cannot automatically create these records through chat without a separate, approved Agent tool contract. A prompt alone does not grant write access.
- Cache/schema gate bumped to `10.13.0`. On first request of a configured web instance it may create 11 tables and seed permissions; **DB backup first.** DB schema is not undone by source rollback.
- Each module defaults **disabled**. Platform admin activates by Workspace in the Module Center; a company selector is not a module-level security boundary.

## Not yet implemented / explicitly NOT claimed

- statutory compliant payroll, employee insurance, tax withholding and bank export;
- retail shift closure, cash collection, payment gateways, inventory reservations and posted POS final sale;
- job costing, revenue recognition, GL posting and live project profitability;
- statutory depreciation, fixed-asset disposal postings and full multi-book asset accounting;
- SLA escalations, customer portal and warranty contract accounting;
- production shop-floor work orders, lot/serial traceability, MRP and full product costing;
- intercompany consolidation, cashflow forecasting certification, tax e-invoice legal compliance;
- human-level conversational, multi-step autonomous AI with write access to these modules;
- large-scale performance validation, multi-tenant load testing or independent security certification.

These gaps are not hidden with a generic "ERP complete" claim. The target is an honest feature-by-feature business MVP demonstration.

## Deployment acceptance gate

1. Ensure Git `main` is the exact baseline above; apply protected ZIP. Check source SHA-256, `git diff --check`, Release Gate, branch/PR review.
2. Take a **verified MySQL backup** before deploying changed app/bootstrap. PHP 8.1+ required. Check safe storage/permissions and that only needed `app`, `assets`, `index.php` files deploy; the script must NOT be exposed via web.
3. In a separate DEMO Workspace, activate modules from Module Center. HR & projects independently, POS depends on finance+inventory, service on CRM, expenses on HR+finance, assets on finance.
4. Create 2 employees, 1 canonical `acc_projects` project, 1 task, and an attendance and timesheet entry. Update and archive a disposable record. Verify other companies cannot read/update it; roles lacking `manage` cannot write.
5. Submit leave/expense requests; verify only approval-role user can approve. Repeated approval must refuse. Verify no money or GL changes.
6. Create POS draft/cart with active customer, warehouse, item; convert to canonical preinvoice **once**; verify its `draft` state, totals and lines; double-click/retry must not create another invoice; no inventory/GL changes.
7. Open executive dashboard; check counts match the underlying DEMO entities. Smoke-test all pre-existing accounting/inventory/trade/CRM/production/AI pages.
8. Test demo seeder first without `--apply`; only use `--apply` with isolated DEMO company, CLI access and DB backup; never run on live customer companies.
9. Failure: stop enabling modules, diagnose under staging; revert source using package rollback; DB restore must be separately planned if needed.

## Next deliverables before calling ERP market-complete

1. Accounting and purchase/sales lifecycle audit with formal posting reversals, inventory valuation, taxpayer integration and chart of accounts.
2. Production release, material reservation, WIP, costing and stock postings with role-specific approvals.
3. HR salary structures, timesheet-linked payroll, insurance/tax rules, settlement and statutory period controls.
4. POS cash sessions, card/QR settlement, refunds, receipt printing and stock linkage, with fiscal reconciliation.
5. Project resource allocation and profitability, expense reimbursement and customer-service SLA.
6. Management reports with drill-down, financial consolidation, audit trail, company-level benchmarking and verified demo data.
7. After business workflows pass E2E, expose typed read+proposal+approve Agent tools using existing Business Copilot authorization boundaries.
