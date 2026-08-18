# نقشه محصول: پلتفرم حسابداری برای حسابداران + AI Agent

## تعریف محصول

مخاطب اولیه «حسابدار/مؤسسه حسابداری» است، نه فقط یک شرکت. بنابراین محصول دو هسته هم‌زمان دارد:

1. **Accounting Engine** برای دفاتر و عملیات مالی هر شرکت
2. **Accounting Practice OS** برای مدیریت چند مشتری/شرکت، کارها، deadlineها، اسناد و ارتباطات

در آینده Business modules دیگر به این Platform اضافه می‌شوند، اما فعلاً Domain اصلی Accounting است.

## وضعیت فعلی سورس قبل/بعد از این فاز

### قابلیت‌های موجود و قابل استفاده

- Workspace/Tenant
- Role/Permission
- چند شرکت
- Audit log
- API token/framework
- Task/Calendar/Kanban/Notes/Library/Phonebook
- اطلاعات پایه حسابداری
- کدینگ حساب‌ها
- اسناد حسابداری
- خرید
- خزانه و چک
- BOM/تولید پایه
- تنظیمات مالیاتی پایه
- کش و ابزار performance

### افزوده شده در این فاز

- فروش و ردیف‌های فاکتور
- Schema گردش انبار
- Schema دریافت/پرداخت تراکنشی
- Schema حقوق و دستمزد
- Schema دارایی ثابت
- Schema بستن دوره
- Schema ارسال/پیگیری مالیاتی
- Compliance Rule Pack versioning
- AI Control Plane
- Agent proposal/approval
- Worker pool
- Local Ollama worker
- RAG و synthetic dataset foundation

## ماژول‌های هدف حسابداری

### A. General Ledger / دفتر کل

- سال مالی و دوره
- کدینگ چندسطحی حساب‌ها
- تفصیلی شناور/اشخاص/پروژه/مرکز هزینه
- اسناد موقت، تأییدشده، قطعی
- شماره‌گذاری کنترل‌شده
- سند افتتاحیه/اختتامیه/تعدیلات
- قفل دوره
- دفتر کل/معین/تفصیلی
- تراز آزمایشی چندسطحی
- ترازنامه
- سود و زیان
- گردش حساب
- کنترل توازن و validation

### B. Accounts Receivable / Sales

- پیش‌فاکتور
- سفارش فروش (فاز بعد)
- فاکتور فروش کالا/خدمت
- برگشت از فروش
- تخفیف، مالیات، عوارض
- سررسید و شرایط پرداخت
- credit limit
- aging مطالبات
- وصول و تخصیص دریافت به فاکتور
- صدور سند حسابداری خودکار با mapping قابل تنظیم
- چرخه سامانه مؤدیان با adapter مستقل

### C. Accounts Payable / Purchase

- درخواست/سفارش خرید در صورت نیاز workflow
- فاکتور خرید
- برگشت از خرید
- هزینه‌ها و خدمات
- بدهی تأمین‌کننده
- aging بدهی
- تخصیص پرداخت
- ارتباط با انبار و اسناد حسابداری

### D. Inventory

- رسید انبار
- حواله
- انتقال بین انبارها
- برگشت‌ها
- تعدیل
- انبارگردانی
- کارت کالا
- موجودی لحظه‌ای
- حداقل/حداکثر موجودی
- سری/بچ/تاریخ انقضا در کسب‌وکارهای نیازمند
- روش ارزش‌گذاری قابل تنظیم
- رزرو موجودی و availability
- گردش کالا به منبع سند فروش/خرید/تولید

### E. Treasury / Cash & Banking

- صندوق و بانک
- دریافت/پرداخت
- تخصیص به اسناد
- چک دریافتی/پرداختی
- وضعیت چرخه چک
- سررسیدها و هشدار
- انتقال وجه
- مغایرت بانکی
- import صورت‌حساب بانکی
- Cash-flow dashboard

### F. Payroll

- پرسنل
- قرارداد/نوع همکاری
- مؤلفه‌های حقوق، مزایا، کسورات
- کارکرد/اضافه‌کار
- بیمه/مالیات به صورت Rule Pack versioned
- فیش حقوقی
- فایل‌ها/خروجی‌های قانونی موردنیاز
- سند حسابداری خودکار
- مرکز هزینه/پروژه

### G. Fixed Assets

- شناسنامه دارایی
- محل و تحویل‌گیرنده
- بهای تحصیل
- استهلاک
- افزایش/کاهش/انتقال
- فروش/اسقاط
- رویدادهای دارایی
- سند حسابداری استهلاک

### H. Production & Costing

- BOM/version
- سفارش تولید
- مصرف مواد
- محصول تولیدشده
- ضایعات
- سربار
- WIP
- بهای تمام‌شده
- تحلیل انحراف

### I. Compliance / Iran

این قسمت باید Adapter + Versioned Rules باشد و نباید قوانین سال خاص در source code پخش شوند.

- مشخصات مالیاتی شرکت
- شناسه کالا/خدمت و داده‌های صورتحساب
- Outbox/Retry/Status برای سامانه مؤدیان
- Rule Pack با effective date
- VAT/tax configuration
- خروجی‌ها و گزارش‌های قانونی بر اساس نسخه معتبر همان دوره
- Audit کامل درخواست/پاسخ سرویس‌های بیرونی

### J. Reporting & BI

- Trial balance
- General/Subsidiary ledger
- Balance sheet
- P&L
- Cash flow
- AR/AP aging
- Sales/Purchase trends
- Inventory valuation/card
- profitability by customer/item/project/cost center
- tax/compliance status dashboard
- accountant portfolio dashboard across clients

## Practice Management مخصوص حسابدار

این قسمت عامل تمایز محصول است:

- Client/Company portfolio
- Client onboarding checklist
- Engagement/work type
- recurring task templates
- deadline calendar
- assigned accountant/reviewer
- document request inbox
- client document vault
- communication log
- review/approval workflow
- time & effort tracking (اختیاری)
- billing of accounting services (فاز بعد)
- SLA/status dashboard
- capacity/workload
- exceptions requiring accountant attention

بخش‌هایی از این foundation در سورس فعلی با Companies/Tasks/Kanban/Library وجود دارد و باید در فاز محصول به مفهوم «Client Engagement» یکپارچه شود.

## AI Capability Map

### Copilot
- پرسش از داده‌های مالی
- توضیح مانده‌ها
- خلاصه وضعیت شرکت
- یافتن اسناد/طرف حساب/کالا

### Agent
- ساخت Draft فاکتور
- ساخت Draft سند
- بعداً Draft دریافت/پرداخت، Reminder، Email، document request
- هر mutation تحت policy/approval

### Suggestions
- سررسید چک
- مطالبات معوق
- اسناد ناقص
- رفتار تکراری حسابدار
- پیشنهاد عملیات دوره‌ای

### Predictive
- cash flow
- late-payment probability
- anomaly detection
- expected expenses/revenue
- workload/deadline risk

### Generative
- متن نامه/ایمیل
- گزارش مدیریتی
- خلاصه پرونده مشتری
- draft توضیحات سند
- استخراج داده از اسناد

## ترتیب توسعه پیشنهادی

### Phase 0 — Foundation (انجام‌شده/در حال انجام)
- معماری ماژولار
- Tenant/RBAC/Audit
- AI Control Plane + Worker
- safe tools
- Sales draft workflow

### Phase 1 — Accounting Core Completeness
- Sales کامل
- Inventory transactions
- Cash transactions + allocation
- Posting engine و auto-journal rules
- Period locking
- GL reports صحیح و سریع
- DB constraints/indexes

### Phase 2 — Accountant Practice OS
- Engagement
- recurring workflows
- deadlines
- document requests
- reviewer workflow
- portfolio dashboard

### Phase 3 — Iran Compliance
- Taxpayer adapter/outbox
- versioned rule packs
- statutory report layer
- legal rule update process

### Phase 4 — AI Agent Productionization
- tools for each module
- policy engine
- approval matrix
- message/conversation memory
- document RAG ingestion
- evaluation suite
- prompt-injection defenses

### Phase 5 — Analytics/ML
- feature store/light analytics tables
- forecasting
- anomaly detection
- collections prediction
- recommendation ranking

### Phase 6 — Scale
- queue service if needed
- analytics database/vector database
- Ray/vLLM/GPU workers
- object storage
- observability
- autoscaling

## شرط ورود هر ماژول به Agent

یک ماژول فقط وقتی Tool تغییردهنده می‌گیرد که:

1. Domain validation کامل باشد.
2. Tenant scope تست شده باشد.
3. mutation idempotent باشد.
4. Draft/Approval state داشته باشد.
5. Audit شود.
6. rollback/error path تعریف شده باشد.
7. integration test داشته باشد.

این شرط از اتوماسیون سریع اما خطرناک جلوگیری می‌کند.
