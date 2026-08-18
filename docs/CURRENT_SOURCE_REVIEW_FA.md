# گزارش بررسی مهندسی سورس فعلی

## نتیجه

سورس فعلی برای ادامه مناسب است و بازنویسی کامل از صفر توصیه نمی‌شود. مهم‌ترین سرمایه موجود، multi-workspace/RBAC، چندشرکتی، audit، API foundation و ماژول حسابداری موجود است. مسیر بهتر: استخراج Domain Services از ماژول بزرگ فعلی و تکمیل تدریجی زیرسیستم‌ها.

## نقاط قوت

- PHP/MySQL سبک و قابل اجرا روی shared hosting
- نبود وابستگی اجباری به Composer/Node در runtime
- multi-tenant foundation
- Permission model
- audit
- cache/versioning
- company context
- chart of accounts / parties / items / warehouse masters
- purchase/voucher/treasury/production foundation
- cPanel deployment آماده

## بدهی فنی مهم

1. `AccountingIndustrialModule.php` هنوز یک ماژول بزرگ و چندمسئولیتی است؛ با رشد محصول باید به Controller/Service/Repository per-domain شکسته شود.
2. منطق Posting رویدادهای فروش/خرید/دریافت/پرداخت به دفتر کل هنوز باید به یک Posting Engine مستقل تبدیل شود.
3. lifecycle اسناد باید immutable/controlled شود؛ حذف سند قطعی نباید مشابه Draft باشد.
4. بستن دوره باید روی همه mutationهای مالی enforce شود، نه فقط UI.
5. شماره‌گذاری production باید sequence/locking مشخص داشته باشد.
6. reporting queryها باید وضعیت سند و scope دوره را دقیق رعایت کنند.
7. foreign keys منطقی فعلی عمدتاً در application validation هستند؛ برای رشد باید integrity strategy رسمی تعریف شود.
8. Tax/compliance باید versioned adapter باشد.
9. Integration/e2e test suite باید اضافه شود.
10. برای data volume بالا pagination و query plans/index telemetry لازم است.

## اصلاحات این فاز

- تراز و دفتر طرف حساب AI فقط از اسناد `approved/final` عدد می‌گیرند، نه Draft.
- فروش واقعی به پنل اضافه شده تا Draft ایجنت در workflow انسانی دیده شود.
- schemaهای آینده با indexهای scope/date ایجاد شده‌اند.
- mutationهای AI مستقیم SQL آزاد نیستند.
- عنوان سطح اول از «حسابداری صنعتی» به «حسابداری و مالی» تغییر کرده و تولید همچنان زیرماژول مستقل است.

## پیشنهاد Refactor بعدی

```text
app/Accounting/
  Ledger/
  Sales/
  Purchase/
  Inventory/
  Treasury/
  Payroll/
  Assets/
  Production/
  Compliance/
  Reporting/
  Shared/
```

هر Domain:

```text
Controller -> Application Service -> Domain Rules -> Repository
                            |
                            +-> Posting Engine
                            +-> Audit/Event
```

AI Toolها فقط Application Service را صدا می‌زنند؛ UI نیز همان Service را استفاده می‌کند. این کار دو منطق جدا برای انسان و Agent ایجاد نمی‌کند.
