# ERPSMART — AI Development Handoff

## 2026-09-11 — v10.10.3 / Kernel v1.6: bounded stream recovery

Current package: `ERPSMART-v10.10.3-stream-recovery-local-validated.zip`, applied over the exact delivered v10.10.2 working state. Current implementation and incident evidence: **30-STREAM-RECOVERY-20260911.md**; install/rollback: **28-LOW-MEMORY-INSTALL-FA.md**; results: **29-LOW-MEMORY-TEST-REPORT-FA.md**.

The supplied Windows log proves v10.10.2 Docker build, 268 offline tests, file installation and Worker startup PASS. Its model gate is DEGRADED: qwen3.5:0.8b provider_error, qwen3:1.7b deadline, gemma3:4b memory_admission. This does not establish an OOM for the first model. A reproducible cross-thread HTTP chunk cancellation bug is fixed in v1.6; first-request allowance, shared endpoint cooldown and redacted per-case evidence are included. v1.6 remains LOCAL-VALIDATED until tested on that engine. MVP-B semantic acceptance remains open; MVP C Role Brief / Intelligent Home follows that acceptance. Older dated entries below describe historical states.

### تکمیل و بسته تحویل — ۲۰۲۶/۰۹/۱۰

بسته فعلی `ERPSMART-v10.10.2-low-memory-intelligence-local-validated.zip` است. راهنمای نصب/بازگشت در `28-LOW-MEMORY-INSTALL-FA.md` و گزارش تست/محدودیت‌ها در `29-LOW-MEMORY-TEST-REPORT-FA.md` قرار دارند. نتیجه محلی: ۲۶۸ تست پروژه و ۹ تست تراکنش بسته موفق؛ Windows/Docker/Ollama واقعی، PHP/CI و پذیرش محصول در این نوبت هنوز تأیید نشده‌اند. ابتدا نصب موتور و سه خوانش محصول؛ پس از پذیرش معنایی، ادامه MVP C — Role Brief / Intelligent Home. حالت DEGRADED موفقیت مدل محسوب نمی‌شود.


## 2026-09-10 — v10.10.2 / Kernel v1.5 low-memory candidate

Current implementation contract: **27-LOW-MEMORY-INTELLIGENCE.md**. Status: **LOCAL-VALIDATED**; Windows/Docker/Ollama and product Live Gate remain pending. This entry supersedes earlier r6/v1.4 working-state and transport descriptions, without changing the North Star or frozen financial/approval contracts.

The candidate fixes explicit-model routing, uses grounded_selection_v3 (finding/action IDs only), removes unnecessary known-route planner calls, bounds local inference and endpoint failover, preserves per-supplier comparisons during degradation, and isolates bounded lexical RAG by workspace/company. Model qualification, installation integrity and ERP semantic acceptance are separate gates. See the new contract for settings, limitations, measured synthetic input reduction and file map.


> سند فشرده برای AI/Developer جدید. قبل از استفاده، `00-START-HERE.md` و Master Spec مرجع هستند.

## Product

ERPSMART یک **AI-native Business Operating System** برای Vertical اول trading/import/distribution B2B است.

```text
Architecture: ERPSMART Intelligence Platform
User-facing AI: ERPSMART Business Copilot
```

ERP/Domainها Source of Truth هستند. Business Copilot لایه همیشه‌حاضر برای Search/Analysis/Orchestration/Guarded Action/Proactive supervision است.

## Current baseline / phase — 2026-09-08

```text
Repository: AmirAbedini78/excel-dev
Branch: main
Baseline: 7415f1471573cce6e398317069567894a7209c4a
Frozen safety milestone: v9.3 Commercial MVP — LIVE-VALIDATED / FROZEN
Universal Copilot foundation: IMPLEMENTED / live-proven
Cycle 12.2 grounding/data/UI rescue: committed at 7415f14
Working milestone: v10.10.1 Cycle 12.3 — Agent Intelligence Kernel v1.4 resilient analyst recovery
Working status: IMPLEMENTED-CANDIDATE / LOCAL-VALIDATION
Immediate contract: docs/ai/26-AGENT-INTELLIGENCE-KERNEL.md
Cadence contract: docs/ai/20-UNIVERSAL-BUSINESS-COPILOT-48H-MVP.md
Master architecture: docs/ai/19-ERPSMART-INTELLIGENCE-PLATFORM-MASTER-SPEC.md
Next after semantic live pass: MVP C — Role Brief / Intelligent Home
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

Close Agent Intelligence Kernel v1 before any additional UI/dashboard feature:

- `supplier.performance`, `trade.risk`, `shipment.impact`;
- server-declared action menu; model selects only `action_id`;
- bounded Tool → Observation → re-plan loop;
- explicit @ entity type/scope correctness;
- decision-first final answer, no Evidence dump fallback;
- real planner semantic hard gate + analyst model-or-explicit-degraded capability preflight;
- semantic benchmark + full regression + live browser gate.

After this passes, immediately implement MVP C Intelligent Home / Experience Role. Do not reopen Cycle 12 as another cosmetic-polish loop.

Canonical implementation map: `docs/ai/26-AGENT-INTELLIGENCE-KERNEL.md`.


### Agent Kernel analyst transport v1.4 — resilient capability route

Live recovery proved a narrower fact than “the Kernel is broken”: the planner contract passes, while every currently configured local analyst can still return truncated/invalid structured JSON. Runtime keeps `compact_decision_v2`, and the smoke now uses the same complete-object extractor as production. If an analyst passes, that model route is persisted. If none passes, preflight persists an explicit `deterministic_decision_fallback` capability route bound to the exact `kernel_version`, `analyst_contract` and configured model list. While that tuple is unchanged, runtime skips repeated slow analyst failures and immediately renders the server-validated decision-first fallback; any model/configuration change automatically reopens analyst evaluation.

The planner remains a hard live capability gate. Analyst degradation is an explicit safe operating mode, **not** semantic live acceptance: Supplier/Trade/Shipment browser gates must still prove that the resulting decision is useful before MVP B closes.
