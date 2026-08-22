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

## Current baseline

```text
Git: da02e416de1e7dccb4456e78e9b2c6f7cd3547be
Milestone: v8.7
Next: v8.8 Accounting Constrained Workflow Planner
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

## Next implementation philosophy

v8.8 فقط Read multi-step.

Example:

```text
Prompt
→ validated Plan
→ step dependencies
→ Tools
→ deterministic calculations
→ grounded response
```

بعد v8.9 write/action orchestration.

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
