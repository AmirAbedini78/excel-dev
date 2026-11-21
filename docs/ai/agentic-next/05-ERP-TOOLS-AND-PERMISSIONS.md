# 05 — ERP Tools and Permissions (T3)

**Owner:** T3 (ERP Tool Integration & Guarded Actions)  
**Contract:** `04-AGENT-RUNTIME-CONTRACT.md` v0.1 (FROZEN)  
**Baseline SHA:** `db849ca09b54c52e4729ee813abb1f6695549ac9`  
**Status:** Seeded Wave2 — extend `AiToolRegistry` only (no parallel ledger / tool engine)

## 1. Single registry rule

| Surface | Policy |
|---------|--------|
| Tool catalog | `AiToolRegistry::descriptors()` only |
| Typed contract | `AiToolRegistry::contractDescriptors()` adapter → v0.1 shape |
| Mutations | `mode=proposal` → `ai_action_proposals` → human approve → `executeProposal` |
| RAG / MCP | Must not invent live ledger facts; MCP off-by-default (T4) |

## 2. Permission model

- LLM never grants authority. Server checks `required_permissions` when present on a descriptor.
- Manufacturing Wave2 tools set explicit `required_permissions: ["production.view"]` and fail closed with `permission_denied:*`.
- Other tools get **inferred** permissions in `contractDescriptors()` for planner/ACL UX; enforcement is phased (explicit key only) to avoid breaking existing worker jobs before T0 matrix freeze.
- Company scope is always job `company_id` + domain `assertCompany` — cross-company IDs fail closed.

## 3. Manufacturing honest reads (new)

| Tool id | Domain | Surfaces |
|---------|--------|----------|
| `manufacturing_bom_read` | `ProductionDomain::listBoms/getBom` | Structural BOM only; `gl_posting=GL_BLOCKED` |
| `manufacturing_order_read` | `ManufacturingExecutionDomain` + `ManufacturingCostEvidence` / T5 rollup | `cost_class` often `UNVALUED`; always `gl_posting=GL_BLOCKED` |
| `manufacturing_material_readiness` | `ProductionDomain::preview` + MRP-lite recs | Shortage / unpriced → `UNVALUED` or provisional estimate; **no auto PO** |

**Forbidden:** autonomous WIP/FG GL posting, fake valued costs, duplicate stock/finance systems.

## 4. Workflows A–H (representative mapping)

| WF | Intent | Representative tools | Gate |
|----|--------|----------------------|------|
| A | Simple finance Q | `document_analytics`, `company_snapshot` | read |
| B | Multi-step Q | `sales_document_detail` + `party_ledger` / search tools | read |
| C | Deep analysis | `financial_analysis_bundle`, `periodic_business_report` | read + availability flags |
| D | Finance mutation | `create_voucher_draft`, `create_check`, invoice drafts | **proposal** |
| E | Multi-step ops | `create_warehouse_receipt`, sales reserve/deliver | **proposal** + approve |
| F | Prediction | *unsupported honestly* — no production forecast tool with backtest | fail closed / qualitative only |
| G | Proactive suggestion | `replenishment_risk`, `trade_risk_summary` (read evidence) | read; drafts still proposal |
| H | RAG | Document evidence only | **proposal-gated / non-authoritative** — must not replace Tool ledger facts |

## 5. Evidence kinds (tools)

Manufacturing reads emit `evidence_kind=LIVE_ERP_FACT` and must keep residual labels:

- `UNVALUED` — incomplete cost evidence  
- `GL_BLOCKED` — no automatic WIP/FG journal  

## 6. Tests

- `tests/php_agentic_t3_contract_descriptors_test.php` — contract shape (no DB)
- `tests/harness/agentic_t3_mf_tools_runner.php` + `tests/test_agentic_t3_erp_tools.py` — MySQL synthetic + permission deny + workflow modes

## 7. Out of scope / blockers for T0

- Do not merge competing registries or proposal tables.  
- Capability skill entry for MF in `AiCapabilityRegistry` deferred to T0 shared-file PR if freeze still holds.  
- Workflow F remains unsupported until a deterministic forecast domain exists.
