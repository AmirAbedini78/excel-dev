# معماری هدف پلتفرم حسابداری AI-Native

## 1. تصمیم معماری

این محصول نباید «یک نرم‌افزار حسابداری + یک چت‌بات» باشد. ساختار هدف سه لایه مستقل دارد:

1. **Accounting & Practice Control Plane** روی هاست وب/cPanel
2. **AI/Analytics Engine Worker Pool** روی سیستم‌های محلی و بعداً سرور/GPU
3. **Knowledge & Data Plane** شامل دیتابیس عملیاتی، اسناد RAG، داده تحلیلی و Feedback

اصل مهم: دیتابیس عملیاتی و قوانین حسابداری منبع حقیقت هستند. مدل زبانی فقط برای فهم درخواست، برنامه‌ریزی، توضیح، استخراج اطلاعات و انتخاب ابزار استفاده می‌شود.

```text
حسابدار / مرورگر
       |
       v
+-----------------------------+
| cPanel Web App / Control    |
| Plane                       |
|-----------------------------|
| UI + Auth + RBAC            |
| Accounting Core             |
| Client/Practice Management  |
| AI Job Queue                |
| Tool Gateway                |
| Approval & Audit            |
| MySQL System of Record      |
+-------------+---------------+
              ^ HTTPS outbound polling
              |
      +-------+--------+----------------+
      |                |                |
      v                v                v
+-----------+     +-----------+     +-----------+
| Worker A  |     | Worker B  |     | Future    |
| Desktop   |     | Laptop    |     | GPU/Cloud |
| Ollama    |     | Ollama    |     | Ray/vLLM  |
| RAG       |     | Analytics |     | Models    |
+-----------+     +-----------+     +-----------+
```

## 2. چرا Workerها از داخل شبکه به cPanel وصل می‌شوند؟

در فاز فعلی، سیستم خانه نباید API عمومی روی اینترنت باز کند. Worker با HTTPS به `ai_api.php` وصل می‌شود، خود را Register می‌کند، Job می‌گیرد، Tool می‌خواند و نتیجه برمی‌گرداند. مزایا:

- بدون Port Forwarding و Public IP
- کاهش سطح حمله
- تحمل قطع شدن یک سیستم
- امکان افزودن سیستم سوم بدون تغییر معماری وب
- Jobهای انجام‌نشده در Queue باقی می‌مانند
- امکان مهاجرت Worker به VPS/GPU در آینده بدون تغییر Accounting Core

## 3. نقش هر قسمت

### Control Plane روی cPanel

- Login، Session، RBAC و Tenant isolation
- ثبت نهایی داده مالی
- شماره‌گذاری و قواعد اسناد
- API ابزارهای ایجنت
- صف Job
- Worker token و Node registry
- Approval queue
- Audit log
- وضعیت پیشنهادهای هوشمند
- API برای سرویس‌های آینده

### Worker Engine

- Ollama و مدل محلی
- Tool calling
- RAG محلی
- استخراج و خلاصه‌سازی اسناد
- طبقه‌بندی و پیشنهاد
- تحلیل‌های سنگین
- Forecasting و anomaly detection در فازهای بعدی

### آینده: Compute Fabric

وقتی سخت‌افزار بهتر شد، Worker ساده فعلی می‌تواند کنار یک Scheduler پیشرفته قرار گیرد. برای GPU/Cluster می‌توان Ray/vLLM یا سرویس‌های مشابه را اضافه کرد. قرارداد API سمت Accounting Core تغییر نمی‌کند.

## 4. اصل حیاتی Agent: مدل مستقیم SQL نمی‌زند

مدل هیچ‌وقت username/password دیتابیس، SQL آزاد یا دسترسی فایل سرور نمی‌گیرد. همه عملیات از Tool Registry عبور می‌کنند.

نمونه Toolهای خواندنی فعلی:

- `company_snapshot`
- `search_parties`
- `search_items`
- `trial_balance`
- `party_ledger`
- `recent_sales`
- `recent_purchases`

نمونه Toolهای تغییردهنده فعلی:

- `create_sales_invoice_draft`
- `create_voucher_draft`

Tool تغییردهنده مستقیماً سند قطعی نمی‌سازد. ابتدا Proposal ثبت می‌شود.

```text
User Prompt
   |
   v
LLM -> read tools -> plan
   |
   v
Mutation Tool Request
   |
   v
Proposal (NOT posted)
   |
   v
Human Review / Approval
   |
   v
Deterministic Accounting Service
   |
   v
Draft accounting entity
   |
   v
Normal workflow -> Final/Post
```

## 5. چهار قفل ایمنی

### Lease
هر Job برای مدت محدود به یک Worker اجاره داده می‌شود. Worker دیگر همان Job را هم‌زمان نمی‌گیرد.

### Idempotency
Tool Call شناسه یکتا دارد. Retry شبکه نباید فاکتور یا سند تکراری بسازد.

### Approval
عملیات مالی حساس Proposal هستند و قبل از اجرا نیازمند مجوز انسانی‌اند.

### Audit
اجرای عملیات AI و کاربر ثبت می‌شود تا مشخص باشد چه کسی، چه زمانی، با چه Tool و چه نتیجه‌ای عمل کرده است.

## 6. RAG کجا استفاده می‌شود و کجا نه؟

### مناسب RAG

- قوانین و بخشنامه‌های مالیاتی
- راهنمای سامانه مؤدیان
- رویه‌های داخلی شرکت
- قراردادها
- دستورالعمل‌های حسابداری
- پرونده و مکاتبات مشتری
- سیاست تنخواه/خرید/تخفیف
- شرح حساب‌ها و مستندات داخلی

### نامناسب برای RAG به عنوان منبع حقیقت عددی

- مانده حساب
- جمع فروش
- گردش شخص
- موجودی قطعی
- مبلغ فاکتور
- وضعیت چک

این داده‌ها باید با Query/Tool قطعی و scope شده از دیتابیس خوانده شوند. RAG تنها context کمکی است.

## 7. معماری داده

### OLTP / System of Record
MySQL روی cPanel برای تراکنش‌های عملیاتی، اسناد و وضعیت workflow.

### Knowledge Index
در فاز کم‌هزینه: SQLite + embedding محلی روی Worker. در مقیاس بالاتر: Qdrant/pgvector/FAISS یا سرویس vector مستقل.

### Analytics Store
در فاز اول لازم نیست Big Data stack وارد Production شود. برای آموزش/آزمایش می‌توان dataset مصنوعی را خارج از MySQL عملیاتی نگه داشت. در مقیاس بزرگ‌تر، یک Analytics DB/Data Lake جدا اضافه می‌شود.

### Feedback Store
قبول/رد پیشنهاد، اصلاح کاربر، امتیاز و outcome باید ذخیره شود. این داده بعداً برای ranking، recommendation و مدل‌های اختصاصی ارزشمندتر از داده مصنوعی است.

## 8. مدل چندنودی

نسخه فعلی Nodeها را با مشخصات CPU، RAM، OS، مدل‌ها و capability ثبت می‌کند. Job بر اساس capability قابل دریافت است. طراحی مطلوب مرحله بعد:

- `llm-small`
- `embedding`
- `ocr`
- `analytics`
- `forecast`
- `batch-generator`
- `gpu-llm`

Job باید Resource Hint هم داشته باشد (RAM حداقل، مدل ترجیحی، کلاس latency). Scheduler سپس فقط Worker مناسب را انتخاب می‌کند.

در سخت‌افزار فعلی بهتر است **Job-level parallelism** داشته باشیم، نه اینکه یک inference را بین دو CPU قدیمی shard کنیم. مثلاً Desktop یک RAG indexing job را بگیرد و Laptop یک Agent chat را. وقتی GPU/سرور اضافه شد، مدل inference می‌تواند روی سرویس تخصصی منتقل شود.

## 9. Local-first + Cloud fallback

Provider abstraction هدف:

1. Local Ollama به عنوان مسیر پیش‌فرض کم‌هزینه
2. Providerهای OpenAI-compatible به عنوان fallback/premium
3. مدل‌های تخصصی جدا برای embedding، extraction، reasoning و coding
4. Policy router برای انتخاب مدل بر اساس حساسیت/هزینه/latency

اطلاعات محرمانه شرکت نباید بدون Policy و Consent به provider خارجی ارسال شود.

## 10. Proactive AI

Proactive suggestion را از مدل بزرگ شروع نمی‌کنیم. ترتیب درست:

### سطح 1: Rule-based
- چک نزدیک سررسید
- سند/فاکتور Draft قدیمی
- موعدهای کاری
- مغایرت‌های واضح

### سطح 2: Behavior mining
- کاربر معمولاً در ساعت/روز خاص چه کاری انجام می‌دهد؟
- چه sequenceهایی تکرار می‌شوند؟
- چه مشتری‌هایی بعد از رویداد مشخص نیازمند پیگیری‌اند؟

### سطح 3: Learned ranking
Feedback واقعی تعیین می‌کند کدام پیشنهاد برای کدام حسابدار ارزشمند است.

### سطح 4: Pre-computation
ایجنت Draft پیشنهادی را پیشاپیش آماده می‌کند ولی Final/Post پس از بازبینی انسانی انجام می‌شود.

## 11. Forecasting و ML

LLM ابزار Forecasting مالی نیست. برای پیش‌بینی از مدل‌های عددی مستقل استفاده می‌کنیم:

- Cash-flow forecasting
- وصول مطالبات / احتمال تأخیر پرداخت
- anomaly detection
- فروش و خرید دوره‌ای
- نیاز نقدینگی
- موجودی و reorder suggestion
- هزینه و حاشیه سود

LLM خروجی مدل‌های عددی را توضیح می‌دهد و به Workflow وصل می‌کند.

## 12. امنیت

- TLS اجباری
- Worker token فقط یک‌بار نمایش داده شود؛ روی سرور hash ذخیره شود
- Token قابلیت محدود داشته باشد
- Rate limit برای API Worker و User API
- Idempotency برای mutation
- عدم اجرای shell/SQL دلخواه از prompt
- Validation مالکیت Company/Workspace روی هر Tool
- Approval برای mutation مالی
- Secret rotation/revoke
- Prompt-injection isolation برای RAG documents
- لاگ بدون افشای password/token
- Backup و restore test
- Rule-based maximum amounts / segregation of duties در فاز بعدی

## 13. وضعیت پیاده‌سازی فعلی این Branch

- AI schema و Worker registry
- Queue و lease
- Worker token
- Tool Registry امن
- Proposal/Approval/Audit
- Agent UI
- Ollama worker
- RAG سبک local
- Synthetic data generator
- Proactive suggestion v1
- Sales invoices + AI-created draft flow
- Schema پایه برای inventory, payroll, fixed assets, period close, tax submission
- Versionable compliance rule pack schema

این نسخه **هسته معماری** است، نه ادعای تکمیل همه زیرماژول‌های حسابداری.
