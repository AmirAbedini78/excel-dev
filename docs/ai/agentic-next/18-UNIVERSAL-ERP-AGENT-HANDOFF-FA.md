# تحویل فارسی — Universal ERP Agent (T0)

**تاریخ:** 2026-11-27  
**پایه:** `171d4b17c376529ac4c779b257e5c1ca90444643`  
**نوک:** `7a2e02719457bf26b78c259de3cdd8768a498326`  
**شاخه:** `feature/universal-erp-agent`  
**PR:** https://github.com/AmirAbedini78/excel-dev/pull/57  

## انجام‌شده

1. ماتریس Phase-1 + ADR-033 + چک‌لیست ساب‌دامین.
2. `AiUniversalOperations` adapter تحت `AiToolRegistry`؛ MCP پیش‌فرض OFF.
3. catalog/search/read/report/navigate/history + `propose_erp_operation`.
4. P0 امنیتی: maker/checker در `ErpSuiteDomain::review`؛ catalog فقط ops مجاز RBAC را تبلیغ می‌کند.
5. **MySQL disposable E2E** (۹ تست، PASS نه SKIP تحت `RC1_REQUIRED`) — wired به `scripts/rc1_ci_mysql_php_gate.py`.
6. routing فارسی + static contracts در release_gate.

## شواهد تست

| Gate | نتیجه |
|------|--------|
| Commercial release-gate (PR tip قبلی) | PASS |
| RC1 mysql-php (PR tip قبلی) | PASS |
| `php_universal_ai_agent_contract_test.php` | PASS |
| `test_universal_ai_agent_routing` (6) | PASS |
| `test_universal_ai_ops_php_e2e` (9) | **PASS / DATABASE_TESTED** (local disposable MySQL) |
| Browser RTL/proposal | **NOT_RUN** (stack مرورگر محلی/UAT staging برای این مسیر موجود نیست) |
| Staging subdomain | **NOT_DEPLOYED** |
| PRODUCTION_VERIFIED | **نه** |

## دستور rebuild / تست

```text
git fetch origin && git checkout feature/universal-erp-agent
python -m unittest tests.test_universal_ai_agent_routing -v
docker run --rm -v "%CD%:/app" -w /app php:8.2-cli php tests/php_universal_ai_agent_contract_test.php
set RC1_REQUIRED=1
set RC1_ALLOW_ANY_MYSQL=1
set O2C_DB_HOST=127.0.0.1
set O2C_DB_PORT=3307
set O2C_DB_USER=p2p
set O2C_DB_PASS=p2p_test
set O2C_DB_NAME=erpsmart_universal_ai_test
python -m unittest tests.test_universal_ai_ops_php_e2e -v
```

## پیکربندی مالک

- `ai.use` + Suite/Enterprise view/manage/approve
- کلید Gemini/Grok فقط env؛ بدون کلید → `BLOCKED_MISSING_CREDENTIAL`
- `mcp_enabled=0` روی prod/main

## استقرار ساب‌دامین

طبق `17-UNIVERSAL-ERP-STAGING-CHECKLIST.md` — نه دامنه اصلی.

## شکاف‌های باز (کاهش‌یافته)

1. **Payroll قانونی** — BLOCKED (مالک/حقوقی)  
2. **MF costing/GL** — UNVALUED / GL_BLOCKED  
3. **Bank reconciliation** — NOT_IMPLEMENTED  
4. **فایل باینری / OCR / e-sign** — BLOCKED  
5. **Browser RTL/proposal UAT** — NOT_RUN  
6. **STAGING_VERIFIED** روی ساب‌دامین — باز  
7. CI روی tip جدید این push باید دوباره سبز شود (شامل E2E universal در RC1 gate)

## حکم

**UNIVERSAL_ERP_AGENT_BLOCKED** — شکاف‌های مهندسی قابل‌بستن برای MySQL E2E/P0 بسته شد؛ staging/owner/browser و دامنه‌های BLOCKED باقی است. PR باز می‌ماند؛ merge فقط پس از سبز شدن CI tip + تصمیم مالک برای staging.
