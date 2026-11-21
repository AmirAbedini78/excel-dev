# گزارش آمادگی Staging — ERPSMART RC1

**تاریخ:** ۲۰۲۶-۱۱-۲۱  
**مالک یکپارچه‌سازی:** T0 Release Engineering / Staging Acceptance  
**SHA انتشار تغییرناپذیر:** `2c1cf6592f246553c66c3bc90d00385bf65d4c29`  
**پذیرش مهندسی RC1:** `RC1_ACCEPTED`  
**تولید:** `NOT_AUTHORIZED` / `NOT_DEPLOYED`  
**دسترسی remote staging:** **مجاز نشده** (فقط برنامه)  
**Pre-flight اجرا:** executor مهاجرت staging + پکیج + UAT روی شاخهٔ آماده‌سازی — **بدون** ادعای `STAGING_VERIFIED`

> `RC1_ACCEPTED` ≠ `PRODUCTION_READY` ≠ `STAGING_VERIFIED`

**ابزارهای pre-flight (بدون remote):** `scripts/rc1_staging_schema_migrate.php` · `RC1-STAGING-REMOTE-AUTH-CHECKLIST.md` · `RC1-STAGING-PACKAGE-INTEGRITY.md` · `RC1-STAGING-UAT-PROCEDURES.md`

---

## ۱. هویت پایه و انجماد ویژگی

| مورد | مقدار |
|------|--------|
| مخزن | `AmirAbedini78/excel-dev` |
| شاخه | `main` |
| SHA تأییدشده | `2c1cf6592f246553c66c3bc90d00385bf65d4c29` |
| ادغام نهایی | PR #36 MERGED (`fix/rc1-final-legacy-data-ci-closure`) |
| نسخهٔ اسکیما | `10.24.0-rc1` |
| Feature freeze | فعال — فقط نقص‌های آمادگی staging |

## ۲. وضعیت CI پس از ادغام (روی همان SHA)

| دروازه | نتیجه | Run |
|--------|--------|-----|
| Commercial MVP Gate | **SUCCESS** | https://github.com/AmirAbedini78/excel-dev/actions/runs/44931684788 |
| RC1 MySQL+PHP Gate | **SUCCESS** | https://github.com/AmirAbedini78/excel-dev/actions/runs/44931684872 |

Commercial MVP به‌تنهایی برای RC1 کافی نیست؛ دروازهٔ MySQL+PHP با fail-on-skip الزامی است و روی این SHA سبز است.

## ۳. آرتیفکت انتشار و PHP/MySQL

| مورد | وضعیت |
|------|--------|
| منبع تغییرناپذیر | `git archive` / checkout از SHA بالا |
| ساخت پیشنهادی | `git archive --format=zip -o erpsmart-rc1-2c1cf65.zip 2c1cf6592f246553c66c3bc90d00385bf65d4c29` (بدون `.env` / dump / `storage/cache` محلی) |
| ساخته‌شده این پاس | **بله** (موقت) — ۲٬۱۱۹٬۴۰۷ بایت؛ SHA-256 `6F7E7011B9D3FF834A392E9004476E91981C30FF5C900857B1B6771D66DF6380`؛ در Git ذخیره نشد |
| PHP | **8.2** (`erpsmart-o2c-php:8.2`؛ ext: `pdo_mysql`) |
| MySQL | **8.0** (تصویر `mysql:8.0` در GHA و sandbox محلی) |
| ارز کارکردی | IRR (نمایش تومان اختیاری) |

## ۴. آمادگی DB / مهاجرت / تطبیق رکوردی

| مورد | طبقه / نتیجه |
|------|----------------|
| استقرار قبلی | `EXISTING_DEPLOYMENT_CONFIRMED` |
| Path2 dump ناشناس | `PATH2_ANON_SCHEMA_DATA_VERIFIED` (خارج از Git؛ leak_scan PASS) |
| migrate×2 روی clone یک‌بارمصرف | **PASS** (۱۱۰→۱۸۲ جدول) |
| تطبیق مالی/موجودی | **PASS** — vouchers ۴۷ / lines ۱۱۷؛ Dr=Cr `18063580000.00`؛ stock qty `4.0000`؛ inventory qty `1094.0000`؛ mismatch=0 |
| `SCHEMA_UPGRADE_TESTED` | فقط disposable Path2 |
| مسیر migrate | CLI/آفلاین اپراتور — **نه** اولین HTTP hit |
| Preflight کش اسکیما | پاک‌سازی هدفمند `schema.json` برای fingerprint همان DB rehearsal |
| `FRESH_INSTALL` | **فرض نشده** |

شواهد: `ops1_legacy_data_rehearsal.json`, `ops1_legacy_data_reconciliation.json`, `agent_c_provenance_status.json`, `RC1-MIGRATION-PREFLIGHT.md`.

## ۵. پشتیبان / بازیابی / Rollback

| Drill | نتیجه |
|-------|--------|
| Dump/restore ممتاز + migrate×2 | **PASS** (`ops1_backup_restore_closure.json`) |
| Restore با کاربر محدود بدون grant | `FAIL_ACCESS_DENIED_EXPECTED` |
| Rollback rehearsal | restore از dump به اسکیمای جدا (DDL تراکنشی نیست) |
| Runbook | `RC1-ROLLBACK-RUNBOOK.md` — فقط disposable؛ تولید خارج از باند مالک/DBA |

## ۶. UAT مرورگر کسب‌وکار

| طبقه | وضعیت |
|------|--------|
| `LOCAL_BROWSER_VERIFIED` | **بله** — ۹ PNG واقعی + لاگ CSRF/session |
| `STAGING_VERIFIED` | **خیر** — URL staging و مجوز remote داده نشده |

پوشش محلی (شواهد قبلی): login/logout، trading، manufacturing (بنر GL BLOCKED)، service/retail، RTL/Jalali، IRR/Toman، ایزوله شرکت، نقش محدود، CSRF ۴۱۹.  
مسیر: `docs/ai/rc1/evidence/browser/` و `T6-BROWSER-NOTES.md`.

## ۷. امنیت / عملیات / کیفیت

| مورد | یادداشت |
|------|---------|
| Secret scan (release_gate) | الگوی توکن/کلید خصوصی روی فایل‌های tracked |
| Dump خام / PII | **ممنوع در Git**؛ کاندید dump = UNSAFE (فقط offline) |
| رگرسیون TR-1..OPS-1 | GHA RC1 gate SUCCESS با fail-on-skip روی SHA نهایی |
| همزمانی MF | تست concurrent issue: یک برنده + یک رد؛ موجودی منفی نمی‌شود |
| میزبانی اشتراکی/cPanel | یادداشت‌های OPS1؛ هیچ mutation روی cPanel در این کارزار |

## ۸. برنامه استقرار Staging (فقط مستندات — بدون remote)

1. **معماری هدف:** ساب‌دامین/اکانت غیرتولید جدا؛ DB staging جدا از production MySQL.  
2. **بسته:** ZIP از SHA `2c1cf65…` بدون secrets.  
3. **پیکربندی:** `app/config.php` gitignored؛ `app_key` جدید staging؛ هرگز کپی production secrets.  
4. **DB:** restore dump ناشناس یا seed مصنوعی → `scripts/rc1_staging_schema_migrate.php` (نه `install.php` / نه اولین HTTP) → تأیید schema cache فقط staging.  
5. **Maintenance:** قفل `storage/maintenance/rc1_offline_migrate.lock` (۵۰۳ وب تا اتمام migrate)؛ قطع cron/worker/SMS/پرداخت زنده روی staging.  
6. **Smoke/UAT:** طبق `RC1-STAGING-UAT-PROCEDURES.md` و اسکلت Playwright `tests/staging_uat/` (فقط با `STAGING_BASE_URL` مجاز).  
7. **Rollback:** بازگردانی dump pre-migrate + کد SHA قبلی (`RC1-ROLLBACK-RUNBOOK.md`).  
8. **Go/No-Go:** بدون مجوز صریح مالک طبق `RC1-STAGING-REMOTE-AUTH-CHECKLIST.md` → هیچ تغییر remote.  
9. **پکیج:** `RC1-STAGING-PACKAGE-INTEGRITY.md` + `RC1-STAGING-RELEASE-PACKAGE-MANIFEST.md`.

## ۹. محدودیت MF و ادعاهای ممنوع

- WIP/FG valuation در RC1 **کامل پشتیبانی نمی‌شود**؛ طبقه هزینه می‌تواند `UNVALUED` باشد.  
- `WIP/FG GL = BLOCKED` — بدون GL خودکار WIP/FG (`T5-VALUATION-DECISION.md`).  
- هیچ ادعای انطباق مقرراتی ایرانی (مالیات/صیاد/بانک زنده) در این کارزار ساخته نشده.  
- `RC1_ACCEPTED` به‌معنی `PRODUCTION_READY` نیست.

## ۱۰. اقدام مالک و حکم

### برای `STAGING_VERIFIED` لازم است
1. مجوز صریح remote staging (URL + دسترسی غیرتولید).  
2. استقرار بسته از SHA `2c1cf65…` روی staging جدا.  
3. UAT مرورگر واقعی روی staging با اسکرین‌شات جدید زیر `evidence/browser/staging/`.  
4. تأیید migrate آفلاین روی DB staging و smoke checklist.

### حکم این پاس
به بخش انتهایی گزارش یکپارچهٔ T0 مراجعه شود (یک مقدار از: `STAGING_READY` | `STAGING_VERIFIED` | `STAGING_BLOCKED`).
