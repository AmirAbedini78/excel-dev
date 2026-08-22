# 04-ROADMAP — Roadmap Canonical فعلی

> این Roadmap بر «AI-first Accounting MVP» قفل شده است.  
> Full Accounting Product expansion بعد از اثبات MVP انجام می‌شود.

## Completed foundation

### Foundation / Phase 0
Status: `LIVE-VALIDATED`

- cPanel Control Plane
- local Docker Worker
- Ollama
- queue/lease/heartbeat
- Tool Gateway
- Proposal/Approval/Audit
- realtime status
- synthetic accounting demo data

### v7.x — Fast financial path
Status: `LIVE-VALIDATED`

هدف: اثبات جداسازی read/report path از tool-heavy LLM path روی CPU ضعیف.

### v8.0 — Realtime observability
Status: `LIVE-VALIDATED`

- SSE/Polling
- trace
- latency metrics
- model progress

### v8.2C.4.2 — Safe Deep Core
Status: `LIVE-VALIDATED`

- deterministic facts
- safe qualitative enhancement
- timeout/fallback
- جلوگیری از جعل عدد/نتیجه مالی

### v8.3 — Guarded Invoice Agent
Status: `LIVE-VALIDATED`

- natural-language invoice intent
- entity/item grounding
- no generated IDs
- Proposal/Approval

### v8.4 — Grounded Read Agent
Status: `LIVE-VALIDATED`

- grounded general accounting reads
- deterministic answers

### v8.5 — Parameterized Query Engine
Status: `LIVE-VALIDATED`

- period
- status
- grouping
- comparison
- multi-intent

### v8.6 — Semantic & Entity-Scoped Analytics
Status: `LIVE-VALIDATED`

- customer scope
- item scope
- combined customer+item
- confirmed/draft/final semantics
- rolling periods

### v8.7 — Adaptive Semantic Router
Status: `IMPLEMENTED / FROZEN`

- read-plan cache only
- fresh financial queries on every run
- exact normalized route v1
- optimization, not a product direction

## Next core phases

### v8.8 — Accounting Constrained Workflow Planner
Status: `NEXT`

هدف: یک Prompt مالی چندمرحله‌ای را به Plan قابل اعتبارسنجی تبدیل کند.

Scope:
- Read-only first
- dependencies between steps
- step outputs referenced by later steps
- server IDs only from Tool results
- deterministic calculations between results
- optional LLM summary
- planner observability
- fix adaptive planner model display bug

مثال:

```text
فروش قطعی این ماه را با ماه قبل مقایسه کن،
مشتری برتر را پیدا کن،
مانده همان مشتری را هم بررسی کن.
```

Plan:

```text
1 document_analytics current confirmed
2 document_analytics previous confirmed
3 compare step1/step2
4 document_analytics current confirmed group_by party limit 1
5 party_ledger party_from step4
6 grounded summary
```

Success gate:
- multi-step correctness
- no invented IDs/numbers
- no direct SQL
- deterministic dependency resolution
- timeout/retry safety
- existing v8.3–v8.7 regressions pass

### v8.9 — Accounting Action Orchestrator
Status: `PLANNED`

Planner به عملیات مالی Proposal-based وصل می‌شود.

- multi-step action plans
- resolve accounts/parties/items
- invoice/voucher flows
- prerequisites
- proposal chain
- resume after approval
- post-action verification
- audit trail
- risk classification

در صورت نیاز فقط Accounting primitive لازم ساخته می‌شود؛ نه کل زیرسیستم.

### v9.0 — Financial Intelligence Core
Status: `PLANNED`

- consistent KPI definitions
- time-series extraction
- AR/AP style signals در حد داده موجود
- trend comparison
- ranking
- drill-down
- anomaly candidates
- explainable managerial report

LLM calculator نیست؛ metric engine deterministic است.

### v9.1 — Forecast / Risk / Anomaly
Status: `PLANNED`

- forecast datasets
- backtesting
- baseline statistical models
- error/confidence
- anomaly detection
- late-payment/collection model در صورت داده کافی
- LLM explanation

Synthetic data برای pipeline/load/testing؛ ادعای accuracy فقط با real validation.

### v9.2 — Proactive Accounting Agent
Status: `PLANNED`

- rule-based suggestions
- behavior mining
- next-best-action ranking
- precomputed drafts
- user feedback/outcome learning

### v9.3 — Commercial MVP Hardening
Status: `PLANNED`

- end-to-end evaluation suite
- permission/risk matrix
- idempotency/rollback tests
- concurrency
- performance/latency budgets
- observability
- backup/restore
- security review
- UX polish
- failure recovery
- documentation freeze/release checklist

## Post-MVP — فقط بعد از تصمیم جداگانه

- ساخت Accounting Application کامل‌تر
- تکمیل کامل GL/AP/AR/Inventory/Treasury/Payroll/Assets/Tax/Production
- Practice OS expansion
- AI روی ماژول‌های غیرمالی
- Builder/general ERP capabilities
- cloud/GPU scaling
- advanced RAG stack
- multi-agent

این بخش‌ها Vision هستند، نه Scope فعلی.
