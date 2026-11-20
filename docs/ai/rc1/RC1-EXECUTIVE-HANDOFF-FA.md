# گزارش مدیریتی RC1 — تحویل اجرایی (فارسی)

**تصمیم مهندسی/پذیرش مستقل:** `RC1_BLOCKED`  
**استقرار تولید:** `NOT_DEPLOYED`  
**SHA پایهٔ `main` قبل از بستن P1:** `2a30f5abac6291af2e6d3ccf1f87446335c84bad`  
**SHA نهایی `main` پس از ادغام PR #32:** `52fa7f12f465653d6e445d484f1a8dda48215f45`  
**شاخهٔ بستن:** `fix/rc1-stabilization-p1-closure` → **ادغام‌شده با PR #32**  
**تاریخ:** ۲۰۲۶-۱۱-۲۱ (بستن نهایی پذیرش)

## خلاصه یک‌خطی

بستن مهندسی P1های همزمانی MF، یونیکد cockpit، restore آزمایشی و دروازهٔ محلی CI MySQL **انجام و در main ادغام شد** (PR #32 @ `52fa7f12…`)؛ قراردادهای دامنه TR-1/TR-2/MF-1 روی MySQL آزمایشی **PASS_REPRODUCED** هستند. چرخهٔ عمر ساخت (lifecycle) تست شده؛ ارزش‌گذاری WIP/FG به‌طور کامل پشتیبانی نمی‌شود و GL خودکار WIP/FG وجود ندارد (`UNVALUED` / `GL_BLOCKED`). اما مرورگر واقعی SYS-1، provenance اسکیمای استقرار قبلی OPS-1، و نصب workflow اجباری GHA MySQL همچنان **خارجی/مسدود**اند — بنابراین پذیرش نهایی RC1 **مسدود** است، نه پذیرفته.

## ماتریس قرارداد (مستقل)

| قرارداد | طبقه |
|---------|-------|
| TR-1 | PASS_REPRODUCED |
| TR-2 | PASS_REPRODUCED |
| MF-1 | PASS_REPRODUCED (lifecycle؛ WIP/FG valuation ناقص؛ بدون auto GL) |
| SYS-1 | BLOCKED_EXTERNAL |
| OPS-1 | BLOCKED_EXTERNAL (restore مهندسی CLOSED) |

## آنچه در بستن P1 اثبات شد

- MF همزمانی صدور: یک برنده + یک رد با کد WMS؛ موجودی منفی نمی‌شود  
- Trading: رد تکراری با کد `P2P_SUPPLIER_INVOICE_DUPLICATE` + متن فارسی  
- Dump/restore با ادمین موقت + migrate×2 + rollback روی کانتینر تأییدشده (مهندسی restore **بسته**)  
- `rc1_ci_mysql_php_gate` محلی PASS (شکست روی skip)  
- valuation همچنان `UNVALUED` و GL ساختگی نیست (`GL_BLOCKED`) — مطابق `T5-VALUATION-DECISION.md`  
- Commercial MVP روی Final main: SUCCESS ولی **فقط source** — کافی برای RC1 نیست

## آنچه باقی است (اقدام دقیق مالک)

1. **Provenance OPS-1:** یا dump ناشناس staging/قبلی (Path2) **یا** تأیید کتبی «هرگز deploy/نصب نشده» (Path1) — بدون اختراع `FRESH_INSTALL`  
2. **UAT مرورگر:** سرو HTTP غیرتولیدی + `app/config.php` + کاربر آزمایشی؛ لاگین واقعی RTL/جلالی/IRR-Toman؛ اسکرین‌شات واقعی — تا آن زمان SYS-1 = `BLOCKED_EXTERNAL`  
3. **GHA MySQL+PHP:** یک‌بار `gh auth refresh -h github.com -s workflow` سپس کپی `docs/ai/rc1/ci/rc1-mysql-php-gate.yml` به `.github/workflows/` و اثبات SUCCESS واقعی — تا آن زمان **GHA E2E فعال اعلام نشود**  
4. فقط پس از رفع P1های خارجی تصمیم deploy — هیچ deploy خودکاری انجام نشده  

## پیوند شش گزارش ماندگار

1. `docs/ai/rc1/RC1-INDEPENDENT-VERIFICATION.md`  
2. `docs/ai/rc1/RC1-ACCEPTANCE-REPORT.md`  
3. `docs/ai/rc1/RC1-EVIDENCE-INDEX.md`  
4. `docs/ai/rc1/RC1-BLOCKERS-AND-REMEDIATION.md`  
5. `docs/ai/rc1/RC1-RELEASE-MANIFEST.md`  
6. `docs/ai/rc1/RC1-EXECUTIVE-HANDOFF-FA.md` (همین فایل)
