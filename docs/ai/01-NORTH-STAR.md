# 01-NORTH-STAR — هدف ثابت ERPSMART

## تعریف یک‌خطی

**ERPSMART در فاز فعلی باید یک MVP حسابداری/مالی AI-Native بسازد که حسابدار بتواند با زبان طبیعی از داده‌های واقعی سؤال کند، تحلیل و پیش‌بینی بگیرد و به یک AI Agent دستور عملیات مالی بدهد؛ به‌گونه‌ای که کاربر به‌تدریج از اپراتور نرم‌افزار به ناظر کیفی/Supervisor تبدیل شود.**

## Vision بلندمدت

در آینده Platform می‌تواند Businessهای بیشتر و یک Accounting Application کامل‌تر را پوشش دهد، اما این Vision **مجوز توسعه همه ماژول‌ها در حال حاضر نیست**.

## تصمیم مهم فعلی

فعلاً:

```text
نرم‌افزار حسابداری کامل شبیه سپیدار/هلو از صفر  ❌
هوشمندسازی همه ماژول‌های موجود                 ❌
توسعه Notes/CRM/Phonebook به خاطر AI           ❌

استفاده از Accounting/Financial Core موجود     ✅
اثبات Workflowهای هوشمند روی همین Domain       ✅
ساخت MVP تجاری AI-first                         ✅
```

بعد از آنکه مغز AI و Workflowها قابل اتکا و تجاری شدند، توسعه یک نرم‌افزار حسابداری کامل‌تر و اتصال همان AI Core به آن انجام می‌شود.

## چهار قابلیت تعریف‌کننده MVP

MVP وقتی معنی‌دار است که این چهار دسته را **واقعاً خوب** انجام دهد:

1. **Ask the Accounting System**
   - سؤال طبیعی از داده‌های حسابداری
   - Drill-down و Entity scope
   - بازه زمانی و وضعیت سند
   - پاسخ Grounded با داده تازه

2. **Financial Intelligence**
   - گزارش مدیریتی
   - مقایسه
   - Trend/KPI
   - تحلیل عمیق با محدودیت‌های داده
   - anomaly/risk signals

3. **Prediction**
   - Forecast عددی مستقل از LLM
   - confidence/error
   - cash-flow / sales / collections / anomaly در حد داده موجود
   - LLM فقط توضیح‌دهنده خروجی مدل عددی

4. **Accounting Agent**
   - فهم دستور
   - برنامه‌ریزی چندمرحله‌ای
   - resolve مشتری/کالا/حساب از Tool
   - ایجاد/ویرایش عملیات مجاز
   - Proposal/Approval/Risk policy
   - Verify + Audit + گزارش نتیجه

## تجربه کاربری هدف

کاربر در نهایت باید بتواند بنویسد:

```text
فروش سه ماه اخیر را بررسی کن،
مشتری‌هایی که خریدشان افت کرده پیدا کن،
مانده‌شان را بررسی کن،
مهم‌ترین‌ها را اولویت‌بندی کن،
و برای اقدامات مالی لازم Draft آماده کن.
```

Agent باید Task را به مراحل معتبر بشکند و Toolهای حسابداری را اجرا کند.

هدف نهایی Autonomy:

```text
امروز:
AI → Proposal → Human Approval → Execute

مرحله بعد:
Low-risk → Auto execute
High-risk → Approval

بلندمدت:
Agent executes normal workflows
Human reviews exceptions / high-risk decisions
```

## مرز هوش

```text
Structured financial facts → SQL/Tools
Documents/policies/laws    → RAG
Planning/interpretation    → LLM
Forecast numeric output    → ML/statistical models
Execution                  → deterministic domain services
Approval/policy            → server-side controls
```

## چیزهایی که North Star نیستند

این‌ها زیرسیستم‌اند، نه هدف محصول:

- Adaptive Plan Cache
- Regex/Dictionary routing
- یک مدل خاص مثل Qwen/Gemma
- Ollama
- LangGraph
- Hermes
- FastRAG/Qdrant
- Multi-node scheduling

هرکدام فقط وقتی ارزش دارند که مسیر چهار قابلیت اصلی MVP را بهتر کنند.
