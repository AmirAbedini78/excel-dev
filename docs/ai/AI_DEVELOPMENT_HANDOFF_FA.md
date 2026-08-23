# ERPSMART — AI Development Handoff

> سند فشرده برای AI/Developer جدید.
> قبل از استفاده، `00-START-HERE.md` مرجع اصلی ترتیب مطالعه است.

## Product

ERPSMART فعلاً یک **AI-native Accounting/Financial MVP** است.

هدف:
- سؤال از داده مالی
- گزارش و تحلیل
- forecast/risk/anomaly
- AI Agent برای اجرای عملیات مالی
- کاربر به‌تدریج Supervisor باشد نه data-entry operator

## Scope lock

فعلاً توسعه روی Accounting/Financial AI است.

DEFER:
- Notes/CRM/Phonebook AI
- Practice OS expansion
- Full accounting software completeness
- Builder
- advanced RAG stack
- multi-agent
- framework migration صرفاً برای مد روز

## Current baseline / phase

```text
v8.8 source baseline: cd13fae227f18229ee734958ea465b41885e78e2
Milestone: v8.8.0.4 Grounded Candidate-ID Accounting Workflow Planner
Status: LIVE-VALIDATED — Jobs #37/#38
Live Jobs #37/#38 completed the v8.8 gate: qwen3.5:0.8b directly selected grounded Candidate IDs, both plans reached `workflow_plan_validated` without planner fallback, Job #37 safely returned partial on empty ranking, and Job #38 executed `document_analytics → party_ledger` with a real Tool-derived party dependency. v8.8 is LIVE-VALIDATED; next operational step is exact 13-file commit/push, then v8.9.
Next after live validation: v8.9 Accounting Action Orchestrator
```

## Architecture

```text
cPanel:
UI/Auth/RBAC/MySQL/Queue/Tools/Approval/Audit

        ↑ outbound HTTPS

Docker Worker:
Python + Ollama
```

## Safety invariants

1. LLM direct SQL ندارد.
2. Current financial number از Tool deterministic می‌آید.
3. RAG ledger نیست.
4. LLM IDs نمی‌سازد.
5. Financial mutations Proposal/Approval دارند.
6. Retry نباید duplicate بسازد.
7. Tenant/company scope روی server validate می‌شود.
8. Deep analysis باید deterministic fallback داشته باشد.
9. Forecast number از LLM آزاد تولید نمی‌شود.
10. Cache فقط Plan؛ نه Answer مالی.

## Current proven paths

- deterministic report
- Safe Deep
- invoice proposal
- grounded reads
- parameterized analytics
- entity/status analytics
- multi-intent
- adaptive unknown-read planning

## Current implementation philosophy

v8.8 فقط Read multi-step است و Write را intercept نمی‌کند.

Example:

```text
Prompt
→ validated Plan
→ step dependencies
→ Tools
→ deterministic calculations
→ grounded response
```

v8.9 مرحله بعدی write/action orchestration با Proposal/Approval است.

## Development workflow

قبل از Edit:
- read canonical docs
- inspect exact Git files
- identify scope/out-of-scope
- candidate-first tests
- exact changed file set

بعد از Edit:
- build/lint/unit/integration
- live test
- docs update
- exact staging
- commit only when validated

## Never repeat these mistakes

- Tool schema بزرگ به مدل ضعیف بدون routing
- آزاد گذاشتن LLM برای حساب کردن/ساخت عدد
- تفسیر «خرید > فروش» به عنوان زیان
- generated ERP IDs
- جواب cached مالی
- patch روی patch بدون prevalidation
- تغییر roadmap صرفاً بر اساس آخرین ایده
