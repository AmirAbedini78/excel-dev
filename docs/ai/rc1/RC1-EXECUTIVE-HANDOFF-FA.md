# گزارش مدیریتی RC1 — تحویل اجرایی (فارسی)

**تصمیم مهندسی/پذیرش مستقل:** `RC1_BLOCKED`  
**استقرار تولید:** `NOT_DEPLOYED`  
**SHA پایهٔ `main` قبل از بستن P1:** `2a30f5abac6291af2e6d3ccf1f87446335c84bad`  
**شاخهٔ بستن:** `fix/rc1-stabilization-p1-closure`  
**تاریخ:** ۲۰۲۶-۱۱-۲۰

## خلاصه یک‌خطی

بستن مهندسی P1های همزمانی MF، یونیکد cockpit، restore آزمایشی و دروازهٔ CI MySQL **انجام شد**؛ قراردادهای دامنه TR-1/TR-2/MF-1 روی MySQL آزمایشی دوباره سبز هستند. اما مرورگر واقعی SYS-1 و provenance اسکیمای استقرار قبلی OPS-1 همچنان **خارجی/مسدود**اند — بنابراین پذیرش نهایی RC1 **مسدود** است، نه پذیرفته.

## آنچه در بستن P1 اثبات شد

- MF همزمانی صدور: یک برنده + یک رد با کد WMS؛ موجودی منفی نمی‌شود  
- Trading: رد تکراری با کد `P2P_SUPPLIER_INVOICE_DUPLICATE` + متن فارسی  
- Dump/restore با ادمین موقت + migrate×2 + rollback روی کانتینر تأییدشده  
- `rc1_ci_mysql_php_gate` و workflow اجباری (شکست روی skip)  
- valuation همچنان `UNVALUED` و GL ساختگی نیست (`GL_BLOCKED`)

## آنچه باقی است (اقدام مالک)

1. dump ناشناس staging/قبلی **یا** تأیید کتبی «هرگز deploy نشده» (بدون اختراع `FRESH_INSTALL`)  
2. UAT مرورگر فارسی/RTL/جلالی/IRR-Toman با لاگین واقعی روی staging-like  
3. فقط پس از رفع P1های خارجی تصمیم deploy — هیچ deploy خودکاری انجام نشده  

## پیوند شش گزارش ماندگار

1. `docs/ai/rc1/RC1-INDEPENDENT-VERIFICATION.md`  
2. `docs/ai/rc1/RC1-ACCEPTANCE-REPORT.md`  
3. `docs/ai/rc1/RC1-EVIDENCE-INDEX.md`  
4. `docs/ai/rc1/RC1-BLOCKERS-AND-REMEDIATION.md`  
5. `docs/ai/rc1/RC1-RELEASE-MANIFEST.md`  
6. `docs/ai/rc1/RC1-EXECUTIVE-HANDOFF-FA.md` (همین فایل)
