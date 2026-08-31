# 00-START-HERE — ERPSMART AI Development Entrypoint

> این فایل نقطه شروع اجباری هر Session توسعه است.

## ترتیب مطالعه

قبل از طراحی یا تغییر کد:

1. `00-GOVERNANCE.md`
2. `01-NORTH-STAR.md`
3. `02-CURRENT-STATE.md`
4. `03-ARCHITECTURE.md`
5. `04-ROADMAP.md`
6. Domain مرتبط از `02-domains/INDEX.yml`
7. در صورت عملیات/تحلیل AI: `05-WORKFLOW-CONTRACTS.md`
8. قبل از تحویل: `06-TEST-RELEASE-PROTOCOL.md`
9. برای Commercial safety baseline: `09-COMMERCIAL-MVP-RELEASE.md`
10. برای v10 Module Platform: `10-MODULAR-PILOT-PLATFORM.md`
11. برای Providerها: `11-MODEL-PROVIDER-GATEWAY.md`
12. برای Finance action depth: `12-FINANCE-CAPABILITY-MATRIX.md`
13. برای Golden Flow بازرگانی: `13-TRADE-FLOW-MVP.md`
14. برای Context Spike قبلی: `18-PAGE-AWARE-AI-CONTEXT-PICKER.md`
15. برای معماری محصول فعلی: `19-ERPSMART-INTELLIGENCE-PLATFORM-MASTER-SPEC.md`
16. برای اجرای کوتاه‌مدت: `20-UNIVERSAL-BUSINESS-COPILOT-48H-MVP.md`

برای انتقال سریع Context به AI/Developer جدید، بعد از موارد بالا:

```text
AI_DEVELOPMENT_HANDOFF_FA.md
```

## دستور استاندارد برای AI/Developer

قبل از کدنویسی باید صریحاً مشخص شود:

```text
Current baseline:
Current phase:
Requirement:
In scope:
Out of scope:
Affected contracts:
Risk:
Success criteria:
Files expected to change:
Tests required:
```

اگر Requirement کاربر با `01-NORTH-STAR.md` یا Scope فعلی تعارض داشت، بدون ساختن هدف جدید یا حدس زدن Roadmap، تعارض را اعلام کن و فقط پس از تصمیم صریح کاربر سند North Star/Roadmap را تغییر بده.

## قانون Source of Truth

ترتیب اولویت:

```text
Repository code + tests
        ↓
Live/runtime evidence
        ↓
docs/ai canonical docs
        ↓
legacy docs
        ↓
conversation history
```

History برای فهم علت تصمیم‌هاست، نه جایگزین وضعیت فعلی سورس.

## Current snapshot — 2026-08-31

- Repository: `AmirAbedini78/excel-dev`
- Current source baseline: `338e13419d091e6e1d3a5e7fd836ac7296e88e6b`.
- v9.3 final baseline remains `LIVE-VALIDATED / FROZEN`; all financial safety/recovery invariants remain binding.
- Latest fully closed business milestone: **v10.4 Cycle 7 — CRM-lite / Customer 360 — LIVE-VALIDATED**.
- v10.5 Cycle 8 r1 is `PARTIAL`: typed context/kernel transport is retained; the forced `Customer360 → dedicated AI page` product UX is `RETIRED` and its original Live Gate is no longer the acceptance target.
- Current milestone: **v10.6 Cycle 9 — Universal Business Copilot Foundation — PLANNED**.
- Product architecture: **ERPSMART Intelligence Platform**; user-facing intelligent layer: **ERPSMART Business Copilot**.
- First commercial wedge remains Finance/Trade for B2B trading/import/distribution companies; the new Copilot architecture connects the already-proven Finance, Inventory, Procurement, Trade/Logistics, Sales and CRM slices instead of abandoning them.
- Immediate implementation contract: `20-UNIVERSAL-BUSINESS-COPILOT-48H-MVP.md`.

## Frozen invariants

- current business facts come from deterministic Domain/Tool paths;
- LLM does not invent ERP IDs or receive direct operational SQL access;
- risky mutations remain Policy/Proposal/Approval guarded;
- Context/Memory/RAG never create authority;
- retry/idempotency/observability contracts from v9.3 remain preserved;
- Provider/model choice does not own business truth.
