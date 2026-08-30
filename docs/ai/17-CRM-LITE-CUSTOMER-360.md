# ERPSMART v10.4 — CRM-lite / Customer 360

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`

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
1. CRM page opens and shows existing parties.
2. Named-customer Customer 360 read.
3. One manual contact.
4. CRM follow-up Proposal → approval → queue verification.
5. Opportunity Proposal → approval → Pipeline verification.
6. Re-read Customer 360 and verify Finance/Sales facts unchanged.
