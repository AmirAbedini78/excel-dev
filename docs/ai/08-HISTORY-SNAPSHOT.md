# 08-HISTORY-SNAPSHOT — تاریخچه فشرده و ماندگار توسعه

این فایل History کامل گفتگو نیست؛ عصاره تصمیم‌ها، Failureها و Milestoneهایی است که برای ادامه توسعه لازم‌اند.

## Origin

Vision اولیه:
- یک Accounting Platform برای حسابدارها
- چندشرکتی
- AI-native
- چت با سیستم
- Agent برای اجرای کار
- پیشنهاد proactive
- تحلیل و پیش‌بینی
- Local-first برای کاهش هزینه
- امکان Scale به سرور/GPU

تصمیم اولیه درست: به جای Clone کامل سپیدار/هلو و سپس AI، Vertical Slice واقعی بسازیم.

## Foundation

Control Plane روی cPanel و Worker محلی شکل گرفت:

```text
cPanel UI/Auth/RBAC/MySQL/Queue/Tools/Approval/Audit
                     ↑ HTTPS
Docker Worker + Ollama
```

Safe Tool design، Lease، Idempotency، Approval و Audit از ابتدا ستون‌های معماری شدند.

## Early hardware lesson

Tool-heavy prompts روی CPU قدیمی بسیار کند شدند. A/B test نشان داد خود Ollama/Tool Calling کار می‌کند، اما prompt schema بزرگ هزینه زیادی دارد.

نتیجه:
- Dynamic/specialized routing
- deterministic fast financial path
- LLM فقط جایی که ارزش دارد

## v7.x

Fast financial analysis path و Docker worker تثبیت شدند. تجربه چند installer خطادار باعث شد روش توسعه به candidate-first validation تغییر کند.

## v8.0

Live/SSE observability و latency instrumentation اضافه شد تا «در حال پردازش» black box نباشد.

## v8.2 series — Deep analysis lessons

آزمایش‌های متعدد نشان دادند:
- LLM با عدد مالی می‌تواند رقم را تحریف کند.
- نام کلید مبهم می‌تواند معنای حسابداری را عوض کند.
- نبود داده نباید به ادعای risk تبدیل شود.
- forcing متن طولانی روی CPU ضعیف latency را شدید می‌کند.

خروجی معماری:
**Safe Deep Core** با deterministic facts، محدودیت داده، LLM enhancement محدود و fallback.

Milestone پایدار:
`v8.2C.4.2 Safe Deep Core`

## v8.3 — Guarded Invoice Agent

Failure واقعی Job #17 نشان داد مدل می‌تواند Tool chain را ناقص اجرا کند و حتی ID بسازد.

راه‌حل:
- invoice-specific guard
- search party
- search each item
- IDs فقط از Tools
- args deterministic
- proposal confirmation from server
- human approval

این Milestone اولین Action Agent واقعی و امن بود.

## v8.4 — Grounded Read Agent

Readهای عمومی حسابداری از پاسخ آزاد LLM جدا شدند و به Grounded Tools متصل شدند.

## v8.5 — Parameterized Query Engine

محدودیت readهای ثابت شکسته شد:
- period
- Jalali
- comparison
- group
- status
- multi-intent

Live reconciliation نشان داد مجموع گروه‌ها و دوره‌ها با total سازگار است.

## v8.6 — Semantic & Entity-Scoped Analytics

- `confirmed = approved + final`
- all/draft/approved/final
- party scope
- item scope
- party + item
- rolling months
- top customers/items

Live Jobs 27–30 مسیرهای واقعی را تأیید کردند.

## v8.7 — Adaptive Semantic Router

ایده optimization:
- بار اول unknown read → LLM Plan → validate → fresh Tools → store Plan
- بار بعد → Plan cache → fresh Tools
- Answer cache ممنوع

Job #31 adaptive MISS واقعی را ثابت کرد.

تصمیم مهم بعدی:
v8.7 FROZEN؛ Cache/Dictionary نباید هدف پروژه شود.

## Current correction of roadmap

از بحث‌های بعدی یک Scope correction مهم تثبیت شد:

> فعلاً نرم‌افزار حسابداری را کامل نمی‌کنیم و به Notes/other modules نمی‌رویم. Existing Accounting/Financial module بستر آزمون و ساخت AI MVP است. اول Workflowهای سؤال، تحلیل، prediction و agent را سالم می‌کنیم. بعد از MVP تجاری، Accounting Application کامل‌تر ساخته می‌شود.

## Next

`v8.8 — Accounting Constrained Workflow Planner`

سپس:
- Action Orchestrator
- Financial Intelligence
- Forecast/Risk/Anomaly
- Proactive Agent
- Commercial hardening
