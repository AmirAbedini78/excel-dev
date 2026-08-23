# 02-CURRENT-STATE — Snapshot زنده پروژه

> این فایل باید بعد از هر Milestone معتبر به‌روزرسانی شود.

## Baseline / Working Milestone

```text
Repository: AmirAbedini78/excel-dev
Branch: main
v8.8 source baseline: cd13fae227f18229ee734958ea465b41885e78e2
Baseline commit: Add canonical AI development smartdocs
Working milestone: v8.8.0.4 — Grounded Candidate-ID Accounting Workflow Planner
Validation state after installer: LOCAL-VALIDATED
Live validation: LIVE-VALIDATED — Jobs #37/#38
```

## Scope فعال

**Accounting/Financial AI MVP**

تمرکز روی تکمیل Workflowهای هوشمند است؛ نه توسعه تمام منوهای حسابداری یا سایر ماژول‌ها.

## Runtime topology

### cPanel / Control Plane

- UI
- Auth / RBAC
- Workspace/Company scope
- MySQL system of record
- AI Job Queue
- Worker registration/token
- Tool Gateway
- Proposal/Approval
- Audit
- SSE + Polling live status

### Local AI Engine

Docker Compose:

- `ollama`
- Python worker

Worker از داخل شبکه با HTTPS به Control Plane وصل می‌شود؛ inbound public API روی PC/Laptop لازم نیست.

## مدل‌های فعلی شناخته‌شده

```text
qwen3.5:0.8b → fast parser / agent planning
gemma3:4b    → deep qualitative enhancer
qwen3:1.7b   → fallback/legacy candidate
```

مدل دقیق یک Contract دائمی محصول نیست و قابل تعویض است.

## Capabilityهای پیاده‌شده و Validation شده

### Foundation
- Job queue / lease
- Heartbeat
- Retry/backoff
- Worker registration
- Token security
- idempotent tool-call design
- realtime trace/latency UI

### Financial read
- company snapshot
- recent sales/purchases
- trial balance
- party ledger
- party/item search
- parameterized sales/purchase analytics
- Jalali period resolution
- current/previous comparisons
- group by party/item/month/status
- semantic scope: all/confirmed/draft/approved/final
- entity-scoped analytics
- multi-intent deterministic reads

### Analysis
- deterministic financial report path
- Safe Deep core
- deep model cannot be trusted as numeric calculator
- factual accounting numbers remain deterministic
- fallback preserves safe report when local deep model fails

### Agent write
- guarded sales invoice proposal
- customer/entity grounding
- item grounding
- quantity/price/tax grounding
- server-side validation
- no LLM-generated ERP IDs
- Proposal → Human Approval → Draft invoice

### Adaptive routing
- exact normalized Prompt → validated read Plan cache
- cached answer is forbidden
- fresh Tools run on every hit
- route contract/versioning
- confidence/feedback lifecycle
- only read plans are learned
- status: FROZEN optimization

### Constrained accounting workflow planner — v8.8
- read-only multi-step workflow planning
- max 8 sequential validated steps
- document analytics + deterministic compare + party ledger dependency
- later steps can consume `party_id`/`item_id` only from earlier Tool results
- no IDs/SQL/financial values may originate from LLM plan
- old deterministic multi-read remains preferred when sufficient
- invalid planner output is rejected; canonical dependency-safe recovery exists for proven patterns
- planner model/metrics are exposed correctly in job metadata

## Live evidence milestones

- Standard deterministic financial report: sub-second class on test data.
- Deep financial path: locally viable but CPU-heavy; Safe Deep architecture retained.
- Guarded invoice flow: real Proposal path validated.
- v8.5 parameterized analytics: live totals/groupings reconciled.
- v8.6 entity/status-scoped reads: live customer/item/combined queries validated.
- v8.7 Job #31: adaptive cache MISS → Qwen plan → grounded `sales_total` → successful result.
- v8.8 package tests: core 19/19 + actual guard-stack integration 7/7 locally validated before repository mutation.
- v8.8 Job #32 live test exposed plan-shape rejection + empty current-month ranking.
- v8.8.0.1 Job #33 validated partial no-data semantics on real cPanel data.
- v8.8.0.1 Job #34 exposed invalid JSON and delegation to old `party_search`.
- v8.8.0.2 Jobs #35/#36 proved partial semantics and real `document_analytics → party_id → party_ledger` execution, but both still rejected the LLM plan.
- Direct Ollama diagnostics showed three distinct planner limits: thinking exhaustion, oversized tool-schema reproduction, and semantic drift when the 0.8B model was allowed to construct tool-step objects.
- Candidate-ID model selection then showed the operational trade-off: qwen3.5:0.8b passed both refined goal-selection cases in ~12–28s; qwen3:1.7b timed out on Case A; gemma3:4b passed but was ~57–171s. v8.8.0.4 therefore keeps 0.8B only as a grounded goal selector while the server owns dependencies and all tool arguments.
- v8.8.0.4 repeated real-Ollama preflight passed 6/6 before mutation and 6/6 after Worker rebuild.
- Live Job #37: direct Candidate-ID LLM plan validated into 5 steps with no `workflow_plan_rejected`/fallback; current confirmed Shahrivar sales were 0 vs Mordad 1,985,720,000 IRR, so ranking returned no rows and the dependent ledger was safely skipped. Route: `accounting_workflow_partial`; model time 7.7s.
- Live Job #38: direct Candidate-ID LLM plan validated into 2 steps (`document_analytics → party_ledger`) with no fallback. Top confirmed-sales customer for Mordad was کارخانه بهین بسته‌بندی at 518,100,000 IRR; the real Tool-derived party dependency produced current balance 727,100,000 IRR. Route: `accounting_workflow_read`; model time 5.2s.
- v8.8 Grounded Candidate-ID Accounting Workflow Planner is therefore `LIVE-VALIDATED` for the two canonical dependent accounting workflows.

### Accounting Action Orchestrator — v8.9.0

The first write/action vertical slice is now Live-proven:

- Job #41: ambiguous debit account phrase `بانک` correctly failed closed after real `trial_balance`, offering real choices `10101 بانک ملت - جاری` and `10102 بانک پاسارگاد - جاری`; no Proposal was created.
- Job #42: exact account code `10101` produced a grounded receipt Proposal after `search_parties → party_ledger → deterministic debtor condition → trial_balance`.
- Real grounded facts for Job #42: کارخانه بهین بسته‌بندی balance `727,100,000 IRR`; requested receipt `100,000,000 IRR`; debit `10101 بانک ملت - جاری`; credit `11001 حساب‌های دریافتنی تجاری`.
- Proposal #2 was generated with two balanced lines: `100,000,000` debit and `100,000,000` credit, using Tool-derived `account_id`/`party_id`.
- Human approval executed the existing server-side validator and created `AI-VCH-20260823-193339-D278` as `general / draft`, totals `100,000,000 / 100,000,000`.
- Job #43 proved approved/final party ledger facts were unaffected: balance remained `727,100,000 IRR`.
- Job #44 proved approved/final trial balance facts were unaffected: debit = credit = `17,821,580,000 IRR`, difference `0`.
- Therefore the first full `READ → CONDITION → PROPOSAL → HUMAN APPROVAL → DRAFT EXECUTION → VERIFY` workflow is `LIVE-VALIDATED`.

Known low-severity observability issue: when a v8.9 action is blocked after the LLM goal-selection step (Job #41), the final UI meta currently reports `model: none` even though the trace proves `qwen3.5:0.8b` ran. This does not affect financial grounding or safety and is deferred to a later observability cleanup.

## Known non-blocking issues

1. Adaptive exact-prompt cache is **FROZEN** as an optimization; no large dictionary/template project now.
2. Old root docs under `docs/*.md` contain an earlier broader roadmap and must not override this SmartDocs set.
3. Full accounting application completeness is intentionally deferred until AI MVP is proven.
4. v8.9.0 اکنون با Live Jobs #41–#44 و human approval `LIVE-VALIDATED` است؛ baseline Git نهایی v8.9 هنوز باید با Commit دقیق runtime + SmartDocs ثبت شود.
5. در Workflow وابسته، نبود داده برای رتبه‌بندی باید `partial` برگرداند و نتایج معتبر مراحل قبلی را دور نریزد.

## Not yet implemented as production capability

- General financial Action Orchestrator
- Risk-based auto-execution matrix
- production-grade financial KPI/trend layer
- real forecasting models
- anomaly/collections prediction
- learned proactive ranking
- production RAG corpus and retrieval evaluation
- full accounting application completeness
