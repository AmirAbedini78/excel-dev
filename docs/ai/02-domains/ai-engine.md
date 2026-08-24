---
id: ai_engine
status: active
touches_code:
  - engine/worker.py
  - engine/deep_safe.py
  - engine/agent_guard.py
  - engine/read_guard.py
  - engine/adaptive_router.py
  - engine/Dockerfile
  - engine/compose.yaml
  - ai_api.php
smoke_checks:
  - docker compose ps
  - worker registered
  - no startup traceback
  - read job completes
  - retry/lease behavior healthy
---

# AI Engine & Worker

## Role

Compute plane محلی و قابل انتقال به VPS/GPU.

## Current model routing

- small Qwen: parser/planner/agent
- Gemma: deep qualitative enhancement
- deterministic code: accounting math/facts

## Constraints learned from hardware

- prompt evaluation روی CPU قدیمی هزینه‌بر است.
- همه Tool schemaها نباید بی‌دلیل به مدل داده شوند.
- route before inference.
- keep evidence compact.
- LLM usage باید value-driven باشد.

## Frozen optimizations

Adaptive exact-route cache حفظ می‌شود ولی توسعه Template dictionary بزرگ فعلاً ممنوع است.

## Current v8.8 planner layer

Guard stack مفهومی:

```text
Safe Deep
→ Guarded Invoice Agent
→ Grounded Read
→ Adaptive Single-Plan Router
→ Accounting Constrained Workflow Planner
```

Workflow Planner فقط complex read-only requestهای وابسته را می‌گیرد. درخواست ساده، Deep و Write به مسیرهای قبلی delegate می‌شوند.

## Future

v8.9 همین قرارداد برنامه‌ریزی را به Action Orchestrator با Proposal/Approval متصل می‌کند. Tool API همچنان نباید به LangGraph/Hermes وابسته شود.

## v8.8.0.4 live planner result

Planner role is now a bounded Candidate-ID selector on `qwen3.5:0.8b`.

Live evidence:

```text
pre-mutation real Ollama: 6/6
post-rebuild real Ollama: 6/6
Job #37: direct LLM candidate selection → workflow_plan_validated (5 steps)
Job #38: direct LLM candidate selection → workflow_plan_validated (2 steps)
```

No planner fallback was used in Jobs #37/#38. Server-side grounding and compilation remain authoritative; the model does not emit Tool arguments or ERP IDs.

## v8.9.0 Accounting Action Orchestrator

The local model remains deliberately bounded.

Model responsibility:

```text
select one server-grounded action Goal ID
```

The model does not own:

```text
party_id
account_id
voucher_id
Tool names
voucher lines
approval decision
DB execution
```

The orchestrator parses the explicit user amount/account phrases, resolves entities through server Tools, evaluates the debtor condition deterministically, constructs a balanced Proposal, and then stops for human approval.

Live Jobs #41/#42 proved both fail-closed ambiguity and successful grounded Proposal creation. Human approval then exercised the existing Control Plane validator/executor. Jobs #43/#44 proved the resulting `draft` does not contaminate approved/final read facts.

Known observability cleanup: a post-LLM blocked path can currently show `model=none` in final UI metadata even though trace events record the real model. Safety/execution are unaffected.

## v9.0.1 Financial Intelligence execution contract

The Financial Intelligence model boundary is narrower than a general analyst:

```text
LLM input: grounded finding IDs + severity/category/title
LLM output: selected grounded finding IDs only
```

The server owns:
- tool plan
- financial periods
- amounts
- thresholds
- finding generation
- severity labels
- final report text
- critical/warning/info precedence

v9.0.1 adds the deterministic priority invariant:

```text
critical > warning > info
```

LLM preference is only advisory within the same severity tier. If priority selection fails, deterministic severity ordering still produces the report.

## v9.1.0 predictive execution contract

The server owns datasets, periods, forecasts, error ranges, risk/anomaly findings and severity.

The LLM only returns grounded finding IDs for prioritization.

```text
server facts/statistics
→ bounded Qwen ID priority
→ server severity gate: critical > warning > info
```

The v9.1 path is read-only. It does not call Proposal/write tools, and deterministic fallback remains available if the priority selector fails.
