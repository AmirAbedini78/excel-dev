# 10-MODULAR-PILOT-PLATFORM — v10 Pilot Platform Contract

Status: `IMPLEMENTED-IN-PROGRESS`

## Product decision — 2026-08-27

v9.3 ثابت کرد هسته AI مالی ERPSMART می‌تواند سؤال Grounded، تحلیل، Forecast/Risk، Proposal، Human Approval و Draft واقعی بسازد. از v10 هدف از «MVP فقط حسابداری» به یک **Modular AI-Native Business Operations Platform** تغییر می‌کند.

اصل محصول:

```text
Wide Platform
+ Deep Modules
+ Local-first AI
+ Cloud-provider fallback
+ Tool-grounded current data
+ Human-controlled financial mutation
```

ERPSMART قرار نیست پیش از اولین مشتری همه ERPهای بازار را کامل بازسازی کند. Blueprint جامع است، اما هر ماژول فقط وقتی Pilot-ready محسوب می‌شود که Workflow دستی + Read/Report + Agent Action + Automation/Proactive behavior لازم را واقعاً انجام دهد.

## Commercial wedge

Vertical اول:

**شرکت‌های بازرگانی / واردکننده / توزیع‌کننده B2B متوسط**

Painهای اولویت‌دار:
- مطالبات و وصول؛
- نقدینگی و چک؛
- فروش و افت مشتری؛
- خرید و تأمین‌کننده؛
- موجودی، کمبود و مازاد؛
- نوسان ارز/Lead time/هزینه حمل؛
- تصمیم‌گیری سریع در محیط پرنوسان.

نام کاری بسته اولین Pilot:

`Trade Resilience Pack`

## Module architecture

هر Module باید این Contractها را داشته باشد:

```text
Module ID / manifest
Dependencies
Workspace enable/disable
Routes + menu
Permissions
Schema/migrations
Assets only when enabled
API surface
AI read tools
AI proposal/action tools
RAG sources/policies
Events / automation triggers
Background jobs only when enabled
Settings
Tests / demo prompts
```

وقتی Module غیرفعال است، نباید منو/route/tool/background processing آن فعال باشد.

## Module blueprint

### Pilot/core
- Finance & Treasury
- AI / Agent
- Inventory
- Procurement
- CRM / Sales
- Trade & Logistics

### Planned platform modules
- Production
- Projects
- HR
- Service / After-sales
- Marketing / Social
- BI / Automation

## AI contract per module

یک Module کامل فقط فرم ندارد. حداقل باید این زنجیره را پوشش دهد:

```text
Manual UI
→ deterministic read/report
→ natural-language query
→ grounded tool execution
→ supported Agent action
→ Proposal/Approval when mutation is risky
→ verification/audit
→ proactive insight or automation where useful
```

Current ledger/transaction facts همیشه از Tool/Server می‌آیند؛ RAG جای Current Data را نمی‌گیرد؛ LLM برای فهم زبان، planning، ranking و explanation استفاده می‌شود.

## Two-day compressed milestone

### Thursday 2026-08-27

1. Module Kernel v1 + Module Center.
2. Update canonical SmartDocs to v10 scope.
3. Model Provider Gateway v1: Ollama primary + OpenAI-compatible provider path/fallback.
4. Finance capability matrix: همه فرم‌های عملیاتی موجود در Vertical مالی فهرست و Action gap مشخص شود.
5. Add the highest-value missing Finance Agent actions that reuse existing domain services.

### Friday 2026-08-28

1. Inventory minimal-complete slice.
2. Procurement minimal-complete slice.
3. CRM-lite / Customer 360 connected to Sales + AR.
4. Trade/Logistics data model + first intelligence slice.
5. Cross-module proactive manager brief.
6. CSV/Excel/API import readiness for Design Partner data.
7. Commercial demo dataset + prompt pack + 7-step live demo.

## Stop-development gate for first market pilot

Core feature work stops when the product can live-demo these outcomes on real/synthetic company data:

1. «امروز چه کسانی بیشترین طلب سررسیدشده دارند؟»
2. «کدام کالا تا دو هفته دیگر کم می‌آید؟»
3. «کدام تأمین‌کننده عملکردش بدتر شده؟»
4. «اگر نرخ ارز ۱۰٪ بالا برود کدام خرید/محموله پرریسک می‌شود؟»
5. «برای مشتری X پیش‌فاکتور بساز.»
6. «برای طلب مشتری Y پیشنهاد دریافت بساز.»
7. «برای کالا Z سفارش خرید پیشنهادی آماده کن.»
8. «پنج اقدام مهم امروز مدیر را بر اساس Finance/Sales/Inventory/Procurement اولویت‌بندی کن.»

بعد از این Gate، توسعه فقط از Design Partner / Customer evidence انجام می‌شود.

## Long-term benchmark/model vision

Toolها، RAG corpusها، workflow traces، evaluation prompt sets و outcome feedback هر Vertical باید به‌گونه‌ای نگهداری شوند که بعداً بتوانند پایه این دارایی‌ها شوند:

- domain benchmarks برای Finance/Trade/Inventory/CRM؛
- tool-use and workflow evaluation datasets؛
- Persian business reasoning benchmarks؛
- synthetic + anonymized training/evaluation corpora با مجوز مناسب؛
- در صورت توجیه داده/هزینه، مدل‌های تخصصی کوچک یا بزرگ‌تر برای هر Vertical.

این Vision مجوز جمع‌آوری یا استفاده از داده مشتری بدون قرارداد/رضایت نیست؛ در MVP فعلی هدف اصلی ساخت Product + evaluation assets است، نه آموزش مدل اختصاصی.
