# T6 Role / Action Matrix (RC1)

**Source of truth in code:** `app/Core/UiRoleActionMatrix.php`  
**Rule:** UI visibility is advisory. `Tenant::can` + domain `requirePermission` remain authoritative. Unauthorized POST must still 403 server-side.

| Role | Key actions | Required permission | Workflow | Mutates |
|------|-------------|---------------------|----------|---------|
| Buyer | Create/approve PO, P2P submit, trading cockpit view | `accounting.purchase.manage` / `procurement.view` | TR-1 | purchase_doc / p2p_bill / none |
| Seller | Confirm sales order (linked, not TR-1 cockpit writer) | `accounting.sales.manage` | TR-2 | sales_doc |
| Warehouse operator | GR post; MF issue/FG after T2 API | `inventory.manage` | TR-1 / MF-1 | stock |
| Production planner | BOM/plan drafts; MF cockpit view | `production.manage` / `production.view` | MF-1 | bom / plan / none |
| QC operator | QC evidence (blocked until T1) | `production.manage` | MF-1 | qc |
| Accountant | AP validate / post | `accounting.purchase.manage` / `accounting.vouchers.manage` | TR-1 | ap_workflow / gl |
| Treasurer | AP payment draft / allocate | `accounting.treasury.manage` | TR-1 | ap_payment / ap_allocation |
| Manager | AI proposal approve; maker/checker | `ai.actions.approve` / purchase manage | SYS-1 / TR-1 | via domain executor |

## Cockpit POST surface
| Action | Allowed? | Gate |
|--------|----------|------|
| `ui_cockpit_select_company` | Yes (view) | `procurement.view` **or** `production.view` **or** `accounting.purchase.view` |
| `ui_cockpit_post_stock` / `ui_cockpit_post_gl` / `ui_cockpit_ai_direct_execute` | **No** | Hard 403 in `UiCockpitModule::handle` |
| Domain writers (`acc_*`, `inv_*`, `p2p_*`, `prod_*`, `ai_approve_proposal`) | Domain modules only | Existing Tenant + maker/checker |

## Fake-status prevention
- Cockpit never labels **PAID** from unallocated posted payments.
- Cockpit never claims **BANK_RECONCILED**.
- MF valuation default shown as **BLOCKED** (T5 policy).
