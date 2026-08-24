# 09-COMMERCIAL-MVP-RELEASE — قرارداد انتشار MVP تجاری

Status: `LOCAL-VALIDATED`

این سند Gate نهایی خروج از توسعه Feature و ورود به Commercial MVP است.

## Task contract

```text
Current baseline: a9a8c0259f4e7eaca248f9d9a912817fd1e23c92
Current phase: v9.3.0 Commercial MVP Hardening
Requirement: بستن قراردادهای release، recovery، idempotency، latency، security، observability و UX
In scope: Worker + Control Plane + tests + CI + SmartDocs
Out of scope: Feature مالی جدید، auto-execution، RAG، multi-agent، ماژول غیرمالی
Affected contracts: Job terminal lifecycle، Proposal idempotency، metadata/trace، HTTPS، release gate
Risk: HIGH؛ چون Worker و PHP Control Plane هر دو تغییر می‌کنند
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
python scripts/release_gate.py --require-php  # CI / release
```

Gate شامل Python syntax، JSON، secret scan، financial regression، runtime contract، static server boundary و PHP lint است.

## Live validation checklist

تا قبل از تکمیل موارد زیر، وضعیت v9.3 فقط `LOCAL-VALIDATED` است:

- [ ] GitHub CI release gate سبز
- [ ] PHP lint روی candidate/CI پاس
- [ ] cPanel Update from Remote + Deploy HEAD
- [ ] Docker Worker rebuild و startup/registration سالم
- [ ] Read job: metadata و latency budget دیده شود
- [ ] Blocked action: مدل و Tool واقعی در UI دیده شود؛ Proposal صفر
- [ ] Proposal retry: یک idempotency key → دقیقاً یک Proposal ID
- [ ] Complete-response replay: retry → `ok=true`, `replayed=true`, counter بدون کاهش دوباره
- [ ] Approved draft: human approval، balanced draft، Audit و post-action verification
- [ ] Logs/metadata فاقد token/authorization

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
- cPanel: deploy commit قبلی `a9a8c02`.
- Worker: بازگرداندن `engine/*` و `docker compose up -d --build worker`.
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
