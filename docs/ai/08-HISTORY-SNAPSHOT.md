# 08-HISTORY-SNAPSHOT — تاریخچه فشرده و ماندگار توسعه

این فایل History کامل گفتگو نیست؛ عصاره تصمیم‌ها، Failureها و Milestoneهایی است که برای ادامه توسعه لازم‌اند.

## Origin

Vision اولیه:
- یک Accounting Platform برای حسابدارها
- چندشرکتی
- AI-native
- چت با سیستم
- Agent برای اجرای کار
- پیشنهاد proactive
- تحلیل و پیش‌بینی
- Local-first برای کاهش هزینه
- امکان Scale به سرور/GPU

تصمیم اولیه درست: به جای Clone کامل سپیدار/هلو و سپس AI، Vertical Slice واقعی بسازیم.

## Foundation

Control Plane روی cPanel و Worker محلی شکل گرفت:

```text
cPanel UI/Auth/RBAC/MySQL/Queue/Tools/Approval/Audit
                     ↑ HTTPS
Docker Worker + Ollama
```

Safe Tool design، Lease، Idempotency، Approval و Audit از ابتدا ستون‌های معماری شدند.

## Early hardware lesson

Tool-heavy prompts روی CPU قدیمی بسیار کند شدند. A/B test نشان داد خود Ollama/Tool Calling کار می‌کند، اما prompt schema بزرگ هزینه زیادی دارد.

نتیجه:
- Dynamic/specialized routing
- deterministic fast financial path
- LLM فقط جایی که ارزش دارد

## v7.x

Fast financial analysis path و Docker worker تثبیت شدند. تجربه چند installer خطادار باعث شد روش توسعه به candidate-first validation تغییر کند.

## v8.0

Live/SSE observability و latency instrumentation اضافه شد تا «در حال پردازش» black box نباشد.

## v8.2 series — Deep analysis lessons

آزمایش‌های متعدد نشان دادند:
- LLM با عدد مالی می‌تواند رقم را تحریف کند.
- نام کلید مبهم می‌تواند معنای حسابداری را عوض کند.
- نبود داده نباید به ادعای risk تبدیل شود.
- forcing متن طولانی روی CPU ضعیف latency را شدید می‌کند.

خروجی معماری:
**Safe Deep Core** با deterministic facts، محدودیت داده، LLM enhancement محدود و fallback.

Milestone پایدار:
`v8.2C.4.2 Safe Deep Core`

## v8.3 — Guarded Invoice Agent

Failure واقعی Job #17 نشان داد مدل می‌تواند Tool chain را ناقص اجرا کند و حتی ID بسازد.

راه‌حل:
- invoice-specific guard
- search party
- search each item
- IDs فقط از Tools
- args deterministic
- proposal confirmation from server
- human approval

این Milestone اولین Action Agent واقعی و امن بود.

## v8.4 — Grounded Read Agent

Readهای عمومی حسابداری از پاسخ آزاد LLM جدا شدند و به Grounded Tools متصل شدند.

## v8.5 — Parameterized Query Engine

محدودیت readهای ثابت شکسته شد:
- period
- Jalali
- comparison
- group
- status
- multi-intent

Live reconciliation نشان داد مجموع گروه‌ها و دوره‌ها با total سازگار است.

## v8.6 — Semantic & Entity-Scoped Analytics

- `confirmed = approved + final`
- all/draft/approved/final
- party scope
- item scope
- party + item
- rolling months
- top customers/items

Live Jobs 27–30 مسیرهای واقعی را تأیید کردند.

## v8.7 — Adaptive Semantic Router

ایده optimization:
- بار اول unknown read → LLM Plan → validate → fresh Tools → store Plan
- بار بعد → Plan cache → fresh Tools
- Answer cache ممنوع

Job #31 adaptive MISS واقعی را ثابت کرد.

تصمیم مهم بعدی:
v8.7 FROZEN؛ Cache/Dictionary نباید هدف پروژه شود.

## Current correction of roadmap

از بحث‌های بعدی یک Scope correction مهم تثبیت شد:

> فعلاً نرم‌افزار حسابداری را کامل نمی‌کنیم و به Notes/other modules نمی‌رویم. Existing Accounting/Financial module بستر آزمون و ساخت AI MVP است. اول Workflowهای سؤال، تحلیل، prediction و agent را سالم می‌کنیم. بعد از MVP تجاری، Accounting Application کامل‌تر ساخته می‌شود.

## Next

`v8.8 — Accounting Constrained Workflow Planner`

سپس:
- Action Orchestrator
- Financial Intelligence
- Forecast/Risk/Anomaly
- Proactive Agent
- Commercial hardening

## v8.8 — Accounting Constrained Workflow Planner

Baseline:
`cd13fae227f18229ee734958ea465b41885e78e2`

هدف این فاز از SmartDocs استخراج شد، نه از یک ایده موقت:

```text
Prompt مالی چندمرحله‌ای
→ constrained JSON plan
→ validation
→ step dependencies
→ fresh accounting tools
→ deterministic derivation
→ grounded answer
```

قفل‌های v8.8:

- Read-only first
- max 8 step
- no SQL
- no model-generated DB IDs
- no model-generated financial facts
- `party_id` و `item_id` فقط از Tool result مرحله قبل
- existing deterministic read، Safe Deep و Guarded Invoice حفظ می‌شوند
- Adaptive Cache همچنان FROZEN است

Validation package قبل از mutation:

```text
Core workflow planner: 19/19 PASS
Actual guard-stack integration: 7/7 PASS
```

Live cPanel validation هنوز لازم است؛ بنابراین وضعیت فعلی `LOCAL-VALIDATED` است.

## v8.8 Live Incident — Job #32

Job #32 correctly entered the new workflow layer, but the first Live Test did **not** qualify as `LIVE-VALIDATED`.

Observed:

```text
workflow_plan_llm
→ workflow_plan_rejected
→ workflow_plan_fallback
→ s1/s2/s3/s4
→ party_ledger dependency
→ workflow_blocked: no ranking rows
```

Two distinct findings:

1. Qwen 0.8B can emit harmless internal Plan-shape drift (step IDs / ranking limit / pronoun reference) that should be canonicalized when it does not change financial meaning.
2. The Live Test crossed the Jalali month boundary into 1405/06/01. The demo data had confirmed sales in Mordad but no confirmed rows yet for current Shahrivar, so `group_by=party` legitimately returned no customer. The workflow must preserve the valid month comparison and explain that no top customer exists, instead of discarding earlier results.

### v8.8.0.1 hardening

- canonicalize internal step IDs
- normalize deterministic top-N constraint
- resolve `همان مشتری/کالا` to a prior grouped Tool step
- expose exact planner rejection reason in trace
- empty dependency becomes `accounting_workflow_partial`
- malformed non-empty group with missing server ID still hard-blocks

Pre-mutation package validation:

```text
Core: 22/22 PASS
Actual guard-stack integration: 8/8 PASS
```

Live validation remains pending.

## v8.8.0.1 Live Evidence — Jobs #33 and #34

### Job #33

Real cPanel data on 1405/06/01:

```text
current confirmed sales = 0
previous month confirmed sales = 1,985,720,000 IRR
ranking current month = empty
```

The hardened partial semantics worked:

```text
workflow_plan_fallback
→ compare kept
→ empty ranking
→ dependent ledger skipped
→ accounting_workflow_partial
```

This is a valid partial Workflow result and no customer ID was invented.

### Job #34

Prompt:

```text
مشتری برتر فروش قطعی ماه قبل را پیدا کن و مانده همان مشتری را هم بررسی کن.
```

Observed:

```text
workflow_plan_llm
→ workflow_json_invalid
→ no canonical fallback for this pattern
→ delegate
→ old party_search
→ meaningless "name/code not specified"
```

Conclusion:
Prompt-only JSON instruction is not sufficient for the local 0.8B planner. The Worker transport must request Ollama structured JSON output.

## v8.8.0.2

Changes:

- `Worker.ollama_chat(..., response_format=...)`
- planner calls Ollama with `response_format="json"`
- existing calls remain unchanged when response_format is omitted
- deterministic recovery added for `top party in one grounded period → ledger same party`
- validator remains authoritative after structured output
- malformed planner output can no longer fall through to the old empty `party_search` for Job #34

Pre-mutation package validation target:

```text
Core: 24/24
Actual guard-stack integration: 9/9
Structured worker transport patch: LF/CRLF + reapply rejection
```

Live Structured Planner validation remains pending.

## v8.8.0.2 Live Evidence — Jobs #35 and #36

### Job #35

The partial workflow remains correct on the real Jalali month boundary:

```text
current confirmed sales = 0
previous confirmed sales = 1,985,720,000 IRR
top party current month = no rows
dependent ledger skipped
accounting_workflow_partial
```

No entity or ID was invented.

### Job #36

The dependency executor is Live-proven:

```text
document_analytics previous month, confirmed, group_by=party, limit=1
→ server returned کارخانه بهین بسته‌بندی + real party_id
→ party_ledger(real party_id)
→ balance 727,100,000 IRR
```

Therefore Tool dependency execution is healthy.

However both Jobs still showed:

```text
workflow_plan_llm
→ workflow_json_invalid
→ workflow_plan_fallback
```

So the General LLM Planner was still not Live-proven.

## Root-cause diagnostic after Jobs #35/#36

A direct local Ollama diagnostic used the real `planner_prompt`, both Live prompts, and `format=json`.

Both cases returned:

```text
done_reason = length
eval_count = 320
message.content = ""
message.thinking = long reasoning text
```

The model consumed the full `num_predict=320` budget in thinking before it emitted any JSON content.

This rules out the JSON validator as the primary failure.

## v8.8.0.3

Design correction:

- add optional per-call `think_override` to `Worker.ollama_chat`
- existing Qwen calls continue to respect the configured global `think` setting
- Accounting Workflow Planner calls:
  - `response_format="json"`
  - `think_override=False`
- do not increase reasoning budget for this structural task
- validator remains unchanged and authoritative
- if fallback is still needed after a real LLM attempt, metadata retains the attempted planner model/metrics instead of falsely showing `model=none`

Live validation remains pending.

## Planner diagnostics after v8.8.0.3

### No-think JSON budget test

`think=false` fixed the empty-content problem, but did not make the old full Tool-step planner reliable.

Observed:

```text
Case A / 320: truncated JSON
Case A / 512: still truncated while enumerating unrelated periods/groups
Case B / 320: truncated JSON
Case B / 512: valid JSON but 8 unrelated analytics steps
```

Conclusion: this was not only a token-budget problem.

### Dynamic Tool-step schema test

Dynamic enums made JSON syntactically valid, but semantic planning still failed:

- Case A omitted `party_ledger` and generated invalid compare structure.
- Case B invented `date_from/date_to` and duplicated analytics instead of ledger.

Conclusion: qwen3.5:0.8b should not construct Tool-step objects or arguments.

### Candidate-ID model selection

A refined goal-ID-only task was tested on installed models:

```text
qwen3.5:0.8b
  Case A PASS ~27.8s
  Case B PASS ~12.2s

qwen3:1.7b
  Case A TIMEOUT
  Case B PASS ~57.7s

gemma3:4b
  Case A PASS ~170.7s
  Case B PASS ~57.1s
```

Operational decision:
- keep qwen3.5:0.8b for the constrained planner role
- do not let it emit Tool args
- server builds grounded candidates, expands dependencies, compiles Tool steps, and validates the final workflow
- Gemma remains too slow for this planner path on current CPU
- 1.7B is not reliable enough operationally because Case A timed out

## v8.8.0.4 — Grounded Candidate-ID Planner

Model output contract becomes:

```json
{"goals":["<server-provided-candidate-id>", "..."]}
```

The LLM cannot generate:
- Tool names outside the candidate set
- periods/dates
- DB IDs
- party/item IDs
- financial values
- Tool arguments

Server pipeline:

```text
Prompt
→ deterministic accounting grounding
→ bounded candidate goals
→ LLM candidate-ID selection
→ semantic candidate validator
→ deterministic dependency expansion
→ canonical workflow validator
→ Tools
```

Fallback remains available if candidate selection is rejected.
Repeated real-Ollama tests must pass before repository mutation.
Live validation remains pending.

## v8.8.0.4 Live Validation — Jobs #37 and #38

### Installer / real-Ollama stability gate

Before repository mutation:

```text
Core workflow planner: 30/30 PASS
Actual guard-stack integration: 11/11 PASS
Worker transport: 2/2 PASS
Real Ollama Candidate-ID planner: 6/6 PASS
```

After install + Worker rebuild:

```text
Real Ollama Candidate-ID planner: 6/6 PASS
Worker startup/registration: PASS
```

### Job #37 — canonical compare → rank → same-party ledger request

Prompt:

```text
فروش قطعی این ماه را با ماه قبل مقایسه کن، مشتری برتر این ماه را پیدا کن و مانده همان مشتری را هم بررسی کن.
```

Primary planner path:

```text
workflow_candidate
→ workflow_plan_llm
→ llm_done
→ workflow_plan_validated (5 steps)
→ document_analytics current confirmed
→ document_analytics previous confirmed
→ compare
→ document_analytics current confirmed group_by=party limit=1
→ party_ledger dependency skipped because ranking returned no rows
→ accounting_workflow_partial
```

No:

```text
workflow_plan_rejected
workflow_plan_fallback
workflow_delegate
workflow_json_invalid
```

Live facts:

```text
Shahrivar 1405 confirmed sales: 0 IRR
Mordad 1405 confirmed sales: 1,985,720,000 IRR
change: -100.0%
current-period top party: unavailable because no rows
dependent ledger: safely skipped
model: qwen3.5:0.8b
first output: 1.6s
model time: 7.7s
```

### Job #38 — top previous-month customer → same-party ledger

Prompt:

```text
مشتری برتر فروش قطعی ماه قبل را پیدا کن و مانده همان مشتری را هم بررسی کن.
```

Primary planner path:

```text
workflow_candidate
→ workflow_plan_llm
→ llm_done
→ workflow_plan_validated (2 steps)
→ document_analytics previous confirmed group_by=party limit=1
→ party_ledger(real Tool-derived party_id)
→ accounting_workflow_read
```

No planner rejection/fallback/delegation occurred.

Live facts:

```text
Mordad confirmed sales total: 1,985,720,000 IRR
top party: کارخانه بهین بسته‌بندی
top-party sales: 518,100,000 IRR
current ledger balance: 727,100,000 IRR
model: qwen3.5:0.8b
first output: 1.2s
model time: 5.2s
```

### v8.8 conclusion

The Grounded Candidate-ID Accounting Workflow Planner is `LIVE-VALIDATED` for the canonical dependent read workflows.

The validated boundary is intentionally narrow:
- LLM selects only bounded server-provided goal IDs
- server owns accounting grounding, dependency expansion, Tool arguments, IDs, validation, and execution
- empty real data produces a partial grounded result rather than invented entities
- real Tool-derived party IDs can safely feed later ledger steps
- write/Deep/legacy grounded-read paths remain separate

## v8.9.0 — Accounting Action Orchestrator Live Validation

Starting frozen baseline:

```text
b442fe3b556c32bcea3b40b8bff1b70de76ce4cd
```

Installer validation:

```text
core action tests: 24/24 PASS
actual-like integration: 6/6 PASS
real Ollama action-goal preflight before mutation: 3/3 PASS
real Ollama action-goal after Worker rebuild: 3/3 PASS
full guard stack: PASS
Worker startup/registration: PASS
```

### Job #41 — ambiguity must fail closed

Prompt used generic debit account `بانک`.

The workflow reached:

```text
action_candidate
→ action_plan_llm
→ action_plan_validated
→ search_parties
→ party_ledger
→ debtor condition
→ trial_balance
→ action_blocked
```

Real candidate accounts returned:

```text
10101 بانک ملت - جاری
10102 بانک پاسارگاد - جاری
```

No Proposal was created.

### Job #42 — grounded receipt Proposal

Exact debit account code `10101` was supplied.

Live facts:

```text
party: کارخانه بهین بسته‌بندی
real balance: 727,100,000 IRR
condition: debtor = true
requested amount: 100,000,000 IRR
debit: 10101 بانک ملت - جاری
credit: 11001 حساب‌های دریافتنی تجاری
proposal: #2
```

Proposal payload was balanced:

```text
line 1: debit 100,000,000 / credit 0
line 2: debit 0 / credit 100,000,000
difference: 0
```

The Proposal waited for human approval.

### Human approval / execution

After explicit UI approval, server-side validation/execution created:

```text
voucher: AI-VCH-20260823-193339-D278
date: 1405/06/01
type: general
status: draft
debit: 100,000,000 IRR
credit: 100,000,000 IRR
```

No automatic `approved` or `final` accounting state was created.

### Jobs #43/#44 — post-action verification

Job #43:

```text
party ledger balance (approved/final only): 727,100,000 IRR
```

Job #44:

```text
trial debit: 17,821,580,000 IRR
trial credit: 17,821,580,000 IRR
difference: 0
```

Therefore the newly created draft did not alter approved/final financial facts.

### v8.9 conclusion

The first full controlled accounting action lifecycle is `LIVE-VALIDATED`:

```text
READ
→ CONDITION
→ PROPOSAL
→ HUMAN APPROVAL
→ SERVER EXECUTION AS DRAFT
→ VERIFY
```
