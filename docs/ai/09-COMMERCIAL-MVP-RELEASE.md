# 09-COMMERCIAL-MVP-RELEASE — قرارداد انتشار MVP تجاری

Status: `LIVE-VALIDATION-IN-PROGRESS`; v9.3.0.2 hotfix `LOCAL-VALIDATED`

این سند Gate نهایی خروج از توسعه Feature و ورود به Commercial MVP است.

## Task contract

```text
Current deployed baseline: 2f196868c9f27c719cf0165fd541656a2e5f11d4
Current phase: v9.3.0.2 Safe Attempt Observability Hotfix inside the v9.3 Commercial MVP gate
Requirement: بستن قراردادهای release، recovery، idempotency، latency، security، observability و UX
In scope: Worker + Control Plane + tests + CI + SmartDocs
Out of scope: Feature مالی جدید، auto-execution، RAG، multi-agent، ماژول غیرمالی
Affected contracts: Job terminal lifecycle، Proposal idempotency، metadata/trace، HTTPS، release gate
Risk: MEDIUM؛ v9.3.0.2 فقط authenticated PHP/JS presentation boundary و tests/docs را تغییر می‌دهد
Success: هیچ Mutation مستقیم، duplicate Proposal یا double terminal side effect؛ suite و lint و live gates پاس
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
- [ ] Approved draft closeout: human approval + balanced draft PASS (`AI-VCH-20260826-202025-9F19`); product-UI article verification pending
- [ ] Logs/metadata فاقد token/authorization

Job #49 بخش Worker/financial Read gate را پاس کرد ولی no-refresh UI شکست خورد. v9.3.0.1 روی commit `2f19686` deploy شد و Job #50 route/model/metrics/end-to-end/budget/risk را بدون refresh نمایش داد؛ بنابراین Read gate بسته است.

Job #51 blocked action را با مدل واقعی `qwen3.5:0.8b`، ریسک بالا، بودجه پاس، دو حساب واقعی و Proposal صفر اجرا کرد. بخش safety/model-name PASS است؛ اما نام `search_parties/party_ledger/trial_balance` و attempted-model metrics در UI نبود. checkbox تا deploy v9.3.0.2 و تکرار همین Prompt باز می‌ماند.

Job #54 then created high-risk Proposal #3 for a grounded receipt. Human approval created `AI-VCH-20260826-202025-9F19` as a balanced `draft` with `100,000,000 IRR` debit and credit. The remaining closeout check is deliberately product-facing: open the stored voucher articles from the Accounting UI and verify the two lines. Standalone live fault-injection harnesses are deferred unless a real runtime failure appears; the deterministic idempotency/replay contract tests remain mandatory.

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

پس از `LIVE-VALIDATED` شدن همه checklistها:

```text
v9.3 → FROZEN
Commercial MVP → release candidate
Next work → market/customer/pricing/positioning/demo/pitch/GTM
```

هر Feature جدید بعد از freeze نیازمند تصمیم و milestone جداگانه است.
