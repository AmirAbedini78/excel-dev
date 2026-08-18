# Deploy فاز اول AI + Accounting V7 روی excel2.bcsrp.ir

## قبل از Deploy

1. از دیتابیس `excel2` در cPanel/phpMyAdmin خروجی SQL بگیرید.
2. از `app/config.php` روی سرور نسخه پشتیبان داشته باشید.
3. مطمئن شوید `.cpanel.yml` این Branch به مسیر نسخه دوم اشاره می‌کند:

```text
/home3/zzflgmfd/excel2.bcsrp/
```

4. این Branch را ابتدا فقط روی نسخه Dev (`excel2`) Deploy کنید، نه نسخه اصلی.

## Deploy در cPanel

```text
Git Version Control
→ excel-dev
→ Manage
→ Pull or Deploy
→ Update from Remote
→ Deploy HEAD Commit
```

`.cpanel.yml` فایل `ai_api.php` را نیز Deploy می‌کند، اما پوشه `engine/` عمداً روی cPanel Deploy نمی‌شود.

## Migration

نسخه Runtime schema به `7.0.0` ارتقا یافته است. اولین Request بعد از Deploy، schema gate را اجرا می‌کند و جدول‌های جدید را با `CREATE TABLE IF NOT EXISTS` ایجاد می‌کند.

برای مرحله اول بهتر است ابتدا صفحه اصلی را باز کنید و بعد صفحات زیر را بررسی کنید:

```text
حسابداری و مالی
دستیار هوشمند
```

## Smoke Test وب

- Login موفق
- انتخاب Company
- باز شدن بخش فروش
- ساخت یک فاکتور Draft دستی
- باز شدن صفحه دستیار هوشمند
- ساخت Worker Token
- عدم نمایش مجدد متن کامل Token پس از refresh
- امکان لغو Worker Token

## اتصال Worker اول

روی PC:

```powershell
cd engine
Copy-Item config.example.json config.json
```

Token ساخته‌شده در پنل را در `config.json` قرار دهید.

```powershell
ollama pull qwen3:1.7b
python worker.py --config config.json
```

در پنل باید Node نمایش داده شود.

## Agent Smoke Test

در صفحه Agent با Company تستی:

```text
طرف حساب‌هایی که نام ... دارند را پیدا کن.
```

انتظار: Tool خواندنی اجرا شود و Job موفق شود.

بعد:

```text
برای مشتری ... از کالای ... یک فاکتور فروش پیش‌نویس بساز.
```

انتظار:

1. Agent ابتدا party/item را با Tool پیدا کند.
2. Proposal بسازد.
3. هیچ فاکتور قطعی قبل از تأیید انسان ایجاد نشود.
4. بعد از «تایید و اجرا»، یک `acc_sales_docs` با `workflow_status=draft` ساخته شود.
5. Draft در بخش «حسابداری و مالی → فروش» دیده شود.

## Worker دوم

روی Laptop همان مراحل را اجرا کنید. می‌توانید Token جدا بسازید تا revoke/ردیابی هر دستگاه مستقل باشد.

وقتی هر دو Worker فعال‌اند، هر کدام که آزاد شود Job بعدی سازگار با capability را Lease می‌کند. در نتیجه Node سریع‌تر به‌طور طبیعی Job بیشتری در واحد زمان انجام می‌دهد.

## بعد از تست

هنوز این موارد را Production-ready فرض نکنید:

- posting خودکار فروش به GL
- inventory posting
- payroll UI/calculation
- fixed asset UI/depreciation
- اتصال واقعی سامانه مؤدیان
- rule packs قانونی واقعی
- مدل پیش‌بینی production
- vector store production

این‌ها فازهای بعدی roadmap هستند.
