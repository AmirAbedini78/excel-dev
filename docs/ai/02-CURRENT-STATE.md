# 02-CURRENT-STATE — Snapshot زنده پروژه

> این فایل باید بعد از هر Milestone معتبر به‌روزرسانی شود.

## Baseline / Working Milestone

```text
Repository: AmirAbedini78/excel-dev
Branch: main
Deployed source baseline: 2f196868c9f27c719cf0165fd541656a2e5f11d4
Deployed milestone: v9.3.0.1 — Live Observability Hotfix
Validated deployment: GitHub Python/PHP/Node gate + cPanel + no-refresh browser Jobs #50/#51
Latest live evidence: Job #51 — blocked action/model/fail-closed PASS; Proposal zero; Tool names and attempted-model metrics missing
Working milestone: v9.3.0.2 — Safe Attempt Observability Hotfix
Working validation: LOCAL-VALIDATED candidate; CI/cPanel/browser proof pending
```

## Scope فعال

**Accounting/Financial AI MVP**

تمرکز روی تکمیل Workflowهای هوشمند است؛ نه توسعه تمام منوهای حسابداری یا سایر ماژول‌ها.

## Runtime topology

### cPanel / Control Plane

- UI
- Auth / RBAC
- Workspace/Company scope
- MySQL system of record
- AI Job Queue
- Worker registration/token
- Tool Gateway
- Proposal/Approval
- Audit
- SSE + Polling live status

### Local AI Engine

Docker Compose:

- `ollama`
- Python worker

Worker از داخل شبکه با HTTPS به Control Plane وصل می‌شود؛ inbound public API روی PC/Laptop لازم نیست.

## مدل‌های فعلی شناخته‌شده

```text
qwen3.5:0.8b → fast parser / agent planning
gemma3:4b    → deep qualitative enhancer
qwen3:1.7b   → fallback/legacy candidate
```

مدل دقیق یک Contract دائمی محصول نیست و قابل تعویض است.

## Capabilityهای پیاده‌شده و Validation شده

### Foundation
- Job queue / lease
- Heartbeat
- Retry/backoff
- Worker registration
- Token security
- idempotent tool-call design
- realtime trace/latency UI

### Financial read
- company snapshot
- recent sales/purchases
- trial balance
- party ledger
- party/item search
- parameterized sales/purchase analytics
- Jalali period resolution
- current/previous comparisons
- group by party/item/month/status
- semantic scope: all/confirmed/draft/approved/final
- entity-scoped analytics
- multi-intent deterministic reads

### Analysis
- deterministic financial report path
- Safe Deep core
- deep model cannot be trusted as numeric calculator
- factual accounting numbers remain deterministic
- fallback preserves safe report when local deep model fails

### Agent write
- guarded sales invoice proposal
- customer/entity grounding
- item grounding
- quantity/price/tax grounding
- server-side validation
- no LLM-generated ERP IDs
- Proposal → Human Approval → Draft invoice

### Adaptive routing
- exact normalized Prompt → validated read Plan cache
- cached answer is forbidden
- fresh Tools run on every hit
- route contract/versioning
- confidence/feedback lifecycle
- only read plans are learned
- status: FROZEN optimization

### Constrained accounting workflow planner — v8.8
- read-only multi-step workflow planning
- max 8 sequential validated steps
- document analytics + deterministic compare + party ledger dependency
- later steps can consume `party_id`/`item_id` only from earlier Tool results
- no IDs/SQL/financial values may originate from LLM plan
- old deterministic multi-read remains preferred when sufficient
- invalid planner output is rejected; canonical dependency-safe recovery exists for proven patterns
- planner model/metrics are exposed correctly in job metadata

## Live evidence milestones

- Standard deterministic financial report: sub-second class on test data.
- Deep financial path: locally viable but CPU-heavy; Safe Deep architecture retained.
- Guarded invoice flow: real Proposal path validated.
- v8.5 parameterized analytics: live totals/groupings reconciled.
- v8.6 entity/status-scoped reads: live customer/item/combined queries validated.
- v8.7 Job #31: adaptive cache MISS → Qwen plan → grounded `sales_total` → successful result.
- v8.8 package tests: core 19/19 + actual guard-stack integration 7/7 locally validated before repository mutation.
- v8.8 Job #32 live test exposed plan-shape rejection + empty current-month ranking.
- v8.8.0.1 Job #33 validated partial no-data semantics on real cPanel data.
- v8.8.0.1 Job #34 exposed invalid JSON and delegation to old `party_search`.
- v8.8.0.2 Jobs #35/#36 proved partial semantics and real `document_analytics → party_id → party_ledger` execution, but both still rejected the LLM plan.
- Direct Ollama diagnostics showed three distinct planner limits: thinking exhaustion, oversized tool-schema reproduction, and semantic drift when the 0.8B model was allowed to construct tool-step objects.
- Candidate-ID model selection then showed the operational trade-off: qwen3.5:0.8b passed both refined goal-selection cases in ~12–28s; qwen3:1.7b timed out on Case A; gemma3:4b passed but was ~57–171s. v8.8.0.4 therefore keeps 0.8B only as a grounded goal selector while the server owns dependencies and all tool arguments.
- v8.8.0.4 repeated real-Ollama preflight passed 6/6 before mutation and 6/6 after Worker rebuild.
- Live Job #37: direct Candidate-ID LLM plan validated into 5 steps with no `workflow_plan_rejected`/fallback; current confirmed Shahrivar sales were 0 vs Mordad 1,985,720,000 IRR, so ranking returned no rows and the dependent ledger was safely skipped. Route: `accounting_workflow_partial`; model time 7.7s.
- Live Job #38: direct Candidate-ID LLM plan validated into 2 steps (`document_analytics → party_ledger`) with no fallback. Top confirmed-sales customer for Mordad was کارخانه بهین بسته‌بندی at 518,100,000 IRR; the real Tool-derived party dependency produced current balance 727,100,000 IRR. Route: `accounting_workflow_read`; model time 5.2s.
- v8.8 Grounded Candidate-ID Accounting Workflow Planner is therefore `LIVE-VALIDATED` for the two canonical dependent accounting workflows.

### Accounting Action Orchestrator — v8.9.0

The first write/action vertical slice is now Live-proven:

- Job #41: ambiguous debit account phrase `بانک` correctly failed closed after real `trial_balance`, offering real choices `10101 بانک ملت - جاری` and `10102 بانک پاسارگاد - جاری`; no Proposal was created.
- Job #42: exact account code `10101` produced a grounded receipt Proposal after `search_parties → party_ledger → deterministic debtor condition → trial_balance`.
- Real grounded facts for Job #42: کارخانه بهین بسته‌بندی balance `727,100,000 IRR`; requested receipt `100,000,000 IRR`; debit `10101 بانک ملت - جاری`; credit `11001 حساب‌های دریافتنی تجاری`.
- Proposal #2 was generated with two balanced lines: `100,000,000` debit and `100,000,000` credit, using Tool-derived `account_id`/`party_id`.
- Human approval executed the existing server-side validator and created `AI-VCH-20260823-193339-D278` as `general / draft`, totals `100,000,000 / 100,000,000`.
- Job #43 proved approved/final party ledger facts were unaffected: balance remained `727,100,000 IRR`.
- Job #44 proved approved/final trial balance facts were unaffected: debit = credit = `17,821,580,000 IRR`, difference `0`.
- Therefore the first full `READ → CONDITION → PROPOSAL → HUMAN APPROVAL → DRAFT EXECUTION → VERIFY` workflow is `LIVE-VALIDATED`.

### Financial Intelligence Core — v9.0.0 / v9.0.1

The first management-level financial intelligence workflow is now Live-proven:

- Installer v9.0.0: static/compile/core `42/42`, actual-like integration `6/6`, real Ollama bounded priority selector `3/3` before mutation and `3/3` after rebuild, full guard stack PASS.
- Job #45: 10 grounded accounting datasets were read successfully; deterministic metrics/findings were generated; qwen3.5:0.8b prioritized only server-grounded finding IDs; report values reconciled to real accounting data.
- Job #45 exposed one product-quality issue: the model elevated informational `largest_account_balances` above the deterministic purchase-decline warning.
- v9.0.1 added a server-owned severity gate `critical → warning → info`; the model may only influence order inside the same severity tier.
- v9.0.1 regression: v9.0 core `42/42` + management priority hardening `12/12` PASS before/after rebuild.
- Job #46: the real warning `purchases -31.9%` appeared first in management priorities, ahead of informational balances.
- Job #46 model latency improved to ~5.5s with first output ~1.1s after warmup.
- v9.0.1 therefore completes the first `grounded facts → deterministic findings → bounded model prioritization → severity-safe management report` lifecycle as `LIVE-VALIDATED`.

### Forecast / Risk / Anomaly — v9.1.0

The first predictive accounting intelligence workflow is now Live-proven:

- Installer: core `64/64`, actual-like integration `7/7`, real Ollama bounded priority `3/3` before mutation and `3/3` after rebuild, full guard stack PASS.
- Job #47 executed the fixed 9-read predictive plan with no write/proposal tool.
- Current partial Jalali month is excluded from trend training.
- Sales full-month 1405/06 forecast: `2,387,880,000 IRR`, approximate range `1,910,304,000–2,865,456,000 IRR`, low confidence with 3 complete months.
- Purchase full-month 1405/06 forecast: `1,164,533,333 IRR`, approximate range `908,844,444–1,420,222,222 IRR`, low confidence with 3 complete months.
- Confirmed purchase decline `-31.9%` remained management warning #1.
- Customer concentration `26.1%`; vendor concentration `59.4%`; non-final sales exposure `784,300,000 IRR / 14.2%`.
- Report explicitly says the forecast band is an approximate MAE-based planning range, not a formal confidence interval.
- Unsupported net profit, cash-flow, bankruptcy and credit-risk claims are not guessed.
- v9.1.0 is `LIVE-VALIDATED` for the first `forecast → risk/anomaly → severity-safe management report` vertical slice.

### Proactive Accounting Agent — v9.2.0

The first proactive accounting recommendation workflow is now Live-proven:

- Installer: static safety PASS, core `60/60`, actual-like integration `8/8`, full guard stack PASS, real Ollama bounded priority `3/3` pre-install and `3/3` post-rebuild.
- Job #48 executed 9 grounded accounting reads with no Proposal/write tool.
- Server-built next-best actions were prioritized by deterministic severity + impact; Qwen only selected existing recommendation IDs.
- Management priority #1: commercial payables burden ≈ `3.12×` latest complete-month purchases → `critical`.
- Priority #2: commercial receivables burden ≈ `1.68×` latest complete-month sales → `warning`.
- Priority #3: confirmed purchase decline `-31.9%` → `warning`.
- Non-final sales review: `2` documents / `14.2%` of recorded sales amount.
- Forecast-history quality follow-up remains informational because demo history is still short.
- Safe bridge to the existing v8.9 receipt Action Orchestrator is recommendation-only: customer, amount, debit account and credit account must be explicitly supplied by the user; human approval remains mandatory.
- Job #48 explicitly confirmed `proposal_created=false`; no voucher/payment/receipt/invoice was created.
- v9.2.0 therefore completes the first `proactive grounded review → next-best-action ranking → safe human-controlled action handoff` lifecycle as `LIVE-VALIDATED`.

### Commercial MVP Hardening — v9.3.0.1 deployed / v9.3.0.2 hotfix

Status: `LOCAL-VALIDATED`

- permanent dependency-free regression/release suite added under `tests/` + `scripts/release_gate.py`;
- GitHub CI release gate added with mandatory PHP lint;
- last Worker guard adds end-to-end latency budgets, actual Tool attempts, attempted-model correction, secret redaction and fail-closed route contracts;
- generic LLM Tool Agent is read-only; Proposal descriptors are available only to dedicated grounded write guards؛
- remote Control Plane requires HTTPS; placeholder/invalid Worker tokens fail before registration;
- Proposal idempotency is atomic under concurrent retry with `ON DUPLICATE KEY ... LAST_INSERT_ID`;
- `complete/fail` response-loss retry is idempotently acknowledged for 24 hours without duplicate terminal side effects;
- request correlation and server error redaction added to `ai_api.php`;
- server-rendered AI UI localizes all proven routes and shows total latency/budget/risk؛
- detailed permission/risk/recovery/security/demo/release contract is canonical in `09-COMMERCIAL-MVP-RELEASE.md`.

Commit `27e34a9` passed the original GitHub release gate، PHP lint، cPanel deployment and rebuilt Worker registration. Live Job #49 completed a fixed 9-read forecast/risk plan, ended with `commercial_hardening_complete`, created no Proposal/write and kept the response Grounded.

Job #49 also exposed a release-blocking browser parity gap: the authenticated live endpoint did not include `commercial_hardening`, and the SSE/Polling renderer still used the old v8 route/stage map. Therefore the no-refresh result omitted total latency/budget/risk and showed raw route/stage codes. v9.3.0.1 fixes the endpoint payload, both live transports, current route/stage localization, asset cache-busting and mandatory JavaScript syntax validation.

Commit `2f19686` passed the expanded Python/PHP/Node GitHub gate and was deployed. Job #50 proved the read terminal contract without refresh: localized forecast route/stages، model metrics، `47.5s` end-to-end، visible exceeded SLO and low risk. Job #51 then proved blocked action fail-closed on ambiguous `بانک`: attempted model `qwen3.5:0.8b` remained visible، action budget passed at `24.7s`، risk was high and Proposal remained zero.

Job #51 also exposed the final presentation gap: the commercial wrapper had already persisted bounded `tools_used/tools_attempted` and `attempted_metrics`, but the authenticated live payload and both renderers did not show them. v9.3.0.2 exposes only normalized names (`[a-z][a-z0-9_]{0,79}`، unique، max 32) and six allowlisted numeric metric fields; it never exposes Tool arguments/results/call IDs. Worker، financial logic and schema remain unchanged.

No financial feature, forecast formula, accounting number, Tool capability or auto-execution scope was added in v9.3 or this hotfix.



Live grounded facts from Job #46:
- confirmed sales: 1405/04 `1,570,360,000 IRR` → 1405/05 `1,985,720,000 IRR` (`+26.4%`)
- confirmed purchases: 1405/04 `2,151,600,000 IRR` → 1405/05 `1,466,300,000 IRR` (`-31.9%`, warning)
- top customer concentration: کارخانه بهین بسته‌بندی `26.1%`
- top vendor concentration: ابزار دقیق سپهر `59.4%`
- non-final sales exposure: `2` docs / `784,300,000 IRR` / `14.2%`
- trial balance: debit = credit = `17,821,580,000 IRR`, difference `0`


The blocked-action model/fail-closed half is live-proven by Job #51. Attempt telemetry remains open until v9.3.0.2 is deployed and the same prompt shows `search_parties`، `party_ledger`، `trial_balance` plus first-output/model timing in both live and reload metadata.

## Known non-blocking issues

1. Adaptive exact-prompt cache is **FROZEN** as an optimization; no large dictionary/template project now.
2. Old root docs under `docs/*.md` contain an earlier broader roadmap and must not override this SmartDocs set.
3. Full accounting application completeness is intentionally deferred until AI MVP is proven.
4. v9.3.0.1 CI/cPanel/read UI پاس است؛ v9.3.0.2 safe attempt parity باید CI و cPanel/browser را پاس کند.
5. تا تکمیل hotfix و همه checklist سند 09، v9.3 نباید `LIVE-VALIDATED` نامیده شود.
6. در Workflow وابسته، نبود داده برای رتبه‌بندی باید `partial` برگرداند و نتایج معتبر مراحل قبلی را دور نریزد.

## Not yet implemented as production capability

- General financial Action Orchestrator
- Risk-based auto-execution matrix
- statistically backtested production forecasting beyond the current deterministic baseline
- collections outcome prediction and learned policy
- learned proactive ranking
- production RAG corpus and retrieval evaluation
- full accounting application completeness
