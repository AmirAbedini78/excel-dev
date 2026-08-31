# 19 — ERPSMART Intelligence Platform / Business Copilot — Master Product & Architecture Spec

Status: `PLANNED`

Product decision: accepted on 2026-08-31. This document is the canonical product/architecture bridge from the proven v10 trading vertical to the next ERPSMART interaction model.

## 1. Product identity

Architecture name:

```text
ERPSMART Intelligence Platform
```

User-facing intelligent layer:

```text
ERPSMART Business Copilot
```

North Star:

**ERPSMART is an AI-native Business Operating System for B2B trading/import/distribution companies. The ERP remains the operational system of record; Business Copilot becomes the pervasive intelligent interaction, analysis, orchestration and guarded-execution layer over Finance, CRM, Sales, Procurement, Inventory and Trade/Logistics.**

The product must not collapse into either of these extremes:

```text
traditional ERP + decorative chatbot          ❌
free-form autonomous agent without controls   ❌

trusted operational core + pervasive AI UX
+ composable capabilities + guarded execution ✅
```

## 2. First market wedge

Primary ICP remains:

- B2B importer / trading company
- distributor / wholesaler
- trading-heavy manufacturer where procurement/import/sales/finance are tightly coupled

The first differentiated narrative is:

```text
Trade Resilience
+ Commercial Intelligence
+ Cross-module Automation
```

ERPSMART may later expand into Production, HR, Project, Service and other domains, but those domains do not enter the critical path before evidence from Design Partners.

## 3. Four simultaneous roles of Business Copilot

### Assistant
Find, navigate, explain and summarize trusted business data.

### Analyst
Compare, reconcile, investigate causes, calculate impact, detect exceptions and forecast with the correct deterministic/statistical engine.

### Operator
Prepare and execute supported business actions through typed Tools, validation, Risk Policy and Approval.

### Autonomous Supervisor
Monitor defined business conditions continuously, surface exceptions, recommend next-best actions and—only where policy allows—execute bounded low-risk work.

The maturity path is incremental. P0 proves Assistant + Analyst + guarded Operator. Autonomous Supervisor expands only after eval, audit and compensation foundations exist.

## 4. Product UX contract

### 4.1 Global Sidecar is the primary daily interaction

Business Copilot must be available from authenticated ERPSMART pages without forcing navigation away from the current task.

Desktop:

```text
┌─────────────────────────────────────────┬─────────────────────────┐
│ Current ERP page                        │ Business Copilot        │
│                                         │                         │
│ Sales / Trade / CRM / Finance / ...     │ Context chips           │
│                                         │ Conversation            │
│ User continues seeing the page          │                         │
│                                         │ @  +  /  🎤        Send │
└─────────────────────────────────────────┴─────────────────────────┘
```

RTL opens on the ergonomically appropriate edge and must not obscure the critical form/table area. Mobile becomes a bottom sheet or full-screen assistant.

### 4.2 Dedicated AI page is retained but its purpose changes

The current dedicated AI page must evolve into an **AI Command Center / Analysis Workspace**, not remain the only place where AI can be used.

It is for:

- long analyses
- larger tables/charts
- evidence drill-down
- conversations/history
- proposals/approvals
- agent activity
- saved skills
- monitors/alerts

### 4.3 Intelligent Home replaces dashboard overload

Home is role-aware and exception-first:

```text
Role Brief
+ Priority Exceptions
+ Agent/Workflow Work Items
+ Conversational Command Bar
```

It must not become a fixed wall of dozens of widgets. Users can inspect stable KPIs, but the primary surface emphasizes work that needs attention.

### 4.4 Role adaptation is controllable

Separate:

```text
Permission Role  = what the user is authorized to see/do
Experience Role  = what information, vocabulary and work items are prioritized
```

Experience adaptation may change ranking, presentation and quick actions; it must never create authority. Adaptation must be predictable, explainable and user-controllable.

Initial experience profiles:

- CEO / General Manager
- Commercial / Trade Manager
- Finance Manager
- Sales / CRM user
- Procurement user
- Warehouse user

## 5. Universal Composer contract

The composer has four complementary affordances:

```text
@   Business Entity mention
+   Context / selection / file / data attachment
/   Skill or Action shortcut
🎤   Voice input (later phase)
```

Natural language remains primary; shortcuts improve discoverability and speed.

Example:

```text
معاملات @کارخانه بهین بسته‌بندی را با @شرکت تامین برق ایرانیان مقایسه کن
```

The rendered chip is a presentation label. The execution identity is a server-resolved typed entity handle.

## 6. Context model: awareness is not injection

Context is divided into independent channels.

### 6.1 Current Page Context

The Sidecar knows what page/record the user is viewing, but page context is **available context**, not automatically dumped into every model prompt.

If the user says `این محموله`, current page context may resolve the referent. If the user asks `فروش این ماه`, irrelevant shipment context must not distort the plan.

### 6.2 Explicit Entity Context

Entities the user explicitly chooses with `@` or `+`.

### 6.3 Selection Context

Rows/records selected in an ERP table and intentionally sent to Copilot.

### 6.4 File/Document Context

Files explicitly attached or linked. In P0 they are stored, previewed and related to entities. RAG processing is not on the first 48-hour critical path.

### 6.5 Conversation Context

Prior conversational referents such as `اون فاکتور قبلی`. Conversation memory can help resolve language but must never replace a fresh Tool read for current ERP truth.

## 7. Universal Entity Registry

The Entity Registry is a central platform contract, not a CRM-specific helper.

Initial type namespace:

```text
party.customer
party.supplier
contact
item
warehouse
sales.document
purchase.document
trade.case
shipment
delivery
inventory.receipt
crm.opportunity
crm.activity
finance.voucher
cash.account
check
file
conversation
user
workspace
company
```

Future modules register providers without changing the AI Kernel.

### 7.1 Browser request contract — untrusted

The browser may request only the minimum typed pointer:

```json
{
  "type": "party.customer",
  "id": "3",
  "source_hint": "crm"
}
```

Labels, balances, amounts, permissions, company IDs and business facts sent by the browser are never authority.

### 7.2 Canonical server EntityRef

After server resolution:

```json
{
  "schema": "erpsmart.entity-ref.v1",
  "type": "party.customer",
  "id": "3",
  "code": "CUS-003",
  "label": "کارخانه بهین بسته‌بندی",
  "subtitle": "Customer",
  "workspace_id": 1,
  "company_id": 6,
  "provider": "accounting.party",
  "source": "mention"
}
```

This remains a pointer, not a cached snapshot of current facts.

### 7.3 Provider contract

Every entity provider exposes a common conceptual interface:

```text
type()
search(query, scope)
resolve(id, scope)
preview(ref, scope)
relations(ref, scope)
permissions(ref, actor)
available_tools(ref, actor)
available_actions(ref, actor)
deep_link(ref)
```

Providers call existing Domain services/Repositories. They must not duplicate canonical business calculations.

### 7.4 Search contract

Mention search is deterministic and permission-scoped; it does not call an LLM.

Ranking inputs may include:

1. exact/prefix business code match
2. label/name match
3. current-page relationship
4. recently selected entities
5. role/domain relevance

Search results are bounded and grouped by type. Every selected result is resolved again on the server before a Job is queued.

## 8. Universal Quick Preview

A consistent design primitive across ERPSMART:

```text
Entity Chip → Quick Preview → Full Record
```

Preview is server-generated and permission-scoped. It returns a bounded summary and safe actions/deep links. Preview must not expose fields the user cannot access.

## 9. Context Envelope v2

`ai_jobs.context_json` remains useful, but the CRM-only `page_context v1` evolves into a versioned universal envelope.

Conceptual contract:

```json
{
  "schema": "erpsmart.context-envelope.v2",
  "conversation_id": 123,
  "current_page": {
    "module": "trade",
    "route": "shipment.view",
    "entity": {"$ref": "server-canonical-entity"}
  },
  "explicit_entities": [],
  "selection": [],
  "files": [],
  "intent_hints": [],
  "locale": "fa-IR"
}
```

Rules:

- only server-canonical refs are persisted as validated context;
- `current_page` can be known without becoming model evidence;
- every Tool invocation re-checks actor/workspace/company/permission;
- Context never grants permission;
- stale business facts are never trusted from stored Context;
- cross-company refs are blocked by default in P0;
- context size is bounded.

## 10. Business Relationship Graph — registry first, graph DB later

Cross-module reasoning needs relationships, not only document retrieval.

Example:

```text
Customer
→ Sales Document
→ Reservation
→ Delivery
→ Item
→ Inventory
→ Purchase
→ Supplier
→ Trade Case
→ Shipment
→ Trade Cost / Landed Cost
```

P0 implements a **Relation Registry over the existing relational database**. A separate graph database is deferred until real query/evaluation evidence justifies it.

## 11. Tool Platform contract

Tool is a typed, deterministic capability over the operational system.

Examples:

```text
crm_customer_360
get_party_balance
sales_fulfillment_read
trade_shipment_risk
create_crm_activity (Proposal path)
```

Rules:

- Tools are standardized, reusable and versionable.
- Tool input/output schemas are explicit.
- authorization and company/workspace scope are server-owned.
- business calculations live in Domain services, not prompt text.
- LLM never receives direct operational DB credentials or arbitrary SQL.
- write tools are not exposed to generic read-only planning paths.

## 12. Skill Registry contract

A Skill is a user/business capability composed from Tools and workflow primitives.

Example:

```text
Customer Business Review
Supplier Performance Review
Trade / Shipment Risk
Executive Business Brief
```

Conceptual Skill definition:

```yaml
skill_id: customer.business_review
version: 1
roles: [ceo, commercial, sales, finance]
required_entities: [party.customer]
capabilities: [sales, fulfillment, receivables, crm, margin]
required_tools: [...]
workflow: customer_business_review_v1
risk_level: R0
approval_policy: none
output_schema: rich_business_review_v1
eval_suite: erpsmart-bench/customer-review-v1
```

Skills are discoverable through `/` and through natural language matching.

## 13. Workflow Grammar instead of thousands of hard-coded workflows

Canonical primitive vocabulary:

```text
Resolve
Read
Filter
Aggregate
Compare
Join
Rank
Calculate
Predict
Detect
Recommend
Propose
Approve
Execute
Verify
Notify
Wait
Branch
Loop
Escalate
```

Planner output is a constrained, validated representation using these primitives and registered capabilities. The model does not invent arbitrary code or operational IDs.

## 14. Intent + Capability Retrieval + Planner

Execution path:

```text
Natural Language
→ Intent understanding
→ Context/entity resolution
→ Capability retrieval
→ exact Skill fast path when available
→ otherwise constrained Supervisor planning
→ Policy validation
→ Tools / Engines
→ result verification
→ rich response
```

With many Tools/Skills, the complete descriptor catalog is not dumped into every prompt. Capability retrieval supplies the bounded relevant set.

Initial retrieval can be deterministic by domain/entity/capability tags and lexical rules. Embedding-assisted retrieval is optional later and must be evaluated before adoption.

## 15. Orchestration strategy

P0 uses one user-facing **Supervisor/Manager** and maximizes a single-agent/tool loop plus deterministic services.

Domain Engines are logical boundaries; they are not automatically separate LLM agents.

```text
Business Copilot
      ↓
Supervisor
      ↓
Finance | CRM | Sales | Trade | Inventory | Procurement
```

Multi-agent execution is deferred until evaluation shows that instruction/tool overlap or workflow specialization materially improves reliability. If added, Manager Pattern is preferred so the user still experiences one coherent assistant.

## 16. Model Router

Not every task should use one model.

Routing ladder:

```text
Deterministic path
→ Small local model
→ Strong local model
→ Cloud reasoning model
```

Routing factors:

- task complexity
- accuracy requirement
- risk level
- latency budget
- privacy policy
- cost
- required modality/capability

Model replacement must never change Tool authorization or business truth contracts. Evals establish accuracy baseline before smaller/faster models replace stronger ones for a task.

## 17. Rich response contract

The assistant does not reduce every result to chat text.

Supported presentation primitives:

```text
Narrative summary
Entity Card
KPI Card
Table
Chart
Comparison
Risk Card
Recommendation Card
Proposal / Approval Card
Evidence Drawer
Deep Link
```

Every metric is labeled conceptually as one of:

```text
Fact
Calculated Metric
Statistical Signal
Prediction
Recommendation
```

This distinction prevents a recommendation or prediction from visually masquerading as recorded ERP fact.

## 18. Evidence-first grounding

Primary output should remain readable; evidence is available on demand.

Example:

```text
Sources: ERP • Shipment • Sales • Landed Cost
```

Evidence drill-down maps claims to server records/Tool outputs or timestamped external sources. Internal free-form chain-of-thought is not an evidence artifact.

## 19. Action Risk Engine

Canonical levels:

| Level | Meaning | Default behavior |
|---|---|---|
| R0 | Read / analyze | direct |
| R1 | reversible low-risk | configurable auto/confirm |
| R2 | operational impact | configurable approval |
| R3 | financial/legal/destructive | mandatory approval |

Every action also declares:

```text
reversible
compensatable
irreversible
```

Execution contract:

```text
Propose/Plan
→ Policy
→ Approval when required
→ Execute deterministically
→ Verify
→ Audit
→ Undo/Compensate where supported
```

Posted accounting is compensated through a reversal, not destructive deletion.

## 20. Persistent conversation and entity history

Conversation remains available while the user navigates pages.

The platform distinguishes:

```text
Conversation subject
Current page
Explicitly attached context
```

They may differ at the same time.

Entity pages may later show AI History filtered by canonical EntityRefs. This requires a typed relation from conversation/run to entities, not text search over messages.

## 21. Memory architecture

Five memory classes remain separate:

1. Conversation Memory
2. User Preference Memory
3. Business Experience Memory
4. Workflow/Skill Memory
5. Knowledge Memory

P0 does **not** perform uncontrolled online learning from every user action.

## 22. Experience Store and safe learning

Every supported agent run should eventually capture:

```text
run_id
actor / experience role
goal
page context
explicit entities
intent
selected capability/skill
validated plan
tool calls + references
policy/risk decisions
approval/edit/rejection
verified result
feedback
latency/cost/model/errors
undo/compensation outcome
```

Promotion path:

```text
Execution Trace
→ Outcome Evaluation
→ Human Feedback
→ Experience Dataset
→ Skill Candidate
→ Offline Eval
→ Controlled Promotion
```

No run becomes production policy merely because a user did it once.

## 23. Proactive Engine

Post-P0 watchers include:

- Shipment Delay
- Stockout
- Margin at Risk
- Receivable / Collection
- Supplier Reliability
- Currency Exposure
- Sales Pipeline
- Customer Churn
- Trade Cost Variance

Signal pipeline:

```text
Signal
→ Severity
→ Business impact
→ Recommendation
→ optional Policy-bounded action
```

Notifications later support in-app first, then configured Email/SMS/Telegram/WhatsApp/Push channels as product/legal constraints allow.

## 24. Role-Adaptive Intelligent Home

Initial role focus examples:

### CEO
Exceptions, cash/liquidity, margin, delivery commitments, major trade risk, top customer/supplier exposure, forecast.

### Commercial Manager
Orders, suppliers, shipments, ETA, Trade risk, landed cost, currency exposure, customer commitments.

### Finance Manager
Cash, AR/AP, liquidity, margin, payment commitments, finance risk.

### Warehouse
Receiving, QC, put-away, availability, reservation, shortage, dispatch.

The role changes priority and presentation, not permissions.

## 25. Files and RAG roadmap

P0 files:

```text
upload
store
link to EntityRefs
metadata
preview
download
```

Production RAG is a later subsystem with its own ingestion, parsing, access control, retrieval, reranking, evaluation and observability. It does not enter the 48-hour core simply because file upload exists.

RAG remains inappropriate as source of current ledger/inventory/sales truth.

## 26. Data platform roadmap

P0/P1 data foundation:

```text
Operational DB
Event / Execution Trace Store
Entity + Relation Registry
Experience Store
Metrics/Eval Store
```

Later, when scale and analytics evidence justify it:

```text
Raw / Bronze
Curated / Silver
Trusted / Gold
lineage / metadata / MDM / governance
```

A lakehouse is not required to prove the next Copilot MVP.

## 27. Security architecture

Security is part of the architecture before autonomy.

Mandatory controls:

- server-validated context
- workspace/company isolation
- RBAC and action-level authorization
- no authority inherited from Context/Memory/RAG
- scoped Tool registry
- input + argument schema validation
- risk classification
- Tool/turn/time budgets
- approval policy
- immutable/bounded audit metadata
- secret redaction
- prompt-injection defenses at untrusted-content boundaries
- memory isolation and provenance
- fail-closed entity mismatch
- no model-generated ERP IDs
- no operational DB access from Worker/LLM
- idempotent mutations and terminal retries
- explicit Agent identity when autonomous workers are introduced

Threat-model mapping must cover at least Agent Goal Hijack, Tool Misuse, Identity/Privilege Abuse, Agentic Supply Chain, unexpected code execution, Memory/Context Poisoning, inter-agent communication risks, cascading failure, human-agent trust exploitation and rogue-agent behavior.

## 28. Performance architecture

Fast interaction is a product requirement, not an afterthought.

Rules:

- Sidecar shell/assets load once and do not fetch entity catalogs eagerly.
- `@` search is debounced, bounded and deterministic.
- Context stores refs, not large snapshots.
- exact supported reads prefer deterministic fast paths.
- capability retrieval limits Tool/Skill descriptors before model calls.
- large analysis moves to Analysis Workspace instead of freezing the page.
- model routing optimizes latency/cost only after accuracy gates.

Initial UX targets for supported P0 paths:

```text
Sidecar open: perceived immediate from already-loaded shell
Entity search p95: < 500 ms on pilot dataset
Entity preview p95: < 700 ms on pilot dataset
Deterministic supported business read: target < 2 s server-side excluding network anomalies
No unnecessary model call for mention/search/preview
```

Existing commercial route-specific latency budgets remain authoritative where already defined.

## 29. ERPSMART-Bench

Domain suites:

```text
ERPSMART-Bench / Trade
ERPSMART-Bench / CRM
ERPSMART-Bench / Sales
ERPSMART-Bench / Inventory
ERPSMART-Bench / Finance
ERPSMART-Bench / Copilot-UX-Contracts
```

Metrics:

- intent accuracy
- entity resolution
- context relevance
- Tool selection
- argument grounding
- plan validity
- result reconciliation
- unauthorized action rate
- hallucination rate
- latency
- Tool count
- recovery rate
- human correction rate

One-month target, not a promise for the first 48 hours:

```text
≥90% supported Entity resolution
≥95% correct Tool selection on benchmark
100% unauthorized writes blocked
100% financial mutations policy-checked
≥80% supported management questions grounded
≥50% fewer navigation/search steps on selected pilot workflows
≥30% faster recurring workflows
<5% correction on promoted deterministic Skills
0 cross-workspace leakage
```

Prediction quality has separate metrics and is never mixed with factual QA accuracy.

## 30. P0 / P1 / P2 product scope

### P0 — Pilot-ready Universal Business Copilot

- Global Sidecar
- persistent conversation
- page awareness without blind injection
- `@` Entity Mention/Search
- multi-entity attach
- Universal Entity Registry v1
- Quick Preview
- Context Envelope v2
- Role Profile v1
- Role Brief / Intelligent Home first slice
- `/` Skill picker
- Tool/Skill Registry
- Supervisor/Planner v1
- cross-module read workflows
- rich response primitives
- Evidence links
- Action Risk Policy integration
- existing Proposal/Approval integration
- execution trace + feedback + Experience logging foundation

P0 entity targets:

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

P0 killer skills:

```text
Customer Business Review
Supplier Performance Review
Trade / Shipment Risk
Executive Business Brief
```

### P1 — Operational intelligence expansion

- Saved Skills
- Scheduled Agents
- Watchers / Alerts
- Notification Hub
- Undo / Compensation framework
- Behavioral scoring
- Currency intelligence
- conversation per Entity
- Role-adaptive navigation
- Full Analysis Workspace
- Workflow learning/promotion
- cross-workspace sharing correction

### P2 — Advanced intelligence

- Document Intelligence
- multimodal RAG
- Voice Commander
- image processing
- Email/Drive/WhatsApp integrations
- Knowledge Graph where justified
- Agent-to-Agent where justified
- fine-tuning after licensed/evaluated data
- advanced forecasting
- policy learning
- mobile optimization

## 31. Migration from v10.5 Cycle 8 r1

Commit `338e13419d091e6e1d3a5e7fd836ac7296e88e6b` is not discarded.

Retain:

- typed browser references
- server context validation principle
- `ai_jobs.context_json`
- Worker context transport
- fail-closed explicit-name/context mismatch
- Proposal/Approval boundaries

Refactor/generalize:

```text
AiPageContext CRM branch
        ↓
AiEntityRegistry
AiContextResolver
AiContextEnvelope
AiCapabilityRegistry / Skill Registry
```

Retire as product UX:

```text
Customer 360 → Ask AI → leave page → dedicated AI page
```

The dedicated page survives only as Command Center/Analysis Workspace.

## 32. Anti-spaghetti architecture rules

1. Do not add a new `if module == X` branch to the central AI UI for every Entity type; register a provider.
2. Do not duplicate Domain calculations inside AI adapters.
3. Do not create a second customer/item/sales truth store for AI convenience.
4. Do not bind contracts to a single model/provider/framework.
5. Do not introduce a frontend-framework rewrite into P0; extract reusable components inside the current stack first.
6. Do not introduce a graph DB/lakehouse/vector DB because the architecture diagram mentions future graphs/data/RAG.
7. Do not dump all available Context/Tools into prompts.
8. Do not teach production policy directly from raw user behavior.
9. Do not allow Context, Memory, RAG or model text to create authorization.
10. Every 48-hour increment must leave a coherent demoable product slice and preserve regression.

## 33. Canonical end-state architecture

```text
                         ERPSMART UI
                             │
                    Business Copilot UX
           ┌─────────────────┼─────────────────┐
           │                 │                 │
       Sidecar        Intelligent Home   Analysis Workspace
           │                 │                 │
           └─────────────────┼─────────────────┘
                             │
                       Copilot Gateway
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
   Context Engine      Identity/Policy       Role UX Profile
        │                    │                    │
        └────────────────────┼────────────────────┘
                             │
                     Intent Interpreter
                             │
                    Capability Retriever
                             │
                    Planner / Supervisor
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
      Skills                Tools             Engines
        │                    │                    │
        └────────────────────┼────────────────────┘
                             │
                       Policy Engine
                             │
                      Execution Engine
                             │
                    Verify / Compensate
                             │
                      Experience Store
                             │
                          Evals
```

## 34. Product acceptance rule

A feature is not accepted merely because a button exists or a unit test passes. Acceptance requires:

```text
useful user workflow
+ coherent UX
+ canonical data grounding
+ permission/risk safety
+ regression
+ product/live evidence at the required validation level
```

Cycle 8 r1 is the explicit example: its kernel primitive is useful, but its forced page-navigation UX was not product-accepted.
