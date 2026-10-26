# 00-GOVERNANCE — قوانین توسعه و نگهداری Context

## 2026-09-11 — v10.10.3 / Kernel v1.6: bounded stream recovery

Current package: `ERPSMART-v10.10.3-stream-recovery-local-validated.zip`, applied over the exact delivered v10.10.2 working state. Current implementation and incident evidence: **30-STREAM-RECOVERY-20260911.md**; install/rollback: **28-LOW-MEMORY-INSTALL-FA.md**; results: **29-LOW-MEMORY-TEST-REPORT-FA.md**.

The supplied Windows log proves v10.10.2 Docker build, 268 offline tests, file installation and Worker startup PASS. Its model gate is DEGRADED: qwen3.5:0.8b provider_error, qwen3:1.7b deadline, gemma3:4b memory_admission. This does not establish an OOM for the first model. A reproducible cross-thread HTTP chunk cancellation bug is fixed in v1.6; first-request allowance, shared endpoint cooldown and redacted per-case evidence are included. v1.6 remains LOCAL-VALIDATED until tested on that engine. MVP-B semantic acceptance remains open; MVP C Role Brief / Intelligent Home follows that acceptance. Older dated entries below describe historical states.

## هدف

این پوشه حافظه ماندگار توسعه ERPSMART است تا:

- هدف اصلی پروژه با هر گفت‌وگو تغییر نکند.
- AI جدید از روی آخرین جمله کاربر Roadmap تازه اختراع نکند.
- تصمیم‌های معماری، تست‌ها و Failureها گم نشوند.
- توسعه به مجموعه‌ای از Micro-patchهای فرسایشی تبدیل نشود.
- وضعیت «پیاده‌شده / تست‌شده / برنامه‌ریزی‌شده» با هم اشتباه نشود.

## اصل اول: North Star فقط با تصمیم صریح تغییر می‌کند

`01-NORTH-STAR.md` سند هویت محصول است.

یک ایده، پیشنهاد آزمایشی، Optimization یا بحث موقت نباید North Star را تغییر دهد.

برای تغییر North Star باید یکی از این‌ها وجود داشته باشد:

- کاربر صریحاً هدف محصول را تغییر دهد؛ یا
- یک تصمیم محصولی جدید پس از بررسی، صریحاً پذیرفته شود.

هر تغییر North Star باید همزمان در `07-DECISION-LOG.md` ثبت شود.

## اصل دوم: Scope v10 — Platform جامع، عمق مرحله‌ای

v9.3 Accounting/Financial AI به‌عنوان هسته اثبات‌شده `FROZEN` می‌ماند. از v10 Scope فعال یک **Modular AI-Native Business Operations Platform** است که Vertical اول آن Finance/Trade برای شرکت‌های بازرگانی B2B است.

جامع‌بودن یعنی Blueprint و Module contractهای Platform کامل باشند؛ نه اینکه همه ERP قبل از اولین مشتری به‌صورت سطحی پیاده شود.

## اصل سوم: Wide Platform / Deep Modules

هر Module فقط وقتی Pilot-ready است که Workflow واقعی خودش را از UI دستی تا Read/Report، Agent Action، Validation، Approval/Audit و در صورت نیاز Automation/Proactive behavior پوشش دهد.

روش v10:

```text
Module contract واقعی
        +
Workflow کسب‌وکار مشخص
        +
Safe Tool/Query
        +
AI understanding/planning
        +
Deterministic domain execution
        +
Approval برای Mutation پرریسک
        +
Runtime/Product evidence
```

ماژول غیرفعال نباید منو، route، AI Tool یا پردازش پس‌زمینه فعال داشته باشد. Module جدید فقط بر اساس Vertical و شواهد مشتری عمق می‌گیرد.

## اصل چهارم: منبع حقیقت مالی

- LLM منبع حقیقت عددی نیست.
- RAG منبع حقیقت عددی جاری نیست.
- Cache پاسخ منبع حقیقت نیست.
- SQL آزاد در اختیار مدل قرار نمی‌گیرد.

اعداد جاری باید از Query/Tool deterministic و company/workspace scoped بیایند.

## اصل پنجم: تغییرات مالی کنترل‌شده

مسیر پایه Mutation:

```text
Prompt
→ Resolve/Ground entities
→ Validate arguments
→ Proposal
→ Human review/approval
→ Deterministic domain execution
→ Verify
→ Audit
```

Autonomy بعداً بر اساس Risk Policy افزایش می‌یابد؛ Approval هرگز با Cache یا LLM bypass نمی‌شود.

## اصل ششم: یک بسته جامع، نه زنجیره Fixهای کوچک

برای Feature جدید:

1. Baseline دقیق بخوان.
2. Failureهای شناخته‌شده را جمع کن.
3. Candidate را خارج از Repo بساز.
4. قبل از Mutation lint/compile/unit/integration کن.
5. Backup خارجی بگیر.
6. فقط فایل‌های مورد انتظار را نصب کن.
7. دوباره build/test کن.
8. در خطا rollback کن.
9. بعد از Pass، Commit/Push/Deploy.

کاربر نباید به Debugger اصلی بسته‌های ناقص تبدیل شود.

## اصل هفتم: Git discipline

- هرگز `git add .` به عنوان روش پیش‌فرض استفاده نشود.
- فقط فایل‌های دقیق Task stage شوند.
- `engine/config.json` و Secretها stage نشوند.
- قبل از Commit: `git diff --check`.
- Baseline packageها به SHA دقیق قفل شوند.
- Commit/Push/Deploy فقط وقتی مرحله تست مربوط PASS شده باشد.

## اصل هشتم: Documentation update contract

هر Task موفق باید حداقل این فایل‌ها را بررسی کند:

- `02-CURRENT-STATE.md`
- `08-HISTORY-SNAPSHOT.md`
- Domain مربوط
- `04-docops/task_state.json`

در صورت تغییر معماری:
- `03-ARCHITECTURE.md`
- `07-DECISION-LOG.md`

در صورت تغییر Roadmap/Scope:
- `01-NORTH-STAR.md`
- `04-ROADMAP.md`
- `07-DECISION-LOG.md`

## Status vocabulary

فقط از این وضعیت‌ها استفاده شود:

```text
IMPLEMENTED
LOCAL-VALIDATED
LIVE-VALIDATED
PARTIAL
PLANNED
DEFERRED
FROZEN
RETIRED
FAILED-EXPERIMENT
```

عبارت «انجام شد» بدون مشخص کردن سطح Validation مجاز نیست.
