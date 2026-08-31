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

## v9.0.0 / v9.0.1 — Financial Intelligence Live Validation

Frozen baseline:

```text
6f5d6c4b8400a8df023011896ff204e0c3c28b09
```

### v9.0.0 installer

```text
core financial intelligence: 42/42 PASS
actual-like integration: 6/6 PASS
real Ollama priority selector pre-install: 3/3 PASS
real Ollama priority selector post-rebuild: 3/3 PASS
full guard stack: PASS
Worker registration: PASS
```

### Job #45

The first real management request executed:

```text
financial_intelligence_candidate
→ 10 grounded financial datasets
→ financial_intelligence_llm
→ financial_intelligence_prioritized
→ financial_intelligence_complete
```

Grounded facts:

```text
sales 1405/04 → 1405/05:
1,570,360,000 → 1,985,720,000 IRR
change: +26.4%

purchases 1405/04 → 1405/05:
2,151,600,000 → 1,466,300,000 IRR
change: -31.9%

top customer: کارخانه بهین بسته‌بندی
share: 26.1%

top vendor: ابزار دقیق سپهر
share: 59.4%

non-final sales:
2 docs
784,300,000 IRR
14.2%

trial:
17,821,580,000 debit
17,821,580,000 credit
difference 0
```

Job #45 exposed a product-quality gap: the LLM chose informational largest balances as the only primary management priority while a deterministic purchase-decline warning existed.

### v9.0.1 management priority hardening

A server-owned severity gate was added:

```text
critical
→ warning
→ info
```

Regression:

```text
v9.0 core: 42/42 PASS
priority hardening: 12/12 PASS
built Worker regression: PASS
```

### Job #46

The same live prompt confirmed the hardening:

```text
priority #1:
[warning] confirmed purchases -31.9%

then informational findings:
largest balances
trial balanced
sales +26.4%
customer concentration 26.1%
```

Model:
```text
qwen3.5:0.8b
first output: ~1.1s
model time: ~5.5s
```

### v9.0 conclusion

Financial Intelligence is `LIVE-VALIDATED`:

```text
grounded facts
→ deterministic metrics
→ deterministic findings
→ bounded LLM priority
→ deterministic severity gate
→ management report
```

## v9.1.0 — Forecast / Risk / Anomaly Live Validation

Frozen baseline:

```text
2c32c3bf7316bb29c206ccbbc0f69cd4b9ba406c
```

Installer:

```text
core: 64/64 PASS
actual-like integration: 7/7 PASS
candidate full guard stack: PASS
real Ollama pre-install: 3/3 PASS
Docker rebuild + full compile: PASS
rebuilt core/integration: 64/64 + 7/7 PASS
real Ollama post-rebuild: 3/3 PASS
Worker startup/registration: PASS
```

Job #47:

```text
forecast_risk_candidate
→ forecast_risk_tool × 9
→ forecast_risk_llm
→ llm_done
→ forecast_risk_prioritized
→ forecast_risk_complete
```

Live outputs:

```text
1405/06 sales forecast:
2,387,880,000 IRR
approx range: 1,910,304,000–2,865,456,000 IRR
confidence: low / 3 complete months

1405/06 purchase forecast:
1,164,533,333 IRR
approx range: 908,844,444–1,420,222,222 IRR
confidence: low / 3 complete months

purchase warning:
1405/04 2,151,600,000 → 1405/05 1,466,300,000 IRR
-31.9%

customer concentration: 26.1%
vendor concentration: 59.4%
non-final sales: 784,300,000 IRR / 14.2%
```

Model:

```text
qwen3.5:0.8b
first output: ~14.0s
model time: ~24.6s
```

Conclusion:

```text
v9.1.0 Forecast / Risk / Anomaly = LIVE-VALIDATED
```

## v9.2.0 — Proactive Accounting Agent Live Validation

Frozen baseline:

```text
55437edddaf464dea969a556b362037ac6fbae11
```

Installer:

```text
static proactive accounting safety: PASS
candidate compile: PASS
core: 60/60 PASS
actual-like integration: 8/8 PASS
candidate full guard stack: PASS
real Ollama pre-install: 3/3 PASS
Docker rebuild + full compile: PASS
rebuilt core/integration: 60/60 + 8/8 PASS
real Ollama post-rebuild: 3/3 PASS
Worker startup/registration: PASS
exact 3-file runtime set retained
```

Job #48:

```text
proactive_candidate
→ proactive_tool × 9
→ proactive_recommendations_built
→ proactive_llm
→ llm_done
→ proactive_prioritized
→ proactive_complete
```

Live management priorities:

```text
1. [critical] review commercial payables schedule
   4.5795B / 1.4663B ≈ 3.12× latest complete-month purchases

2. [warning] prioritize receivables collection review
   3.33088B / 1.98572B ≈ 1.68× latest complete-month sales

3. [warning] investigate confirmed purchase decline
   -31.9%

4. [info] review 2 non-final sales documents
   14.2%

5. [info] collect more complete-month history for Forecast
```

Safe action bridge:

```text
existing bridge: v8.9 receipt action orchestrator
required explicit inputs:
customer
amount_rial
debit_account
credit_account

human approval required: true
proposal_created by proactive path: false
```

Model:

```text
qwen3.5:0.8b
first output: ~9.9s
model time: ~16.7s
```

Conclusion:

```text
v9.2.0 Proactive Accounting Agent = LIVE-VALIDATED
```

## v9.3.0 — Commercial MVP Hardening candidate

Frozen input baseline:

```text
a9a8c0259f4e7eaca248f9d9a912817fd1e23c92
v9.2.0 LIVE-VALIDATED — Job #48
```

Architecture/code review found two release-blocking retry races and one observability gap:

```text
1. Proposal SELECT-then-INSERT could race under concurrent identical requests.
2. complete response could be committed then lost; retry saw lease_invalid and Worker reported false failure.
3. blocked v8.9 action could hide the actually attempted model/tools in final metadata.
```

v9.3 candidate changes:

```text
atomic Proposal upsert → same Proposal ID on concurrent retry
24h idempotent terminal replay → no second side effect/counter decrement
X-AI-Request-ID → correlated retries/errors
secret-redacted persisted metadata/trace
last commercial guard → read/proposal invariants + risk + end-to-end latency budget
generic LLM tool loop → read-only descriptors; dedicated grounded guards own Proposal calls
permanent tests + dependency-free release gate + GitHub CI/PHP lint
localized route/risk/Proposal/latency UX
```

Validation level at this snapshot:

```text
LOCAL-VALIDATED
PHP lint/CI: pending outside local builder
cPanel deploy: pending
rebuilt Worker live jobs: pending
```

No accounting Feature, numeric formula, direct mutation or auto-approval was added.

## v9.3.0 deployment and Job #49 live observability incident

Commit/deploy evidence:

```text
commit: 27e34a9af3d1ca05a2b25f5aa2b60a94a86a369c
GitHub Commercial MVP Gate: PASS
mandatory PHP lint: PASS
cPanel deployed HEAD: PASS
Docker Worker rebuild/config/guard bootstrap/registration: PASS
```

Job #49 used the broad financial prompt with explicit risk language, so the existing router correctly selected `forecast_risk_anomaly`. The Worker executed 9 Grounded predictive reads, returned a deterministic forecast/risk report, created no Proposal or mutation, and traced `commercial_hardening_complete` before terminal success.

The live page nevertheless showed raw `forecast_risk_anomaly` and omitted total end-to-end time, latency budget status and route risk. Source inspection confirmed a two-sided presentation defect:

```text
persisted terminal result_json: commercial_hardening present
liveJobStateForUser: commercial_hardening omitted
ai-live.js: old v8 route map; no hardening renderer
PHP reload renderer: new metadata supported
```

Safety and financial correctness passed, but the Commercial no-refresh observability gate failed. v9.3.0 therefore remains live-validation-in-progress.

## v9.3.0.1 — Live Observability Hotfix candidate

- authenticated live payload now exposes only the already-redacted `commercial_hardening` object with existing mode/model/metrics؛
- SSE and Polling use one terminal metrics renderer including total time, budget status and risk؛
- all current guard-stack route/stage codes have Persian labels؛
- asset version changed to `9.3.0.1` to invalidate stale browser cache؛
- CI/release gate now requires Node JavaScript syntax checking؛
- Worker/financial logic/schema remain unchanged, so no Worker rebuild or migration is required.

Candidate validation:

```text
Python unit/contract suite: 37/37 PASS
JavaScript syntax: 6/6 PASS
secret scan/Python/JSON: PASS
PHP lint + GitHub CI + cPanel/browser live proof: pending package install
```

## v9.3.0.1 deployment — Jobs #50/#51

Commit `2f196868c9f27c719cf0165fd541656a2e5f11d4` passed the GitHub Python/PHP/Node gate and was deployed to cPanel without a Worker rebuild.

Job #50 repeated the Job #49 forecast prompt without refresh and proved the hotfix:

```text
route: localized forecast/risk
model: qwen3.5:0.8b
end-to-end: 47.5s
read_model budget: exceeded but visible
risk: low
commercial_hardening_complete
Proposal/write: zero
```

Job #51 repeated the known ambiguous receipt action:

```text
accounting_action_blocked
model attempted: qwen3.5:0.8b
end-to-end: 24.7s
action budget: within budget
risk: high
real account choices: 10101 / 10102
Proposal: zero
```

The financial/safety/model-name gate passed, but the UI still showed generic `action_read` labels rather than persisted actual Tool names and omitted first-output/model-time metrics. Static inspection confirmed `tools_used/tools_attempted` and `attempted_metrics` existed in terminal metadata but were omitted by `liveJobStateForUser` and both renderers.

## v9.3.0.2 — Safe Attempt Observability Hotfix

- authenticated live payload exposes bounded Tool-name arrays only؛
- blocked/fallback model metrics use a six-field numeric allowlist and fall back to persisted `attempted_metrics`؛
- server filter: lowercase identifier، max 80 characters، unique، max 32؛
- shared SSE/Polling renderer and PHP reload renderer show attempted/successful names؛
- arguments، results and call IDs remain hidden؛
- cache-busted asset `9.3.0.2`؛
- no Worker، financial، routing or schema change.

Candidate validation:

```text
Python/contract suite: 39/39 PASS
PHP lint: 53/53 PASS + live observability behavior PASS
JavaScript syntax: 6/6 PASS
server Tool-name boundary behavior: PASS
live Tool renderer behavior: PASS
secret/Python/JSON gates: PASS
```

## v10.0 kickoff — Modular Pilot Platform

2026-08-27: کاربر پس از v9.3 Feature Freeze به‌صورت صریح North Star را گسترش داد. هدف جدید، ERPSMART به‌عنوان Modular AI-Native Business Operations Platform با Vertical اول Finance/Trade است. Strategy پذیرفته‌شده: Wide Platform / Deep Modules؛ Module Kernel و Model Provider Gateway به‌عنوان foundation، سپس Finance action depth و Inventory/Procurement/CRM-lite/Trade slices برای Design Partner Pilot.

## v10.0 Cycle 2 — Model Provider Gateway

After Module Kernel live validation, ERPSMART removed the hard runtime dependency on Ollama as the only possible LLM transport. `provider_gateway.py` adds local/cloud strategy selection, OpenAI-compatible Chat Completions, tool-call/message normalization, structured-output compatibility fallback, non-secret provider observability, and a cloud-only second-worker deployment template. Existing runtime config remains local and unchanged. Six provider contract tests passed before delivery; live product Ollama smoke remains the acceptance step after Worker rebuild.

## v10 Job #56 — semantic correctness + latency closure

Exact Job #55 prompt repeated after deterministic party-balance routing hotfix: `grounded_read`, no LLM, tools `search_parties, party_ledger`, result 727,100,000 IRR, total 1.0s, budget PASS. This closed Cycle 2 local live acceptance and moved work to Finance action depth.

## v10.1 Cycle 4 — Inventory + Procurement vertical slice

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`. Shared `InventoryDomain` now connects existing purchase documents to expected inbound, warehouse receipt/inspection, Stock Ledger, on-hand/reserved/available and replenishment reads. Risky receipt posting remains Proposal → Human Approval. See `14-INVENTORY-PROCUREMENT-MVP.md`. Context Picker / Entity Chips stays in committed UX backlog and will attach server-resolved page entities after the Golden Flow pages stabilize.

## v10.2 Cycle 5 — Trade Logistics + Landed Cost

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`. Cycle 4 is now `LIVE E2E PASS` through Job #70 and receipt `RCV-20260829-024216-D32F`. Cycle 5 adds Trade Case → Shipment → ETA/Customs → Estimated/Actual Trade Costs → deterministic Landed Cost allocation → inventory valuation bridge. AI mutations remain Proposal → Human Approval. See `15-TRADE-LOGISTICS-LANDED-COST.md`.

## 2026-08-29 — Cycle 5 LIVE closed
- `TRD-20260829-171042-90E6` created after Proposal #8.
- `SHP-20260829-192303-3C8C` created after Proposal #9.
- Estimated freight Proposal #10 produced Projected Landed 600,000,000 IRR.
- Actual freight Proposal #11 replaced estimate by type and produced Projected/Actual Recorded 620,000,000 IRR; PLC Projected Unit 310,000,000 IRR.
- Customs hold was grounded as high risk by Job #76.
- Keep-alive commit `12c9000dba8bcafb42829176f8bbf232338ff78f` moved deterministic risk/landed reads from 5.6s/11.0s to 0.2s/0.3s in Jobs #77/#78.
- Cycle 6 source work starts from this exact baseline.

## 2026-08-30 — Cycle 6 LIVE closed
- Job #79 proved the deterministic Trade/Inventory/Sales Manager Brief at 0.2s with no LLM.
- Job #81 proved Sales fulfillment on `AI-SAL-20260820-234534-4E5F`.
- Job #82 showed `SENSOR-PROX` had zero stock; the all-outstanding reservation route was therefore product-incomplete for partial fulfillment.
- Engine-only selective reservation hotfix was live-tested, then committed as `1638c458ec0b1390587b1ffb7ffd91512fe0ac6d`.
- Job #83 created Proposal #12 containing only PLC sales line 28 / quantity 2; human approval reserved exactly those two units.
- Job #84 created Proposal #13 containing only the active PLC reservation; human approval posted delivery `DLV-20260830-163108-188D`.
- Sales UI showed revenue ex-tax 370,000,000 IRR, COGS 620,000,000 IRR, gross margin -250,000,000 IRR, -67.6%, basis `actual_landed`.
- Job #85 verified PLC on_hand/reserved/available all zero after delivery.
- Job #86 verified PLC delivered 2/outstanding 0 and SENSOR-PROX outstanding 4.
- Job #87 independently grounded the same actual-landed margin and passed the deterministic latency budget at 4.7s.
- Cycle 6 is `LIVE E2E CLOSED`; next product slice is CRM-lite / Customer 360.

## 2026-08-30 — Cycle 7 source audit / candidate
- baseline `c426aaf171faae3737928ccbea25883eeae3929a` verified.
- `acc_parties` is canonical customer identity.
- Workbench `phonebook_entries` is not reused as CRM identity.
- no existing CRM Contact/Opportunity/Activity schema was found.
- v10.4 candidate adds CRM-lite and live validation is pending.

## 2026-08-31 — Cycle 7 live closeout

- Commit `27b3b31dad821fa1a88f4eb0fa1d2b6a5519471a` closed CRM-lite documentation after full Cycle 7 live validation.
- Jobs #88–#93 proved canonical `acc_parties` Customer 360, CRM Activity/Follow-up, Opportunity/Pipeline and re-read without changing Finance/Sales truth.
- Cycle 7 became `LIVE-VALIDATED`.

## 2026-08-31 — Cycle 8 r1 Context Kernel

- Commit `338e13419d091e6e1d3a5e7fd836ac7296e88e6b` added typed CRM page refs, `AiPageContext` server validation, `ai_jobs.context_json` persistence and Worker deterministic context consumption.
- Local candidate installation passed full regression `124/124` plus focused CRM/context tests and rebuilt/restarted the Worker.
- The runtime/PHP changes were committed/pushed and deployed to cPanel.
- Before executing the originally planned live context prompts, product review rejected the forced navigation flow `Customer 360 → Ask AI → dedicated AI page` as too narrow and not the intended universal assistant experience.
- Decision: retain the Context Kernel primitive; retire the page-jump UX; cancel the old Cycle 8 product live gate.

## 2026-08-31 — Intelligence Platform / Universal Business Copilot architecture lock

Product direction was clarified from `ERP + AI` to:

```text
ERPSMART Intelligence Platform
+ ERPSMART Business Copilot
```

Key accepted decisions:

- global persistent Sidecar instead of per-page Ask-AI navigation;
- `@` Universal Entity mention/search and multi-entity context;
- Universal Entity Registry + Context Envelope, with server-owned authority;
- Role-Adaptive Experience separated from RBAC;
- Intelligent Home focused on exceptions/work items;
- Tool Registry + Skill Registry + constrained Workflow Grammar;
- single Supervisor first; Multi-Agent only after eval evidence;
- trace/eval/controlled Skill promotion instead of raw online self-learning;
- P0/P1/P2 scope and two-day presentable increments;
- v9.3 security/approval/idempotency invariants remain frozen.

Source/package audit confirmed the supplied source ZIP and GitHub `main` at `338e134`; Cycle 4→8 patch baselines align with commit history; an independent local execution of the Python suite passed `124/124`.

Canonical specs created:

- `19-ERPSMART-INTELLIGENCE-PLATFORM-MASTER-SPEC.md`
- `20-UNIVERSAL-BUSINESS-COPILOT-48H-MVP.md`
