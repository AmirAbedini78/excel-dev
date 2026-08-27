# 09-COMMERCIAL-MVP-RELEASE — قرارداد انتشار MVP تجاری

Status: `LIVE-VALIDATED / FEATURE FROZEN`

این سند Gate نهایی خروج از توسعه Feature و ورود به Commercial MVP است.

## Task contract

```text
Live product baseline entering closeout: 448fca0b00a5ef2470e5498a9e25981ce30a7865
Current phase: v9.3 Commercial MVP closeout / feature freeze
Requirement: freeze the validated Accounting/Financial AI MVP and remove only release-blocking product defects
In scope: draft-only voucher delete hardening + release-gate runtime-config exclusion + SmartDocs
Out of scope: new financial feature، auto-execution، RAG، multi-agent، non-financial module expansion
Affected contracts: voucher lifecycle، release gate، SmartDocs release state
Risk: LOW؛ no Worker/AI routing/schema change
Success: approved AI Draft remains balanced/visible; non-draft voucher delete is blocked; source release gate ignores only intentional runtime config; v9.3 moves to RC/GTM
```

## Runtime release contract

آخرین Wrapper روی Guard Stack، `engine/commercial_hardening.py` است. این Wrapper:

- زمان end-to-end مسیر را اندازه می‌گیرد؛
- Toolهای واقعاً تلاش/موفق‌شده را بدون arguments/result ثبت می‌کند؛
- مدل تلاش‌شده را در Block/Fallback از Trace بازیابی می‌کند؛
- token/password/authorization/secret را پیش از persistence از metadata/trace حذف می‌کند؛
- Read-only route را در صورت مشاهده Proposal tool، fail closed می‌کند؛
- Proposal tools را هرگز به generic LLM tool loop نمی‌دهد؛ write فقط از Guardهای Grounded اختصاصی عبور می‌کند؛
- Proposal route را بدون `proposal_id` و `awaiting_human_approval` معتبر نمی‌داند؛
- اجرای مالی خودکار را همیشه `false` نگه می‌دارد؛
- اتصال remote Control Plane را فقط با HTTPS می‌پذیرد.

## Permission / risk matrix

| Operation | Risk | Worker behavior | Human permission | Execution |
|---|---:|---|---|---|
| Grounded read | low | direct Tool read | `ai.use` | immediate read |
| Deterministic analysis/forecast/recommendation | low | read-only | `ai.use` | immediate analysis |
| Sales invoice draft | medium | Proposal only | `ai.actions.approve` | human approval → draft |
| Balanced voucher draft | high | Proposal only | `ai.actions.approve` | human approval → draft |
| Post/finalize/delete/reversal/external send | prohibited | Tool exposed نیست | none | unavailable |

محدودیت فعلی: medium/high هر دو از permission مشترک `ai.actions.approve` استفاده می‌کنند؛ UI سطح ریسک را صریح نشان می‌دهد. تفکیک permission جدید فقط با تصمیم محصولی و migration جداگانه انجام می‌شود. هیچ سطحی auto-approved نیست.

## Idempotency and failure recovery

### Proposal

`(workspace_id, job_id, idempotency_key)` unique است و insert از الگوی atomic زیر استفاده می‌کند:

```text
INSERT ... ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
```

Retry هم‌زمان یا ترتیبی همان Proposal ID را می‌گیرد؛ Proposal دوم ساخته نمی‌شود.

### Job terminal response

```text
server commits complete
→ response is lost
→ worker retries complete
```

Control Plane اکنون lease hash را برای recovery نگه می‌دارد و retry همان `complete` یا `fail` را تا ۲۴ ساعت با `replayed=true` acknowledge می‌کند. terminal مخالف (`complete` بعد از `failed` یا برعکس) با `job_terminal_conflict` رد می‌شود. شمارنده Worker فقط در transition اولیه کم می‌شود.

### Request correlation

هر API call یک `X-AI-Request-ID` ثابت در تمام retryهای همان call دارد. Control Plane همان شناسه را در header/body پاسخ برمی‌گرداند. خطاهای SQL/PDO/path با `server_error` + request ID redacted می‌شوند و جزئیات فقط در server log می‌مانند.

## Latency budgets

| Class | Default budget |
|---|---:|
| deterministic | 5s |
| read_model | 45s |
| action | 45s |
| deep | 240s |
| fallback | 90s |

بودجه‌ها SLO اولیه‌اند، timeout یا ادعای performance تضمین‌شده نیستند. Override فقط از `latency_budgets_seconds` در Worker config انجام می‌شود. خروجی هر Job شامل `end_to_end_seconds`, `latency_budget_seconds`, `latency_status` است.

## Observability contract

هر نتیجه باید `mode`، مدل/مدل تلاش‌شده، Toolهای استفاده/تلاش‌شده، Trace redacted، risk/mutation boundary، وضعیت Proposal/Approval، زمان end-to-end، budget status و release contract `commercial-mvp-v1` را داشته باشد. خطاهای Control Plane با request ID قابل correlation هستند.

در browser boundary فقط Tool name و metrics عددی allowlisted مجاز است: lowercase/underscore identifier، حداکثر ۸۰ کاراکتر، unique و حداکثر ۳۲ نام؛ metrics فقط first chunk/elapsed/token count/duration. arguments، result، call ID، free-form model metadata و trace detail حساس مجاز نیستند.

## Security gate

- remote `server_url` باید HTTPS باشد؛ HTTP فقط loopback محلی.
- Worker token باید قالب server-issued `aiw_` + 48 hex داشته باشد.
- secret scan روی Python/PHP/JS/JSON/Docs/PowerShell اجرا می‌شود.
- LLM هیچ DB credential، SQL آزاد یا direct mutation ندارد.
- tenant/company ownership همچنان فقط server-side validate می‌شود.
- metadata/trace قبل از ذخیره redacted می‌شود.

## Regression / release gate

```bash
python scripts/release_gate.py
python scripts/release_gate.py --require-php --require-node  # CI / release
```

Gate شامل Python syntax، JSON، secret scan، financial regression، runtime contract، static server boundary، PHP lint + live-observability behavior و JavaScript syntax است. Installer ویندوز هر سه runtime را فقط داخل Docker فراهم می‌کند.

## Live validation checklist

تا قبل از تکمیل موارد زیر، وضعیت v9.3 فقط `LOCAL-VALIDATED` است:

- [x] GitHub CI release gate برای commit `27e34a9` سبز
- [x] PHP lint برای v9.3.0 candidate/CI پاس
- [x] cPanel Update from Remote + Deploy HEAD روی `27e34a9`
- [x] Docker Worker rebuild و startup/registration سالم
- [x] GitHub Python/PHP/Node gate و cPanel deploy برای commit `2f19686`
- [x] GitHub Python/PHP/Node gate و cPanel deploy برای v9.3.0.2 final SHA `7e1c7c5`
- [x] Read job: metadata و latency budget دیده شود — Job #50
- [x] Blocked action: مدل، attempted metrics و Tool واقعی در UI دیده شود؛ Proposal صفر — Job #53
- [x] Proposal retry contract: یک idempotency key → دقیقاً یک Proposal ID — deterministic recovery self-test PASS
- [x] Complete-response replay contract: retry → `ok=true`, `replayed=true` — deterministic recovery self-test PASS
- [x] Approved draft closeout: human approval + balanced draft + product-UI article verification PASS (`AI-VCH-20260826-202025-9F19`)
- [x] Committed-source/metadata/UI redaction gate PASS; intentionally local/gitignored `engine/config.json` is excluded from source secret scanning. Standalone live fault-injection/log harness is deferred unless a real product failure appears.

Job #49 بخش Worker/financial Read gate را پاس کرد ولی no-refresh UI شکست خورد. v9.3.0.1 روی commit `2f19686` deploy شد و Job #50 route/model/metrics/end-to-end/budget/risk را بدون refresh نمایش داد؛ بنابراین Read gate بسته است.

Job #51 blocked action را با مدل واقعی `qwen3.5:0.8b`، ریسک بالا، بودجه پاس، دو حساب واقعی و Proposal صفر اجرا کرد. بخش safety/model-name PASS است؛ اما نام `search_parties/party_ledger/trial_balance` و attempted-model metrics در UI نبود. checkbox تا deploy v9.3.0.2 و تکرار همین Prompt باز می‌ماند.

Job #54 created high-risk Proposal #3 for a grounded `100,000,000 IRR` receipt. Human approval created `AI-VCH-20260826-202025-9F19` as `general / draft`. Product UI verification then confirmed exactly two stored articles: debit `10101 بانک ملت - جاری` for `100,000,000` and credit `11001 حساب‌های دریافتنی تجاری` for party `CUS-003 کارخانه بهین بسته‌بندی` for `100,000,000`; voucher difference is `0`. The full `grounded request → Proposal → human approval → balanced Draft → product UI verification` gate is closed.

## Commercial demo script

1. سؤال مالی قطعی و نمایش پاسخ Grounded + زمان کل.
2. Forecast/Risk با توضیح range و confidence.
3. Proactive review و اثبات `proposal_created=false`.
4. درخواست دریافت با حساب مبهم و اثبات fail-closed.
5. درخواست دقیق و ساخت Proposal high-risk.
6. نمایش ریسک/پارامترهای Grounded، Human Approval و Draft.
7. retry همان action و اثبات Proposal تکراری ساخته نشده است.

## Rollback

- قبل از نصب، backup خارجی از exact changed-file set.
- rollback v9.3.0.2: cPanel را به commit `2f19686` برگردان؛ Worker نیاز به تغییر ندارد.
- rollback کل v9.3: commit `a9a8c02` و سپس بازسازی Worker از `engine/*` همان baseline.
- این milestone Schema migration جدید ندارد؛ rollback نیازمند rollback دیتابیس نیست.
- Proposal/Draft ساخته‌شده در Live test باید طبق lifecycle حسابداری و Audit مدیریت شود، نه با حذف مستقیم DB.

## Feature-freeze rule

همه MVP checklistهای لازم برای release candidate بسته شده‌اند:

```text
v9.3 → FROZEN
Commercial MVP → release candidate
Next work → market/customer/pricing/positioning/demo/pitch/GTM
```

هر Feature جدید بعد از freeze نیازمند تصمیم و milestone جداگانه است.
