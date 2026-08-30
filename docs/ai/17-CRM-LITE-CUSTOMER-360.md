# ERPSMART v10.4 — CRM-lite / Customer 360

Status: `LIVE E2E CLOSED`

Canonical identity is `acc_parties`. No `crm_customers` table is created.

Additive CRM process tables:
- `crm_party_contacts`
- `crm_opportunities`
- `crm_activities`

Customer 360 reads live Finance/Sales/Fulfillment facts rather than copying them:
- approved/final party balance
- Sales document count/amount
- outstanding undelivered Sales quantity
- contacts
- open/weighted Pipeline
- activities and next follow-up

AI reads:
- `crm_customer_360`
- `crm_pipeline_summary`
- `crm_followup_queue`

AI writes:
- `create_crm_activity`
- `create_crm_opportunity`

All AI writes remain Proposal → Human Approval → server execution.

Legacy `phonebook_entries` remains Workbench data and is not reused as CRM identity because it is keyed to `client_company_id`, not `acc_parties`.

Live gate:
1. CRM page opens and shows existing parties. — PASS
2. Named-customer Customer 360 read. — PASS, Job #88
3. One manual contact. — PASS, `مخاطب آزمایشی CRM` / `مسئول خرید`
4. CRM follow-up Proposal → approval → queue verification. — PASS, Job #89 / Proposal #14 / Job #90
5. Opportunity Proposal → approval → Pipeline verification. — PASS, Job #91 / Proposal #15 / `OPP-20260831-011700-86AC` / Job #92
6. Re-read Customer 360 and verify Finance/Sales facts unchanged. — PASS, Job #93

Closeout evidence:
- Company context/UI hotfix: commit `71c303ce9e292da53114507bc47127019e54a878`.
- GitHub Commercial MVP Gate Run #19: `success`.
- Customer under test: `CUS-003 کارخانه بهین بسته‌بندی`.
- Finance/Sales truth before and after CRM writes: `727,100,000 IRR debtor`, `1,157,200,000 IRR` Sales, `3` Sales docs, `29` undelivered.
- Approved Activity: `تماس برای بررسی سفارش بعدی`, due `1405/06/12`; Follow-up Queue = `0 / 0 / 1`.
- Approved Opportunity: `OPP-20260831-011700-86AC`, `qualification`, amount `900,000,000 IRR`, probability `50%`; weighted Pipeline `450,000,000 IRR`.
- Job #93 verified the CRM additions without changing the pre-existing financial/sales facts.
