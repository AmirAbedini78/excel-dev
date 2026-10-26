# 00-START-HERE — ERPSMART AI Development Entrypoint

### تکمیل و بسته تحویل — ۲۰۲۶/۰۹/۱۰

بسته فعلی `ERPSMART-v10.10.2-low-memory-intelligence-local-validated.zip` است. راهنمای نصب/بازگشت در `28-LOW-MEMORY-INSTALL-FA.md` و گزارش تست/محدودیت‌ها در `29-LOW-MEMORY-TEST-REPORT-FA.md` قرار دارند. نتیجه محلی: ۲۶۸ تست پروژه و ۹ تست تراکنش بسته موفق؛ Windows/Docker/Ollama واقعی، PHP/CI و پذیرش محصول در این نوبت هنوز تأیید نشده‌اند. ابتدا نصب موتور و سه خوانش محصول؛ پس از پذیرش معنایی، ادامه MVP C — Role Brief / Intelligent Home. حالت DEGRADED موفقیت مدل محسوب نمی‌شود.


## 2026-09-10 — v10.10.2 / Kernel v1.5 low-memory candidate

Current implementation contract: **27-LOW-MEMORY-INTELLIGENCE.md**. Status: **LOCAL-VALIDATED**; Windows/Docker/Ollama and product Live Gate remain pending. This entry supersedes earlier r6/v1.4 working-state and transport descriptions, without changing the North Star or frozen financial/approval contracts.

The candidate fixes explicit-model routing, uses grounded_selection_v3 (finding/action IDs only), removes unnecessary known-route planner calls, bounds local inference and endpoint failover, preserves per-supplier comparisons during degradation, and isolates bounded lexical RAG by workspace/company. Model qualification, installation integrity and ERP semantic acceptance are separate gates. See the new contract for settings, limitations, measured synthetic input reduction and file map.


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
17. برای سابقه هسته Agent: `26-AGENT-INTELLIGENCE-KERNEL.md`
18. قرارداد فعلی کم‌حافظه: `27-LOW-MEMORY-INTELLIGENCE.md`
19. نصب و بازگشت نسخه فعلی: `28-LOW-MEMORY-INSTALL-FA.md`
20. نتایج و محدودیت‌ها: `29-LOW-MEMORY-TEST-REPORT-FA.md`

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

## Historical snapshot — 2026-09-08 (superseded by the v1.5 entry above)

- Repository: `AmirAbedini78/excel-dev`
- Current committed source baseline: `7415f1471573cce6e398317069567894a7209c4a`.
- v9.3 Commercial safety/recovery baseline remains `LIVE-VALIDATED / FROZEN`.
- MVP A Universal Copilot foundation and Cycle 11 workspace conversation/context are implemented and live-proven.
- Cycle 12/12.1/12.2 implemented Skills, capability retrieval, grounded evidence and bounded Supervisor, but browser Live Gate showed that the fixed report → Evidence Pack → LLM pattern is **not sufficient as the final intelligence architecture**.
- Current working milestone: **v10.10.1 Cycle 12.3 — Agent Intelligence Kernel v1.4 — RESILIENT-ANALYST RECOVERY / LOCAL-VALIDATION**.
- Agent Intelligence Kernel is a blocking correction inside MVP B, not a North Star change. Planner capability remains a hard live gate; analyst may run in an explicit deterministic-fallback capability mode when the exact configured local model set fails the structured contract. After semantic live acceptance, roadmap continues immediately to **MVP C — Role Brief / Intelligent Home**.
- Product architecture remains **ERPSMART Intelligence Platform** with **ERPSMART Business Copilot** as the pervasive user-facing layer.
- Immediate implementation contract: `26-AGENT-INTELLIGENCE-KERNEL.md`; cadence remains governed by `20-UNIVERSAL-BUSINESS-COPILOT-48H-MVP.md`.

## Frozen invariants

- current business facts come from deterministic Domain/Tool paths;
- LLM does not invent ERP IDs or receive direct operational SQL access;
- risky mutations remain Policy/Proposal/Approval guarded;
- Context/Memory/RAG never create authority;
- retry/idempotency/observability contracts from v9.3 remain preserved;
- Provider/model choice does not own business truth.
