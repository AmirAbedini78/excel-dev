# 02-CURRENT-STATE — Snapshot زنده پروژه

> این فایل باید بعد از هر Milestone معتبر به‌روزرسانی شود.

## Baseline

```text
Repository: AmirAbedini78/excel-dev
Branch: main
Snapshot SHA: da02e416de1e7dccb4456e78e9b2c6f7cd3547be
Commit: Add adaptive semantic ERP plan router
Milestone: v8.7
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

## Live evidence milestones

- Standard deterministic financial report: sub-second class on test data.
- Deep financial path: locally viable but CPU-heavy; Safe Deep architecture retained.
- Guarded invoice flow: real Proposal path validated.
- v8.5 parameterized analytics: live totals/groupings reconciled.
- v8.6 entity/status-scoped reads: live customer/item/combined queries validated.
- v8.7 Job #31: adaptive cache MISS → Qwen plan → grounded `sales_total` → successful result.

## Known non-blocking issues

1. UI may display `model=none` for `adaptive_llm_read` even when Qwen Planner ran; planner model is stored separately. Fix with next observability-touching phase.
2. Adaptive exact-prompt cache is **FROZEN** as an optimization; no large dictionary/template project now.
3. Old root docs under `docs/*.md` contain an earlier broader roadmap and must not override this SmartDocs set.
4. Full accounting application completeness is intentionally deferred until AI MVP is proven.

## Not yet implemented as production capability

- General multi-step constrained Planner
- General financial Action Orchestrator
- Risk-based auto-execution matrix
- production-grade financial KPI/trend layer
- real forecasting models
- anomaly/collections prediction
- learned proactive ranking
- production RAG corpus and retrieval evaluation
- full accounting application completeness
