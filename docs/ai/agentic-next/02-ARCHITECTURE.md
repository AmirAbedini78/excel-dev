# 02 — Architecture (Agentic Next)

**Baseline:** `db849ca` · **Rule:** ERP owns business truth; Agent owns orchestration/explanation/controlled decision support.

## Control planes

```text
[Browser RTL Workspace / Copilot]
        │
        ▼
[PHP Control Plane — cPanel compatible]
  AiModule · ai_api · copilot_api · AiRepository
  AiToolRegistry · AiCapabilityRegistry · AiEntityRegistry
  AiContext* · AiProposalGuardrails · AiSchema (ai_jobs/proposals)
        │  lease / callback (no Worker DB credentials)
        ▼
[Python Worker — Docker/local]
  worker.py → agent_intelligence_kernel → provider_gateway
  rag.py (DOCUMENT_EVIDENCE only) · commercial_hardening
        │
        ▼
[Optional MCP facade — DISABLED until security gate]
  allowlisted Tools/Resources → same PHP authz + proposals
```

## Non-goals

- Second ledger, second tool registry, second approval store  
- Model as source of balances/stock/permissions  
- Unbounded multi-agent swarms  

## Wave dependency

Wave1 contracts/state → Wave2 RAG+tools → Wave3 MCP/providers/guarded ops → Wave4 Workspace+full eval.

## RC1 preservation

Commercial MVP Gate + RC1 MySQL/PHP Gate + TR-1/TR-2/MF-1/SYS-1/OPS-1 remain mandatory regression.
