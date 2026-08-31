# 04-ROADMAP — Roadmap Canonical فعلی

> v9.3 Accounting/Financial AI به‌عنوان هسته اثبات‌شده `FROZEN` است.
> از v10 Roadmap روی **Wide Platform / Deep Modules** و اولین Vertical تجاری Finance/Trade قفل می‌شود.

## v10 — Modular Pilot Platform → Intelligence Platform

Status: `IMPLEMENTED` foundation; next product layer `PLANNED`.

The original Finance/Trade Golden Flow has now produced live-proven slices through Cycle 7. The next roadmap does not abandon that vertical; it changes how users interact with and compose those capabilities.

Current product direction:

```text
ERPSMART Intelligence Platform
+ ERPSMART Business Copilot
```

Current source baseline: `338e13419d091e6e1d3a5e7fd836ac7296e88e6b`.

### Immediate two-day cadence

| Window | Presentable increment | Primary result |
|---|---|---|
| D0–D2 | MVP A — Universal Copilot Foundation | Global Sidecar + `@` Entity Registry/Search + persistent context + Customer Business Review |
| D2–D4 | MVP B — Skills & Capability Retrieval | `/` picker + multi-entity compare + Supplier Review + Shipment Risk |
| D4–D6 | MVP C — Role Brief / Intelligent Home | CEO vs Commercial experience + Executive Brief + trace/feedback |
| D6–D8 | MVP D — Guarded Operator | Proposal/Approval cards inside Sidecar + verify/audit |
| D8–D10 | MVP E — Proactive pilot | first deterministic Watchers + in-app work items |
| D10–D12 | MVP F — Analysis Workspace | large rich analysis + saved Skill pilot |

Every window must remain demoable and preserve the full prior regression/safety contracts. Detailed DoD is canonical in `20-UNIVERSAL-BUSINESS-COPILOT-48H-MVP.md`.

### Stop/learn gate

After the coherent loop `Role Home → Sidecar/@ → Cross-module analysis → guarded action → verified outcome` is live-proven, next depth is prioritized from Design Partner evidence. Graph DB, heavy RAG, multi-agent, fine-tuning and broad module expansion do not enter the critical path without evidence.

## Completed foundation

### Foundation / Phase 0
Status: `LIVE-VALIDATED`

- cPanel Control Plane
- local Docker Worker
- Ollama
- queue/lease/heartbeat
- Tool Gateway
- Proposal/Approval/Audit
- realtime status
- synthetic accounting demo data

### v7.x — Fast financial path
Status: `LIVE-VALIDATED`

هدف: اثبات جداسازی read/report path از tool-heavy LLM path روی CPU ضعیف.

### v8.0 — Realtime observability
Status: `LIVE-VALIDATED`

- SSE/Polling
- trace
- latency metrics
- model progress

### v8.2C.4.2 — Safe Deep Core
Status: `LIVE-VALIDATED`

- deterministic facts
- safe qualitative enhancement
- timeout/fallback
- جلوگیری از جعل عدد/نتیجه مالی

### v8.3 — Guarded Invoice Agent
Status: `LIVE-VALIDATED`

- natural-language invoice intent
- entity/item grounding
- no generated IDs
- Proposal/Approval

### v8.4 — Grounded Read Agent
Status: `LIVE-VALIDATED`

- grounded general accounting reads
- deterministic answers

### v8.5 — Parameterized Query Engine
Status: `LIVE-VALIDATED`

- period
- status
- grouping
- comparison
- multi-intent

### v8.6 — Semantic & Entity-Scoped Analytics
Status: `LIVE-VALIDATED`

- customer scope
- item scope
- combined customer+item
- confirmed/draft/final semantics
- rolling periods

### v8.7 — Adaptive Semantic Router
Status: `IMPLEMENTED / FROZEN`

- read-plan cache only
- fresh financial queries on every run
- exact normalized route v1
- optimization, not a product direction

## Next core phases

### v8.8 / v8.8.0.1 / v8.8.0.2 / v8.8.0.3 / v8.8.0.4 — Accounting Constrained Workflow Planner
Status: `LIVE-VALIDATED`

هدف: یک Prompt مالی چندمرحله‌ای را به Plan قابل اعتبارسنجی تبدیل کند.

Scope:
- Read-only first
- dependencies between steps
- step outputs referenced by later steps
- server IDs only from Tool results
- deterministic calculations between results
- optional LLM summary
- planner observability
- fix adaptive planner model display bug

مثال:

```text
فروش قطعی این ماه را با ماه قبل مقایسه کن،
مشتری برتر را پیدا کن،
مانده همان مشتری را هم بررسی کن.
```

Plan:

```text
1 document_analytics current confirmed
2 document_analytics previous confirmed
3 compare step1/step2
4 document_analytics current confirmed group_by party limit 1
5 party_ledger party_from step4
6 grounded summary
```

Implemented contract:
- Read-only, max 8 sequential steps
- `document_analytics`
- deterministic `compare`
- `party_ledger`
- `party_from` / `item_from` references to prior grouped Tool results
- server-produced IDs only
- old deterministic fast paths preserved
- planner model/metrics observability fixed
- Ollama planner call uses structured JSON output
- planner call explicitly disables Qwen thinking
- v8.8.0.4 no longer asks the model to construct Tool-step objects or arguments
- deterministic grounding builds a bounded candidate goal set
- LLM selects only candidate goal IDs through JSON Schema enums
- server expands goal dependencies and owns every Tool argument/date/period/ID
- harmless internal planner-shape drift → safe canonicalization
- invalid/unsafe LLM plan → reject; limited deterministic dependency-safe recovery
- empty dependent ranking → partial grounded answer, not loss of prior valid results

Local success gate:
- v8.8.0.4 core planner tests 30/30
- v8.8.0.4 actual guard-stack integration 11/11
- actual Worker transport tests 2/2
- repeated real-Ollama candidate-plan gate is required before mutation
- worker think-override patch LF/CRLF + reapply rejection
- no invented IDs/numbers
- no direct SQL
- deterministic dependency resolution
- v8.3/v8.6/v8.7 paths preserved

Live gate completed:
- repeated real-Ollama Candidate-ID preflight: 6/6 before mutation + 6/6 after rebuild
- Job #37: direct LLM plan → 5-step validated workflow; safe partial result on empty current-month ranking
- Job #38: direct LLM plan → 2-step `document_analytics → party_ledger`; Tool-derived party dependency reconciled
- no planner rejection/fallback/delegation in Jobs #37/#38
- no hidden write; read-only contract preserved
- observed model times: ~7.7s (Job #37) and ~5.2s (Job #38)

### v8.9 — Accounting Action Orchestrator
Status: `LIVE-VALIDATED`

Validated first vertical slice:

```text
named customer
→ search_parties
→ party_ledger
→ deterministic condition: debtor?
→ trial_balance account resolution
→ balanced create_voucher_draft Proposal
→ human approval
→ server-side validation
→ draft voucher
→ post-action grounded verification
```

Live evidence:
- Job #41: ambiguous bank account failed closed; no Proposal.
- Job #42: Proposal #2 created from real customer/account IDs and 100,000,000 IRR grounded amount.
- Human approval created `AI-VCH-20260823-193339-D278`, status `draft`, debit = credit = 100,000,000 IRR.
- Job #43: confirmed/final party balance stayed 727,100,000 IRR.
- Job #44: confirmed/final trial balance stayed 17,821,580,000 / 17,821,580,000 IRR, difference 0.
- no automatic approval/finalization is allowed by the v8.9 Worker.

### v9.0 — Financial Intelligence Core
Status: `LIVE-VALIDATED` via v9.0.1 hardening

Validated architecture:

```text
grounded server datasets
→ deterministic financial metrics
→ deterministic findings
→ bounded qwen finding-ID prioritization
→ deterministic severity gate
→ management financial intelligence report
```

Live evidence:
- Job #45: first 10-dataset management report successfully grounded and rendered.
- v9.0.1: deterministic severity gate prevents Info findings from outranking Warning/Critical findings.
- Job #46: `-31.9%` confirmed purchase decline surfaced as management priority #1.
- no write/proposal path exists in Financial Intelligence Core.
- current partial Jalali month is excluded from primary monthly trend comparison.
- unsupported profit/cash-flow metrics are explicitly not guessed.

### v9.1 — Forecast / Risk / Anomaly
Status: `LIVE-VALIDATED`

Validated architecture:

```text
grounded accounting time series
→ exclude incomplete current Jalali month
→ deterministic full-month forecast
→ approximate error/planning range
→ deterministic risk/anomaly findings
→ bounded Qwen finding-ID priority
→ critical > warning > info gate
→ management predictive report
```

Live evidence:
- Job #47: fixed 9-read predictive plan completed successfully.
- sales 1405/06 forecast: 2,387,880,000 IRR; approximate range 1,910,304,000–2,865,456,000 IRR; low confidence.
- purchases 1405/06 forecast: 1,164,533,333 IRR; approximate range 908,844,444–1,420,222,222 IRR; low confidence.
- confirmed purchase decline -31.9% remained warning priority #1.
- unsupported profit/cash-flow/bankruptcy/credit-risk claims are not generated.

### v9.2 — Proactive Accounting Agent
Status: `LIVE-VALIDATED`

Validated architecture:

```text
grounded accounting datasets
→ reuse deterministic forecast/risk findings
→ deterministic AR/AP burden heuristics
→ server-built next-best-action candidates
→ bounded Qwen recommendation-ID priority
→ deterministic severity + impact gate
→ recommendation-only safe action bridge
```

Live evidence:
- Job #48: fixed 9-read proactive review completed successfully.
- commercial payables burden ≈ 3.12× latest complete-month purchases → critical.
- commercial receivables burden ≈ 1.68× latest complete-month sales → warning.
- confirmed purchase decline -31.9% → warning.
- non-final sales review 14.2% → informational.
- no Proposal, voucher, payment, receipt or invoice was created.
- bridge to v8.9 receipt Action requires explicit customer/amount/accounts + human approval.

Out of scope for v9.2:
- autonomous financial mutation
- model-generated account/party IDs or amounts
- background auto-execution without a user action/approval boundary

### v9.3 — Commercial MVP Hardening
Status: `LIVE-VALIDATED / FEATURE FROZEN`

- permanent end-to-end regression/release suite + GitHub CI
- canonical permission/risk matrix
- atomic Proposal idempotency under concurrent retry
- idempotent complete/fail response-loss recovery
- route-class performance/latency budgets
- actual Tool/model/request observability
- metadata/trace secret redaction + remote HTTPS guard
- Proposal/mode/risk/latency UX polish
- rollback, demo and live release checklist

Deployment `27e34a9`، v9.3.0.1 (`2f19686`) and v9.3.0.2 (`7e1c7c5`) completed the release/observability hardening. Job #50 proved read metadata and latency UI; Job #53 proved blocked-action model/Tool/metrics parity with Proposal zero. Job #54 produced grounded Proposal #3; human approval created balanced draft `AI-VCH-20260826-202025-9F19`; commit `448fca0` added the missing Voucher detail UI and the product UI verified both stored articles and zero difference.

No new accounting/AI feature is part of the closeout. v9.3 is now feature-frozen. Post-freeze work is RC/demo/market/customer/pricing/positioning/GTM; any new core feature requires a new milestone decision.

## Post-MVP — فقط بعد از تصمیم جداگانه

- ساخت Accounting Application کامل‌تر
- تکمیل کامل GL/AP/AR/Inventory/Treasury/Payroll/Assets/Tax/Production
- Practice OS expansion
- AI روی ماژول‌های غیرمالی
- Builder/general ERP capabilities
- cloud/GPU scaling
- advanced RAG stack
- multi-agent

این بخش‌ها Vision هستند، نه Scope فعلی.

### v10 execution status — 2026-08-27
- Module Kernel v1 + Module Center: `LIVE-VALIDATED` in product UI.
- Model Provider Gateway v1: `LOCAL-VALIDATED / LIVE-VALIDATION-PENDING`.
- Next immediate work after one local-Ollama smoke: Finance form/action coverage matrix and highest-value missing Agent actions.

## v10 Golden Flow lock — Trade Resilience MVP

After the Finance candidate live gate, implementation order is: (1) Inventory + Procurement primitive, (2) Trade Case/Shipment + estimated/actual landed cost, (3) warehouse receipt/inspection + inventory valuation bridge, (4) Sales/Delivery integration, (5) cross-module proactive Manager Brief. Finance remains the accounting truth layer; it is no longer the only product narrative. See `13-TRADE-FLOW-MVP.md`.

## v10.1 Cycle 4 — Inventory + Procurement vertical slice

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`. Shared `InventoryDomain` now connects existing purchase documents to expected inbound, warehouse receipt/inspection, Stock Ledger, on-hand/reserved/available and replenishment reads. Risky receipt posting remains Proposal → Human Approval. See `14-INVENTORY-PROCUREMENT-MVP.md`. Context Picker / Entity Chips stays in committed UX backlog and will attach server-resolved page entities after the Golden Flow pages stabilize.

## v10.2 Cycle 5 — Trade Logistics + Landed Cost

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`. Cycle 4 is now `LIVE E2E PASS` through Job #70 and receipt `RCV-20260829-024216-D32F`. Cycle 5 adds Trade Case → Shipment → ETA/Customs → Estimated/Actual Trade Costs → deterministic Landed Cost allocation → inventory valuation bridge. AI mutations remain Proposal → Human Approval. See `15-TRADE-LOGISTICS-LANDED-COST.md`.

## Cycle 6 — Sales Fulfillment + Margin — LIVE E2E CLOSED
1. Existing Sales document → selective warehouse reservation: PASS (Job #83 / Proposal #12).
2. Reservation → posted delivery/outbound Stock Ledger: PASS (Job #84 / Proposal #13 / `DLV-20260830-163108-188D`).
3. Delivered revenue excluding tax → actual-landed COGS → gross margin: PASS (Job #87).
4. Cross-module deterministic Manager Brief across Trade, Inventory and Sales: PASS (Job #79).
5. Independent inventory + fulfillment verification: PASS (Jobs #85/#86).

## Cycle 7 — CRM-lite / Customer 360 — LIVE E2E CLOSED
1. Canonical customer identity remains `acc_parties`; no parallel CRM customer master.
2. Customer 360 joins Sales, fulfillment, receivable/party-ledger truth and CRM process data.
3. Contact, Activity/Follow-up and Opportunity/Pipeline are additive CRM process records.
4. AI Activity/Opportunity writes remain Proposal → Human Approval → server execution.
5. Grounded Customer 360, Follow-up Queue and Pipeline reads were independently reconciled after live writes.
6. Independent Lead Capture remains later.

Live evidence:
- CRM company context fix: commit `71c303ce9e292da53114507bc47127019e54a878`; GitHub Commercial MVP Gate Run #19 = `success`.
- Job #88: grounded Customer 360 = `727.1m` debtor balance, `1,157.2m` Sales, `3` Sales docs, `29` undelivered, Pipeline `0`.
- Job #89 → Proposal #14 → human approval: Activity `تماس برای بررسی سفارش بعدی`, due `1405/06/12`.
- Job #90: grounded Follow-up Queue = `0 overdue / 0 today / 1 upcoming`.
- Job #91 → Proposal #15 → human approval: `OPP-20260831-011700-86AC`, `qualification`, `900m`, `50%`.
- Job #92: grounded Pipeline = `1 open / 900m / weighted 450m`.
- Job #93: Customer 360 re-read preserved Finance/Sales truth and exposed the new Pipeline/follow-up.
- Manual Contact persisted in UI: `مخاطب آزمایشی CRM` / `مسئول خرید`.

## v10.5 Cycle 8 — Page-aware AI / Context Picker r1

Status: `PARTIAL`.

Kernel outcome retained:

- typed browser refs;
- server workspace/company/RBAC/customer validation;
- `ai_jobs.context_json` transport;
- Worker context consumption;
- fresh Tool grounding;
- explicit prompt/context mismatch fail-closed.

Product UX outcome:

```text
Customer 360 → dedicated AI page
```

is `RETIRED`. The original live gate is cancelled as a product acceptance target. Cycle 8 is now an infrastructure spike feeding the universal architecture, not the final context feature.

## v10.6 Cycle 9 — Universal Business Copilot Foundation

Status: `PLANNED`.

### D0–D2 — MVP A

- Global Sidecar from one application-shell integration point.
- persistent active conversation across navigation.
- Universal Entity Registry/Context Resolver v1.
- `@` mention/search and multi-entity chips.
- Context Envelope v2: page context available, explicit refs attached.
- Quick Preview.
- initial providers for Customer/Supplier/Item/Sales/Purchase/Trade Case/Shipment/Warehouse/Voucher.
- first cross-module `Customer Business Review` Skill.
- existing Tool/Proposal/Worker stack reused; no parallel AI infrastructure.

### D2–D4 — MVP B

- Skill Registry and `/` picker.
- deterministic Capability Retriever.
- bounded Supervisor/Planner over retrieved capabilities.
- Supplier Performance Review.
- Trade / Shipment Risk.
- multi-entity compare and Evidence drawer.

### D4–D6 — MVP C

- Experience Role v1 separate from Permission Role.
- Intelligent Home first slice.
- Executive Business Brief.
- exception/work-item cards.
- feedback + Experience Trace foundation.

### D6–D8 — MVP D

- Sidecar Proposal/Approval UX over existing safety contracts.
- risk label + edit/reject/approve handoff.
- post-execution verify.
- reversible/compensatable metadata foundation.

### D8–D12 — MVP E/F

- first deterministic Watchers and in-app notifications;
- Analysis Workspace for large results;
- Saved Skill pilot;
- entity-linked AI history foundation.

## P1 after Universal Copilot core

- Saved Skills / scheduled agents;
- wider Watchers + Notification Hub;
- Undo/Compensation implementation;
- customer/supplier behavioral scoring;
- currency/external intelligence with source+timestamp;
- role-adaptive navigation;
- workflow evaluation/promotion;
- cross-workspace sharing correction;
- pilot data onboarding / Design Partner hardening.

## P2

- production Document Intelligence / multimodal RAG;
- Voice Commander;
- image processing;
- Email/Drive/WhatsApp integrations;
- Knowledge Graph if relation-query evidence justifies it;
- Agent-to-Agent if eval justifies it;
- fine-tuning after licensed/evaluated data;
- advanced forecasting/policy learning;
- mobile optimization.

## One-month product/eval targets

Targets—not guarantees for the first 48 hours:

```text
≥90% Entity resolution on supported types
≥95% correct Tool selection on benchmark
100% unauthorized writes blocked
100% financial mutations policy-checked
≥80% supported management questions grounded
≥50% fewer navigation/search steps on selected pilot workflows
≥30% faster recurring workflows
<5% human correction on promoted deterministic Skills
0 cross-workspace leakage
```

Prediction metrics remain separate from factual QA metrics.
