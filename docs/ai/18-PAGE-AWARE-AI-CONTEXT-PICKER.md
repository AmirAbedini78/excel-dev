# ERPSMART v10.5 — Page-aware AI / Context Picker

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`

## Contract

Page-aware AI does not trust browser labels or business facts. The browser sends a typed reference only:

```text
{ type: party, id: 3, source_page: crm }
```

The Control Plane resolves it again before queuing the Job:

```text
UI typed ref
→ workspace/company/module/RBAC validation
→ canonical DB entity resolution
→ ai_jobs.context_json.page_context
→ Worker
→ fresh Tool read / guarded Proposal
```

The context is an entity pointer, not a factual snapshot. Current balances, Sales totals, Pipeline values and other business truth are always read again from server Tools.

## r1 scope

First vertical slice: CRM Customer 360.

Supported page context:
- source page: `crm`
- entity type: `party`
- canonical source: `acc_parties`
- active customer types: `customer` / `both`

Supported deterministic uses:
- `crm_customer_360`
- `create_crm_activity` Proposal
- `create_crm_opportunity` Proposal

The user may therefore write:
- `وضعیت 360 این مشتری را بده.`
- `برای این مشتری یک پیگیری با موضوع «...» آماده کن.`
- `برای این مشتری فرصت فروش «...» با مبلغ ... آماده کن.`

No customer name/code is required after attaching the context.

## Safety

- Browser-provided labels are not accepted as truth.
- Workspace + company + active customer ownership are checked server-side.
- `crm.view` and enabled CRM module are required.
- Tool Gateway/domain validation re-checks the referenced `party_id`.
- Explicit customer text that conflicts with the attached page context fails closed.
- CRM mutations remain Proposal → Human Approval → server execution.
- No schema migration is required.

## Live gate

1. Open `CUS-003 کارخانه بهین بسته‌بندی` in Customer 360.
2. Click `از AI درباره این مشتری بپرس`.
3. Verify the AI page shows the canonical entity chip and locks the company context.
4. Prompt: `وضعیت 360 این مشتری را بده.` Expected: deterministic Customer 360, no `search_parties`, Tool `crm_customer_360`, same live facts.
5. Prompt: `برای این مشتری یک پیگیری با موضوع «تست Context Picker» برای 1405/06/16 آماده کن`. Expected: medium-risk Proposal targeting `party_id=3`; do not approve; reject after inspection.
6. Prompt: `برای این مشتری فرصت فروش «تست Context Opportunity» با مبلغ 100000000 ریال و احتمال 40% آماده کن`. Expected: medium-risk Proposal targeting `party_id=3`; do not approve; reject after inspection.
7. With the CUS-003 chip still attached, ask for `نمای 360 مشتری «فروشگاه پارس الکترونیک» را بده.` Expected: fail closed because explicit customer and page context differ; no Customer 360 Tool execution for the mismatched customer.

After r1 live validation, reuse the same server Context Kernel for Sales Document, Trade Case, Item/Inventory and Warehouse refs.
