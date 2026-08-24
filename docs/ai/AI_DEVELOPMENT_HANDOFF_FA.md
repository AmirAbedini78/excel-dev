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
Frozen source baseline: a9a8c0259f4e7eaca248f9d9a912817fd1e23c92
Baseline: v9.2.0 Proactive Accounting Agent — LIVE-VALIDATED, Job #48
Working milestone: v9.3.0 Commercial MVP Hardening
Working status: LOCAL-VALIDATED; GitHub CI + cPanel + rebuilt Worker live validation pending
Release contract: docs/ai/09-COMMERCIAL-MVP-RELEASE.md
Next: install exact v9.3 candidate, pass CI/PHP lint, deploy cPanel, rebuild Worker, execute live checklist, then freeze Commercial MVP.
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
- constrained multi-step accounting workflows
- conditional receipt Proposal + Human Approval + Draft verification
- financial intelligence
- deterministic forecast/risk/anomaly
- proactive next-best-action recommendation
- commercial runtime/recovery/release guard (local candidate)

## Current implementation philosophy

Planner read multi-step است و Write را intercept نمی‌کند؛ write فقط از Guarded Proposal routes عبور می‌کند.

Example:

```text
Prompt
→ validated Plan
→ step dependencies
→ Tools
→ deterministic calculations
→ grounded response
```

v9.3 Feature جدید نیست؛ هدفش freeze کردن رفتارهای اثبات‌شده با regression، recovery، security، observability و release gates است.

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
