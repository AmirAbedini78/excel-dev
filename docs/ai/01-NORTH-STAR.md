# 01-NORTH-STAR — هدف ثابت ERPSMART

## تعریف یک‌خطی

**ERPSMART یک Modular AI-Native Business Operations Platform است که ماژول‌های عملیاتی کسب‌وکار را با Agent، تحلیل، پیش‌بینی و اتوماسیون به هم متصل می‌کند؛ Vertical اول آن Finance/Trade برای شرکت‌های بازرگانی B2B است و هدف این است که کاربر از Data-entry Operator به Supervisor تصمیم و استثنا تبدیل شود.**

## Vision بلندمدت

Platform از نظر معماری می‌تواند Finance، Sales/CRM، Inventory، Procurement، Trade/Logistics، Production، HR، Project، Service و Marketing را پوشش دهد. عمق هر Module بر اساس Vertical و شواهد بازار تکمیل می‌شود؛ ERP جامع سطحی قبل از مشتری هدف نیست.

در بلندمدت Toolها، workflow traces، RAG corpusها و evaluation setهای هر Vertical می‌توانند پایه benchmarkها و مدل‌های تخصصی کسب‌وکار شوند، مشروط به مجوز و کیفیت داده.

## تصمیم مهم فعلی — v10

```text
بازنویسی کامل سپیدار/راهکاران/CRM قبل از بازار       ❌
ساخت ده‌ها منوی نمایشی و نیمه‌کاره                    ❌
Rewrite کامل Frontend صرفاً برای مد روز               ❌

Module Kernel واقعی + Enable/Disable/Dependencies      ✅
Finance/Trade Vertical عمیق و Actionable               ✅
Inventory + Procurement + CRM-lite متصل                ✅
Local-first AI + Cloud Provider Gateway                ✅
Design Partner Pilot و توسعه بعدی از شواهد مشتری       ✅
```

v9.3 هسته مالی اثبات‌شده را Freeze می‌کند؛ v10 آن را به Platform ماژولار قابل Pilot تبدیل می‌کند.

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
