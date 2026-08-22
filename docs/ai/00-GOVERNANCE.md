# 00-GOVERNANCE — قوانین توسعه و نگهداری Context

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

## اصل دوم: Scope فعلی کوچک‌تر از Vision نهایی است

Vision بلندمدت می‌تواند بزرگ باشد، ولی **Scope فعلی MVP فقط حسابداری/مالی و هوشمندسازی آن است**.

وجود Notes، CRM، Phonebook، Calendar، Production یا سایر بخش‌ها در سورس به معنی مجاز بودن توسعه AI روی آن‌ها در فاز فعلی نیست.

## اصل سوم: Vertical Slice

برای اثبات AI لازم نیست ابتدا نرم‌افزار حسابداری کامل ساخته شود.

روش فعلی:

```text
Accounting primitive واقعی
        +
Workflow مشخص
        +
Safe Tool/Query
        +
AI understanding/planning
        +
Validation
        +
Approval برای Mutation
        +
Runtime evidence
```

اگر Workflow هوشمند نیازمند یک قابلیت حسابداری گمشده است، **فقط همان primitive لازم** تکمیل می‌شود؛ نه کل زیرسیستم حسابداری.

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
