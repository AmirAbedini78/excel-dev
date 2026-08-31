# 20 — Universal Business Copilot — 48-hour MVP and two-day delivery cadence

Status: `PLANNED`

Baseline for the first implementation candidate:

```text
338e13419d091e6e1d3a5e7fd836ac7296e88e6b
```

This plan turns the Master Spec into small but **presentable** increments. Every two-day window must produce an end-to-end slice that can be shown without explaining unfinished plumbing.

## Delivery principle

Each increment must pass:

```text
Exact baseline / changed-file boundary
→ lint/compile
→ existing full regression
→ new contract tests
→ local product smoke
→ CI
→ deploy when runtime/UI changed
→ live acceptance gate
→ SmartDocs closeout
```

No increment is allowed to trade structural quality for a demo-only hack.

## D0–D2 — MVP A: Universal Copilot Foundation

### User-visible outcome

From supported ERPSMART pages, the user opens **Business Copilot as a Sidecar without leaving the current page**, types `@`, finds business records, attaches several typed entities and asks a grounded question.

### Scope

1. Global Copilot shell available on authenticated pages when AI module/permission allows it.
2. Persistent Sidecar open/closed state and active conversation across normal page navigation.
3. `@` mention dropdown with grouped deterministic search.
4. Multi-entity chips in the composer.
5. Universal Entity Registry v1 and server Context Resolver.
6. Context Envelope v2; current page is available context, explicit mentions are attached context.
7. Generic Quick Preview contract with at least a rich preview for Customer and Shipment and safe generic fallback for other supported types.
8. Reuse existing `ai_conversations`, `ai_jobs`, Tool Gateway, Proposal/Approval and Worker transport; no parallel AI queue.
9. Preserve dedicated AI page as secondary Command Center path; remove forced navigation as the normal page-aware interaction.
10. First cross-module Skill: `Customer Business Review`.

### Initial Entity providers

Target the records already represented by canonical domain tables/services:

```text
party.customer
party.supplier
item
sales.document
purchase.document
trade.case
shipment
warehouse
finance.voucher
```

Delivery/Opportunity can be added inside this increment only if they do not jeopardize the acceptance gate; they are mandatory by MVP B at the latest.

### Search behavior

Typing:

```text
@
@کارخانه
@SHP-
@PLC
```

must show bounded grouped results. Browser labels are display-only; selection posts typed refs and the server resolves them again.

### First Magic Moment

```text
وضعیت معاملاتمون با @کارخانه بهین بسته‌بندی چطوره؟
```

Expected: a grounded Customer Business Review combining the supported real facts already available in Finance/Sales/Fulfillment/CRM, with Evidence links and no model-generated business IDs.

Then, without leaving the page:

```text
این مشتری رو با @فروشگاه پارس الکترونیک مقایسه کن
```

If comparison capability is not yet a registered fast path, the Supervisor must either produce a valid bounded read plan from available capabilities or clearly report the unsupported gap; it must not hallucinate.

### D0–D2 Definition of Done

- no forced navigation to AI page for normal Sidecar use;
- Sidecar present on at least CRM, Sales, Trade and Inventory pages through one global integration point, not four copied widgets;
- `@` search works for the initial providers;
- at least two entities can coexist in one prompt;
- company/workspace/RBAC are server-validated for every selected ref;
- cross-company leakage test = 0;
- stale browser labels cannot change canonical entity identity;
- current page context is not blindly injected into an unrelated question;
- Customer Business Review reconciles with canonical Tools;
- existing 124-test baseline remains green plus new Universal Copilot contract tests;
- entity search and preview require no LLM call;
- no DB migration unless implementation proves it strictly necessary and documents why.

## D2–D4 — MVP B: Skills, Capability Retrieval and Business Comparison

### User-visible outcome

The user can use `/` to discover useful business Skills and can ask multi-entity/cross-domain questions without knowing Tool names.

### Scope

- Skill Registry v1
- `/` Skill/Action picker
- deterministic Capability Retriever v1
- constrained Supervisor/Planner over retrieved capability set
- add remaining P0 providers for Delivery and CRM Opportunity
- rich Table/Comparison/Risk cards
- Evidence drawer
- Skills:
  - Customer Business Review
  - Supplier Performance Review
  - Trade / Shipment Risk
- multi-entity comparison workflow

### Presentable demo

```text
/compare @Supplier-A @Supplier-B
```

and:

```text
اگر @Shipment-X دیر برسد، چه تعهدهای فروش شناخته‌شده‌ای تحت تأثیر قرار می‌گیرند؟
```

The second question may be limited to relationships currently represented in canonical data. Missing relationship evidence must be surfaced explicitly.

## D4–D6 — MVP C: Role Brief + Intelligent Home + Experience Trace

### User-visible outcome

CEO and Commercial Manager do not see the same home priorities. The home page presents a concise business brief and exceptions with actions that open the same Copilot conversation.

### Scope

- Experience Role v1 derived from configured role/profile
- role-aware brief templates/capabilities
- Executive Business Brief Skill
- exception/work-item cards
- feedback `👍 / 👎 / corrected`
- Execution Trace / Experience logging foundation
- entity-linked AI history metadata foundation

### Presentable demo

CEO sees risk/cash/margin/delivery exceptions first; Commercial Manager sees supplier/shipment/ETA/commitment issues first, without any permission escalation.

## D6–D8 — MVP D: Guarded Operator inside the Sidecar

### User-visible outcome

A user can move from analysis to a prepared real business action without leaving the assistant, while the existing Proposal/Approval safety remains intact.

### Scope

- Proposal cards rendered in Sidecar
- edit/review/reject/approve handoff using existing server contracts
- Action Risk level visible to the user
- reversible/compensatable metadata foundation
- verify result after approved execution
- entity/action history entry

### Presentable demo

```text
برای @کارخانه بهین بسته‌بندی یک پیگیری مناسب آماده کن.
```

or a currently supported grounded operational action. R2/R3 paths never silently auto-execute.

## D8–D10 — MVP E: Proactive Watcher pilot + Notifications in-app

### Scope

- first two deterministic Watchers selected from Shipment Delay / Stockout / Receivable / Margin
- severity + impact + recommendation contract
- in-app notification/work-item only
- no external notification channel yet
- no autonomous high-risk execution

### Presentable demo

The user opens ERPSMART and sees one genuinely derived exception, asks Copilot to analyze it, and receives a grounded next action.

## D10–D12 — MVP F: Analysis Workspace + Saved Skill pilot

### Scope

- promote large result from Sidecar to Analysis Workspace
- rich chart/table/evidence layout
- save a configured supported Skill invocation
- replay through validated Skill version, not raw prompt macro
- evaluation record for replayed workflow

## Week 2 completion target

By the end of these increments, the product should demonstrate a coherent loop:

```text
Role-aware Home
→ exception or user question
→ Sidecar
→ @ Entity context
→ cross-module grounded analysis
→ recommendation
→ guarded Proposal/action
→ verification
→ trace/feedback
```

## Month 1 expansion

Only after the core loop remains stable:

- behavioral customer/supplier intelligence
- currency/external intelligence with source + timestamp
- more Watchers
- Notification Hub
- Undo/Compensation implementation per domain
- conversation per entity UI
- workflow promotion/evals
- cross-workspace sharing correction
- pilot data import and Design Partner onboarding

## Explicitly deferred from the first 48 hours

- separate graph database
- lakehouse
- production document RAG pipeline
- multimodal OCR/image reasoning
- multi-agent swarm
- fine-tuning
- voice
- external messaging integrations
- frontend framework rewrite
- broad autonomous execution

These are not rejected features; they are kept out of the first critical path to protect speed, reliability and architectural coherence.

## Engineering boundaries for MVP A

Expected service boundaries, names may change during source audit but responsibilities must stay separated:

```text
AiEntityRegistry       provider catalog and typed entity contract
AiContextResolver      actor/company/RBAC validation and canonicalization
AiContextEnvelope      versioned context assembly/limits
AiEntitySearch         deterministic bounded mention search
AiCapabilityRegistry   Skill/capability metadata
BusinessCopilot UI     reusable Sidecar/composer/thread shell
```

`AiPageContext` from Cycle 8 may be adapted as a compatibility layer; it must not become the universal class by accumulating per-module branches.

## Acceptance before code promotion

The first runtime candidate is not accepted merely when the Sidecar renders. It must prove:

1. useful `@` selection in the real UI;
2. canonical server resolution and permission isolation;
3. persistent conversation while navigating;
4. page-awareness without irrelevant Context contamination;
5. one real cross-module grounded business review;
6. no regression of existing read/write guardrails;
7. acceptable interaction latency;
8. no duplicate module-specific UI implementation.
