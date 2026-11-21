# گزارش مدیریتی RC1 — تحویل اجرایی (فارسی)

**تصمیم مهندسی/پذیرش مستقل:** `RC1_ACCEPTED` (شاخهٔ کاندید PR #36)  
**استقرار تولید:** `NOT_DEPLOYED`  
**SHA پایهٔ `main`:** `c5c644c386cbedde788c4e12e0585c43dc1b5a5d` (PR #35)  
**شاخهٔ بستن نهایی:** `fix/rc1-final-legacy-data-ci-closure` → **PR #36**  
**تاریخ:** ۲۰۲۶-۱۱-۲۱

## خلاصه یک‌خطی

قراردادهای دامنه TR-1/TR-2/MF-1 روی MySQL آزمایشی **PASS_REPRODUCED** هستند. چرخهٔ عمر ساخت تست شده؛ ارزش‌گذاری WIP/FG کامل نیست و GL خودکار WIP/FG وجود ندارد (`UNVALUED` / `GL_BLOCKED`). UAT مرورگر محلی SYS-1 **`LOCAL_BROWSER_VERIFIED`** است (نه staging). Path2 دادهٔ ناشناس‌سازی‌شده restore → migrate×2 → تطبیق مالی/موجودی **PASS** (P1-001 بسته). دروازهٔ GHA MySQL+PHP **SUCCESS** (P1-006 بسته روی SHA کاندید). پذیرش RC1 روی کاندید **انجام شد**. تولید دست نخورده. برای نشستن روی `main` باید PR #36 ادغام شود.

## ماتریس قرارداد (مستقل)

| قرارداد | طبقه |
|---------|-------|
| TR-1 | PASS_REPRODUCED |
| TR-2 | PASS_REPRODUCED |
| MF-1 | PASS_REPRODUCED (lifecycle؛ WIP/FG valuation ناقص؛ بدون auto GL) |
| SYS-1 | PASS_EXISTING_EVIDENCE_VERIFIED + LOCAL_BROWSER_VERIFIED (نه STAGING) |
| OPS-1 | PASS_PATH2_DISPOSABLE_REHEARSAL (`SCHEMA_UPGRADE_TESTED` فقط Disposable) |

## آنچه اثبات شد

- MF همزمانی صدور: یک برنده + یک رد با کد WMS؛ موجودی منفی نمی‌شود  
- Trading: رد تکراری با کد `P2P_SUPPLIER_INVOICE_DUPLICATE` + متن فارسی  
- Dump ناشناس‌سازی‌شده (خارج از Git) + leak_scan PASS  
- Restore روی MySQL آزمایشی tmpfs/bridge → migrate×2 → Dr=Cr سندها برابر + quantity موجودی حفظ شد  
- بازیابی از baseline دست‌نخورده **PASS**  
- GHA `RC1 MySQL+PHP Gate` SUCCESS روی PR #36  
- هیچ اتصال cPanel/تولید انجام نشد  

## باقیمانده عملیاتی (غیر P1)

1. **ادغام PR #36** به `origin/main` تا شواهد بستن روی main بنشیند.  
2. UAT مرورگر staging اختیاری است (P2؛ الان فقط `LOCAL_BROWSER_VERIFIED`).  
3. تصمیم deploy تولید فقط با اقدام صریح مالک — هیچ deploy خودکاری انجام نشده.

## پیوند شش گزارش ماندگار

1. `docs/ai/rc1/RC1-INDEPENDENT-VERIFICATION.md`  
2. `docs/ai/rc1/RC1-ACCEPTANCE-REPORT.md`  
3. `docs/ai/rc1/RC1-EVIDENCE-INDEX.md`  
4. `docs/ai/rc1/RC1-BLOCKERS-AND-REMEDIATION.md`  
5. `docs/ai/rc1/RC1-RELEASE-MANIFEST.md`  
6. `docs/ai/rc1/RC1-EXECUTIVE-HANDOFF-FA.md` (همین فایل)
