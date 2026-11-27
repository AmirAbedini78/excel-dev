# تحویل فارسی — Universal ERP Agent (T0)

**تاریخ:** 2026-11-27  
**پایه:** `171d4b17c376529ac4c779b257e5c1ca90444643`  
**نوک شاخه:** `9ad7eecd82452c0989c21523655218e0c94461d0`  
**شاخه:** `feature/universal-erp-agent`  
**PR:** https://github.com/AmirAbedini78/excel-dev/pull/57  

## انجام‌شده

1. ماتریس واقعی Phase-1: `16-UNIVERSAL-ERP-AUDIT-MATRIX.md` (بدون ادعای کامل بودن منو/جدول).
2. معماری reuse: `AiToolRegistry` تنها مرجع Tool؛ `AiUniversalOperations` فقط adapter؛ MCP همچنان پیش‌فرض OFF؛ ترتیب guard حفظ شد.
3. برش عمودی universal برای Suite/Enterprise: catalog / search / read / report / navigate / history + `propose_erp_operation` با تأیید انسانی، بازاعتبارسنجی، snapshot/revision، maker/checker، بدون حذف فیزیکی.
4. routing فارسی در `worker.py` / `business_skills.py`؛ proposal مالی/انبار همچنان روی Toolهای اختصاصی.
5. ADR-033 + چک‌لیست استیجینگ ساب‌دامین: `17-UNIVERSAL-ERP-STAGING-CHECKLIST.md`.
6. تست‌های استاتیک سبز؛ اتصال به `release_gate.py`.

## شواهد تست

| Gate | نتیجه |
|------|--------|
| `php -l` (Docker php:8.2) روی فایل‌های تغییر یافته | PASS |
| `php tests/php_universal_ai_agent_contract_test.php` | PASS (static only) |
| `python -m unittest tests.test_universal_ai_agent_routing -v` | PASS (6) |
| DB E2E universal ops | **NOT RUN** |
| Browser RTL/proposal journey | **NOT RUN** |
| Staging subdomain | **NOT_DEPLOYED** |
| PRODUCTION_VERIFIED | **نه** |

## دستور rebuild / تست محلی

```text
git fetch origin && git checkout feature/universal-erp-agent
python -m unittest tests.test_universal_ai_agent_routing -v
docker run --rm -v "%CD%:/app" -w /app php:8.2-cli php tests/php_universal_ai_agent_contract_test.php
python scripts/release_gate.py --require-php --require-node
```

## پیکربندی مالک

- اعطای نقش: `ai.use` + `hr|projects|service|assets|expenses|pos.view/manage/approve` و در صورت نیاز `enterprise.<group>.view/manage/approve`
- فعال‌سازی ماژول‌های Suite/Enterprise مربوطه در Workspace
- کلید Gemini/Grok فقط از env؛ بدون کلید → `BLOCKED_MISSING_CREDENTIAL`؛ prod پیش‌فرض OFF
- `mcp_enabled` روی prod/main خاموش بماند

## استقرار ساب‌دامین (نه دامنه اصلی)

طبق `17-UNIVERSAL-ERP-STAGING-CHECKLIST.md`: پکیج CI-green روی ساب‌دامین + MySQL کلون؛ حفظ `storage`/config؛ بدون Docker/Redis/Node daemon جدید برای control plane؛ smoke فارسی catalog→navigate و proposal با تأییدکننده دوم.

## BLOCKED / شکاف‌های باقی‌مانده (دقیق)

1. **Payroll/حقوق قانونی ایران** — بدون مدل مالک/حقوقی → BLOCKED  
2. **MF costing → WIP/FG GL** — UNVALUED / GL_BLOCKED  
3. **Bank reconciliation** — NOT_IMPLEMENTED  
4. **فایل باینری اسناد / OCR / امضای رمزنگاری** — BLOCKED  
5. **DB integration E2E** برای هر مسیر create/update/archive/transition/review + cross-company + stale revision + maker/checker روی universal ops  
6. **Browser RTL** برای deep-link و کارت Proposal «پیشنهاد — اجرا نشده»  
7. **Provider smoke** Gemini/Grok با credential مالک  
8. **STAGING_VERIFIED** روی ساب‌دامین + چک‌لیست امضاشده  
9. سلول‌های ماتریس با وضعیت PARTIAL (CRM کامل، POS بدون posting، WMS metadata≠stock، …) تا تکمیل چرخه Domain+DB+UI+test  

## حکم کمپین

چون برش foundation و قراردادها merge-ready هستند ولی گیت‌های DB/browser/staging برای خروج کامل بازند و چند دامنه صریحاً BLOCKED مانده‌اند، حکم صادقانه:

**UNIVERSAL_ERP_AGENT_BLOCKED**
