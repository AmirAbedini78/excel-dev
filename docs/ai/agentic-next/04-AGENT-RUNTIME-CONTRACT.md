# 04 — Agent Runtime Contract (FROZEN v0.1 for Wave 1)

**Status:** FROZEN for dependent coding (2026-11-21)  
**Compatibility:** Must map onto existing `AiToolRegistry`, `ai_jobs`, proposals — **no parallel engine**.

## 1. Typed Tool descriptor (target shape)

Every AI-accessible tool MUST be expressible as:

```json
{
  "id": "domain.capability",
  "version": "1.0",
  "mode": "read|proposal",
  "risk": "low|medium|high",
  "required_permissions": ["domain.view"],
  "company_scope": "required",
  "input_schema": {},
  "output_schema": {},
  "max_rows": 100,
  "timeout_ms": 15000,
  "idempotency": "not_applicable|required",
  "evidence_policy": "required",
  "audit_policy": "summary"
}
```

### Mapping to current PHP registry

| Contract field | Current `AiToolRegistry` field / behavior |
|----------------|-------------------------------------------|
| `id` | `name` (stable string); Wave1 adds optional `id` alias = `name` |
| `mode` | `mode` (`read` \| `proposal`) |
| `risk` | `risk` |
| `input_schema` | `parameters` JSON-schema-like |
| permissions | Enforced server-side in execute path — **never trust LLM** |
| company_scope | Job `company_id` + RBAC — server owned |

Wave1 deliverable: `AiToolRegistry::contractDescriptors()` adapter returning the v0.1 shape **without** forking a second registry.

## 2. Durable task state machine

Represent agent progress as states stored in `ai_jobs.result_json.agent_task` (additive JSON; avoid new ledger tables unless T0 approves):

```
CREATED → VALIDATING → PLANNING → EXECUTING_READS → ANALYZING
  → PROPOSAL_READY → AWAITING_APPROVAL → EXECUTING_APPROVED_ACTION
  → VERIFYING → COMPLETED
```

Terminal: `FAILED` | `CANCELLED` | `EXPIRED` | `BLOCKED` | `PARTIALLY_COMPLETED`

### Recovery invariants

- Lease expiry / worker restart / duplicate callback MUST NOT duplicate vouchers, payments, stock, or purchase/sales docs.
- Mutations remain `proposal → human approve → AiToolRegistry::executeProposal` with idempotency_key where applicable.
- Company change in conversation forces re-VALIDATING (permissions + entity re-check).

## 3. Authority boundary

| Actor | May | Must not |
|-------|-----|----------|
| Model / planner | Choose server-issued action/tool IDs; explain evidence | Invent tool names, SQL, IDs, permissions, balances |
| Kernel | Bounded read loop; budgets | Execute writes |
| PHP control plane | Authorize, execute reads, store proposals, approve | Trust client/LLM for RBAC |
| RAG | DOCUMENT_EVIDENCE | Act as LIVE_ERP_FACT or authority |

## 4. Evidence kinds

- `LIVE_ERP_FACT` — from Tools  
- `DOCUMENT_EVIDENCE` — from RAG  
- `CALCULATED_FINDING` — deterministic domain math  
- `MODEL_INTERPRETATION` — explanation only  

## 5. Budgets (Wave1 defaults)

- Max read tool calls per task: **4** (kernel v1 compatible)  
- Max planner calls: **3**  
- Max wall retries on transient tool/provider errors: **2** with backoff  
- No unbounded specialist swarms  

## 6. Security tests required for AI1_ACCEPTED

1. Invalid tool ID rejected  
2. Cross-company context cannot leak  
3. Unsupported operation fails closed  
4. Duplicate job completion / callback does not double-apply side effects  
5. Financial writes remain approval-gated  
6. Worker restart resumes without duplicate mutation  

## 7. Change control

Any break of this contract requires T0-approved amendment in this file + dependent agent notification before merge.
