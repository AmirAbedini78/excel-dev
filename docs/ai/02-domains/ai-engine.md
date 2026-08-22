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

## Future

v8.8 Planner باید روی همین Worker یا abstraction قابل تعویض سوار شود. Tool API نباید به LangGraph/Hermes وابسته شود.
