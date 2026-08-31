# ERPSMART — AI Development Handoff

> سند فشرده برای AI/Developer جدید. قبل از استفاده، `00-START-HERE.md` و Master Spec مرجع هستند.

## Product

ERPSMART یک **AI-native Business Operating System** برای Vertical اول trading/import/distribution B2B است.

```text
Architecture: ERPSMART Intelligence Platform
User-facing AI: ERPSMART Business Copilot
```

ERP/Domainها Source of Truth هستند. Business Copilot لایه همیشه‌حاضر برای Search/Analysis/Orchestration/Guarded Action/Proactive supervision است.

## Current baseline / phase — 2026-08-31

```text
Repository: AmirAbedini78/excel-dev
Branch: main
Baseline: 338e13419d091e6e1d3a5e7fd836ac7296e88e6b
Frozen safety milestone: v9.3 Commercial MVP — LIVE-VALIDATED / FROZEN
Latest fully closed business milestone: v10.4 Cycle 7 CRM-lite / Customer 360 — LIVE-VALIDATED
v10.5 Cycle 8 r1: PARTIAL — Context Kernel retained; forced dedicated-page UX RETIRED
Working milestone: v10.6 Cycle 9 Universal Business Copilot Foundation — PLANNED
Immediate contract: docs/ai/20-UNIVERSAL-BUSINESS-COPILOT-48H-MVP.md
Master architecture: docs/ai/19-ERPSMART-INTELLIGENCE-PLATFORM-MASTER-SPEC.md
```

## Proven business chain

The source already has live-proven primitives across:

```text
Finance
→ Procurement
→ Inventory / Warehouse Receipt
→ Trade Case / Shipment / Landed Cost
→ Sales Reservation / Delivery / Margin
→ CRM Customer 360 / Follow-up / Opportunity
```

Do not rebuild these truths in an AI-specific data model.

Important live evidence includes:

- v9.3 Job #54 → Proposal #3 → human approval → balanced voucher draft.
- Cycle 4 Job #70 inventory/receipt truth.
- Cycle 5 Jobs #71–#78 Trade/Landed Cost and performance closeout.
- Cycle 6 Jobs #79/#83–#87 reservation, delivery, stock and actual-landed margin.
- Cycle 7 Jobs #88–#93 Customer 360, Activity, Follow-up, Opportunity and Pipeline.

## Cycle 8 r1 interpretation

`338e134` introduced typed CRM page context. It is **not** to be reverted wholesale.

Keep:

- typed browser reference
- server validation
- context_json transport
- Worker context handling
- fail-closed mismatch

Retire as product direction:

```text
page-specific Ask AI button → dedicated AI page
```

Generalize into Universal Entity/Context architecture and Global Sidecar.

## Safety invariants

1. LLM direct SQL ندارد.
2. Current business facts از deterministic Domain/Tool می‌آید.
3. RAG current ledger/inventory truth نیست.
4. LLM ERP IDs را تولید نمی‌کند.
5. Context/Memory/RAG مجوز ایجاد نمی‌کند.
6. R2/R3 mutations از server Policy/Proposal/Approval عبور می‌کنند.
7. Retry/idempotency duplicate ایجاد نمی‌کند.
8. Workspace/company/RBAC server-side re-check می‌شود.
9. Forecast numeric output از Engine عددی می‌آید.
10. Generic model path write Tool descriptor دریافت نمی‌کند.
11. Secret/trace exposure باید allowlisted/redacted بماند.
12. Cross-company context در P0 fail closed است.

## Target architecture

```text
UI: Sidecar + Intelligent Home + Analysis Workspace
                     ↓
               Copilot Gateway
                     ↓
Context + Identity/Policy + Experience Role
                     ↓
Intent → Capability Retrieval → Supervisor
                     ↓
        Skills / Tools / Domain Engines
                     ↓
Policy → Execute → Verify/Compensate
                     ↓
             Experience Store → Evals
```

## Universal Entity contract

Browser selects minimal typed refs. Server resolves canonical entity handles through registered providers.

P0 target entities:

```text
Customer
Supplier
Item
Sales Document
Purchase Document
Trade Case
Shipment
Warehouse
Delivery
CRM Opportunity
Voucher
```

Do not implement this as a growing `if/elseif` list inside `AiPageContext` or `AiModule`.

## User UX contract

Composer:

```text
@ Entity
+ Context/File/Selection
/ Skill/Action
🎤 Voice later
```

Current page is context-aware but not blindly injected. Conversation persists while navigating. Large results can move to Analysis Workspace.

## Orchestration

P0: one Business Copilot Supervisor + deterministic Domain Engines. Maximize the single-agent loop first. Multi-Agent is deferred until eval proves need.

Workflow Grammar:

```text
Resolve Read Filter Aggregate Compare Join Rank Calculate Predict Detect
Recommend Propose Approve Execute Verify Notify Wait Branch Loop Escalate
```

## Model strategy

```text
Deterministic → Small Local → Strong Local → Cloud Reasoning
```

Accuracy is established by eval first; cost/latency optimization comes after.

## RAG/Data strategy

P0 does not require a graph DB, vector DB or lakehouse.

Start with:

```text
Operational DB
Entity/Relation Registry
Execution Trace/Experience
Metrics/Evals
```

Files can be uploaded/linked/previewed before production RAG is introduced.

## Development workflow

Before edit:

- read canonical docs + Master Spec + 48h plan;
- inspect exact source baseline;
- identify affected contracts and domain truth;
- define exact file set and rollback;
- build candidate outside repo.

After edit:

- lint/compile;
- full existing regression;
- focused new contract tests;
- product smoke/live gate;
- docs closeout;
- exact staging; never default to `git add .`;
- commit/push/deploy only after the required validation level passes.

## Immediate next implementation

D0–D2 MVP A from `20-UNIVERSAL-BUSINESS-COPILOT-48H-MVP.md`:

- global Sidecar
- persistent conversation
- Universal Entity Registry/Resolver
- `@` mention/search
- multi-entity chips
- Context Envelope v2
- Quick Preview
- first cross-module Customer Business Review
- reuse existing Tool/Proposal/Worker stack

The first runtime patch must be a coherent Universal Copilot vertical, not another page-specific context experiment.
