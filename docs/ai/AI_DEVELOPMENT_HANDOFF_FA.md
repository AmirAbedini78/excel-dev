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
Deployed source baseline: 2f196868c9f27c719cf0165fd541656a2e5f11d4
Baseline: v9.3.0.1 Live Observability Hotfix — Python/PHP/Node CI + cPanel passed
Latest live evidence: Job #50 read metadata PASS؛ Job #51 blocked safety/model-name PASS، Proposal zero، Tool names and attempted metrics absent from UI
Working milestone: v9.3.0.2 Safe Attempt Observability Hotfix
Working status: LOCAL-VALIDATED candidate; GitHub CI + cPanel/browser validation pending
Release contract: docs/ai/09-COMMERCIAL-MVP-RELEASE.md
Next: install exact v9.3.0.2 hotfix on `2f19686`, pass CI with PHP+Node, deploy cPanel, repeat blocked Job #51 and require safe Tool names plus attempted-model metrics in live/reload UI، then finish idempotency/replay/Approval/redaction gates. Worker rebuild is not required.
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

v9.3 Feature جدید نیست؛ هدفش freeze کردن رفتارهای اثبات‌شده با regression، recovery، security، observability و release gates است. v9.3.0.1 route/model/latency/risk parity را اصلاح کرد؛ v9.3.0.2 فقط Tool-name و attempted-metrics parity امن و bounded را کامل می‌کند.

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
