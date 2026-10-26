# 07-DECISION-LOG — تصمیم‌های معماری و محصول

## 2026-09-11 — v10.10.3 / Kernel v1.6: bounded stream recovery

Current package: `ERPSMART-v10.10.3-stream-recovery-local-validated.zip`, applied over the exact delivered v10.10.2 working state. Current implementation and incident evidence: **30-STREAM-RECOVERY-20260911.md**; install/rollback: **28-LOW-MEMORY-INSTALL-FA.md**; results: **29-LOW-MEMORY-TEST-REPORT-FA.md**.

The supplied Windows log proves v10.10.2 Docker build, 268 offline tests, file installation and Worker startup PASS. Its model gate is DEGRADED: qwen3.5:0.8b provider_error, qwen3:1.7b deadline, gemma3:4b memory_admission. This does not establish an OOM for the first model. A reproducible cross-thread HTTP chunk cancellation bug is fixed in v1.6; first-request allowance, shared endpoint cooldown and redacted per-case evidence are included. v1.6 remains LOCAL-VALIDATED until tested on that engine. MVP-B semantic acceptance remains open; MVP C Role Brief / Intelligent Home follows that acceptance. Older dated entries below describe historical states.

## 2026-09-10 — v10.10.2 / Kernel v1.5 low-memory candidate

Current implementation contract: **27-LOW-MEMORY-INTELLIGENCE.md**. Status: **LOCAL-VALIDATED**; Windows/Docker/Ollama and product Live Gate remain pending. This entry supersedes earlier r6/v1.4 working-state and transport descriptions, without changing the North Star or frozen financial/approval contracts.

The candidate fixes explicit-model routing, uses grounded_selection_v3 (finding/action IDs only), removes unnecessary known-route planner calls, bounds local inference and endpoint failover, preserves per-supplier comparisons during degradation, and isolates bounded lexical RAG by workspace/company. Model qualification, installation integrity and ERP semantic acceptance are separate gates. See the new contract for settings, limitations, measured synthetic input reduction and file map.


## ADR-001 — AI-first Vertical Slice
Status: ACCEPTED

لازم نیست ابتدا نرم‌افزار حسابداری کامل شود. Existing Accounting Core برای ساخت و آزمون Workflowهای هوشمند استفاده می‌شود. هر primitive گمشده فقط در حد نیاز Workflow تکمیل می‌شود.

## ADR-002 — Accounting/Financial only for current MVP
Status: ACCEPTED

در Scope فعلی توسعه AI روی Notes/CRM/Phonebook/other business modules انجام نمی‌شود. Multi-company foundation می‌ماند ولی Practice OS expansion فعلاً DEFERRED است.

## ADR-003 — DB is system of record
Status: ACCEPTED

LLM و RAG منبع حقیقت عددی جاری نیستند.

## ADR-004 — No direct SQL for LLM
Status: ACCEPTED

تمام تعامل با ERP از Tool Gateway و Domain validation عبور می‌کند.

## ADR-005 — Proposal before financial mutation
Status: ACCEPTED

Mutationهای مالی حساس Proposal هستند. Autonomy بعداً با Risk Policy مرحله‌بندی می‌شود.

## ADR-006 — Local-first compute
Status: ACCEPTED

cPanel = control plane؛ Workerهای Docker/Ollama = compute. Worker outbound HTTPS استفاده می‌کند.

## ADR-007 — Hybrid deterministic + LLM
Status: ACCEPTED

LLM نباید برای arithmetic/report facts استفاده شود. Fast deterministic path برای facts، LLM برای planning/interpretation.

## ADR-008 — Forecasting is separate numeric engine
Status: ACCEPTED

Forecast عددی از مدل آماری/ML می‌آید؛ LLM فقط explain می‌کند.

## ADR-009 — RAG is contextual knowledge
Status: ACCEPTED

RAG برای documents/laws/policies است، نه current balances.

## ADR-010 — Frameworks are replaceable
Status: ACCEPTED

Custom loop فعلی contract نهایی نیست. LangGraph/Hermes/Qdrant فقط با نیاز اثبات‌شده اضافه می‌شوند و Tool contracts نباید به runtime خاص قفل شوند.

## ADR-011 — Safe Deep
Status: ACCEPTED

آزمایش‌های اولیه نشان دادند وارد کردن مستقیم عدد به LLM می‌تواند تعبیر/رقم نادرست بسازد. Deep باید deterministic core + constrained qualitative enhancement + fallback داشته باشد.

## ADR-012 — Ground IDs, never generate them
Status: ACCEPTED

party_id/item_id/account_id از search/Tool واقعی می‌آیند؛ LLM حق ساخت ID ندارد.

## ADR-013 — Adaptive Plan Cache is optimization only
Status: ACCEPTED / FROZEN

Cache پاسخ مالی ممنوع است. فقط Plan معتبر Read-only می‌تواند cache شود. این Feature نباید Roadmap را از Planner/Agent/Intelligence منحرف کند.

## ADR-014 — Comprehensive candidate validation
Status: ACCEPTED

پس از تجربه چند Micro-patch شکست‌خورده، سیاست رسمی Candidate-first + pre-mutation integration + rollback اتخاذ شد.

## ADR-015 — Full accounting app deferred until AI MVP
Status: ACCEPTED

هدف فعلی اثبات و تجاری‌سازی مغز AI روی ماژول مالی موجود است. Full accounting product expansion بعد از MVP تصمیم‌گیری و اجرا می‌شود.

## ADR-016 — Commercial release guard and idempotent terminal delivery
Status: ACCEPTED

v9.3 یک Wrapper نهایی cross-cutting روی Guard Stack دارد تا بدون تغییر محاسبات مالی، contractهای latency/observability/redaction/read-only/proposal-only را یکجا enforce کند. Proposal creation باید atomic idempotent باشد و retry پاسخ گمشده `complete/fail` باید همان terminal state را بدون side effect دوم acknowledge کند. این recovery هرگز Proposal Approval یا domain validation را bypass نمی‌کند.

## ADR-017 — Live and reload observability must be contract-equivalent
Status: ACCEPTED

Job #49 نشان داد persistence صحیح metadata کافی نیست؛ اگر endpoint یا browser renderer آن را در SSE/Polling حذف کند، Commercial observability در تجربه اصلی کاربر شکست خورده است. از v9.3.0.1، terminal live payload باید redacted `commercial_hardening` را حمل کند و renderer مشترک SSE/Polling با رندر PHP از نظر route/model/latency/risk هم‌معنا باشد. JavaScript syntax نیز release gate اجباری است.

## ADR-018 — Attempt observability is allowlisted and bounded
Status: ACCEPTED

Job #51 مدل تلاش‌شده و fail-closed بودن Action را ثابت کرد، اما stage عمومی `action_read` نام Toolهای واقعی را نشان نداد و metrics مدل تلاش‌شده نیز نمایش داده نشد. برای audit تجاری، endpoint و renderer می‌توانند فقط نام‌های normalize‌شده `tools_used/tools_attempted` و شش مقدار عددی allowlisted از `attempted_metrics` را نمایش دهند. Tool arguments، results، call IDs، free-form model metadata و trace details حساس هرگز وارد payload مرورگر نمی‌شوند.

## 2026-08-26 — Commercial MVP closeout: product evidence over standalone proof harnesses

Status: ACCEPTED

برای بستن v9.3، تست‌های جداگانه fault-injection فقط زمانی blocker هستند که یک failure واقعی در recovery محصول مشاهده شود. Proposal idempotency و terminal replay همچنان در regression deterministic اجباری‌اند، اما مسیر اصلی acceptance از UI واقعی محصول است. Job #54 → Proposal #3 → human approval → `AI-VCH-20260826-202025-9F19` و مشاهده دو آرتیکل متوازن در Accounting UI معیار نهایی write-flow است.

همچنین `engine/config.json` یک فایل runtime محلی و gitignored است و ممکن است Worker token واقعی داشته باشد؛ source-secret gate نباید این فایل runtime را به‌عنوان secret committed گزارش کند. فقط همین path از source scan مستثناست.

v9.3 پس از این closeout feature-frozen است؛ کار بعدی RC/demo/market/customer/pricing/positioning/GTM است.

## ADR-019 — v10 Wide Platform / Deep Modules pivot
Status: ACCEPTED — 2026-08-27

پس از Live validation کامل v9.3، Scope از Accounting-only MVP به Modular AI-Native Business Operations Platform تغییر می‌کند. تصمیم کاربر صریح است و North Star/Roadmap مجاز به تغییر هستند.

Architecture strategy: Platform از نظر Module blueprint جامع می‌شود، اما عمق عملیاتی فقط Module-by-Module بالا می‌رود. اولین Vertical تجاری Finance/Trade برای شرکت‌های بازرگانی B2B است. Module غیرفعال نباید navigation/route/AI processing داشته باشد.

v9.3 safety contracts همچنان invariant هستند. Model Provider Gateway باید LLM provider را قابل تعویض کند بدون اینکه domain calculation/validation به مدل منتقل شود.

Long-term benchmark/model vision یک Asset strategy است، نه Scope آموزش مدل در v10: ابتدا Product workflows + evaluation data، سپس benchmark و در صورت توجیه داده/مجوز، مدل تخصصی.

## ADR-020 — Model Provider Gateway and dual-worker availability
Status: ACCEPTED — 2026-08-27

ERPSMART LLM transport is provider-agnostic from v10 Cycle 2. Ollama stays primary for local/private operation; an OpenAI-compatible adapter may act as fallback/primary only by deployment configuration. Cloud transport never changes deterministic business truth, Tool validation, ERP IDs or Proposal/Approval boundaries.

A cloud fallback inside the local Worker does not solve a powered-off PC. High availability is achieved by a second always-on Worker configured `cloud_only` against the same cPanel queue. Provider API keys are runtime secrets and must never be committed or exposed in registration/job metadata.

## ADR-021 — Natural-language party balance is a deterministic grounded read
Status: ACCEPTED — 2026-08-27

Job #55 proved that provider/runtime success is not enough when semantic routing answers the wrong business question. Common Persian phrasings such as `مانده <نام طرف‌حساب> را بررسی کن` must bypass adaptive LLM planning and route directly through grounded entity resolution: `search_parties → party_ledger`. Entity text is copied from the prompt; ERP IDs still come only from server Tool results. Summary-only wording may reduce presentation noise but never changes the ledger source of truth.

## ADR-023 — First commercial MVP is an end-to-end trading flow, not a finance feature bundle
Status: ACCEPTED — 2026-08-27

The first Design Partner story will demonstrate one coherent B2B importer/distributor workflow across Procurement, Trade/Logistics, Warehouse/Inventory, Finance and Sales. Finance capabilities are supporting primitives inside this flow. The golden path is demand/replenishment → supplier/proforma/PO → shipment/import case → landed-cost estimate/risk → warehouse receipt/inspection → inventory valuation/vendor bill → sales/delivery → receivable/cash → margin/manager brief. AI may reason across the flow, but facts and mutations remain Tool/Proposal grounded.

## ADR-024 — Inventory truth is movement-ledger derived
`on_hand` is derived from posted Stock Movements; `reserved` from active reservations; `available = on_hand - reserved`. Expected inbound remains a procurement projection and is not counted as on-hand. Rejected receipt quantity never increases stock.

## ADR-025 — UI and Agent share InventoryDomain
Manual module pages and AI Tools call the same `InventoryDomain`; stock calculations and receipt posting must not be duplicated in separate UI/Agent implementations.

## v10.2 Cycle 5 — Trade Logistics + Landed Cost

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`. Cycle 4 is now `LIVE E2E PASS` through Job #70 and receipt `RCV-20260829-024216-D32F`. Cycle 5 adds Trade Case → Shipment → ETA/Customs → Estimated/Actual Trade Costs → deterministic Landed Cost allocation → inventory valuation bridge. AI mutations remain Proposal → Human Approval. See `15-TRADE-LOGISTICS-LANDED-COST.md`.

## ADR-026 — ERPSMART Intelligence Platform / Business Copilot North Star
Status: ACCEPTED — 2026-08-31

The v10 Finance/Trade vertical remains the first market wedge, but the product interaction model is promoted from “modular ERP with AI features” to an **AI-native Business Operating System**. Architecture name is `ERPSMART Intelligence Platform`; the user-facing intelligent layer is `ERPSMART Business Copilot`.

ERP/Domain services remain the system of record and execution authority. Business Copilot becomes the pervasive layer for Search, Analysis, Orchestration, Guarded Action and Proactive supervision. This decision does not reopen v9.3 safety invariants or justify broad horizontal module implementation without customer evidence.

## ADR-027 — Universal Entity/Context/Skill contracts replace per-page AI integrations
Status: ACCEPTED — 2026-08-31

AI context must be modeled through a Universal Entity Registry and versioned Context Envelope. Module pages expose typed page/selection references; a central resolver re-validates workspace/company/module/RBAC and canonical identity. Browser labels/business facts are never authority.

Tools remain deterministic primitives. User-facing business capabilities are versioned Skills composed from reusable Tools/Engines and constrained Workflow Grammar. With catalog growth, Capability Retrieval supplies a bounded relevant set to the Planner instead of injecting every Tool descriptor.

The architecture must not grow by adding one CRM/Sales/Trade branch after another inside `AiPageContext` or by cloning an Ask-AI widget into each module.

## ADR-028 — Pervasive Sidecar + Role-Adaptive Intelligent Home; Cycle 8 page-jump UX retired
Status: ACCEPTED — 2026-08-31

Primary daily UX is a Global Business Copilot Sidecar that persists while the user navigates. Current page is context-aware but not blindly injected. `@` selects canonical business entities; `+` attaches context/files/selections; `/` discovers Skills/Actions. Large work moves to an Analysis Workspace/Command Center.

Permission Role and Experience Role are separate. Role adaptation may prioritize information and actions but never grants authority.

Cycle 8 r1 commit `338e134` is retained as a Context Kernel prototype (`typed ref → server validation → context_json → Worker`), but the flow `Customer 360 → dedicated AI page` is `RETIRED` as product UX and its original live gate is cancelled.

## ADR-029 — Incremental Supervisor architecture; multi-agent and self-learning are deferred by evidence
Status: ACCEPTED — 2026-08-31

P0 maximizes one user-facing Supervisor/Manager plus deterministic Domain Engines and standardized Tools. Multi-Agent is introduced only when eval shows that Tool/instruction overlap or specialization requires it; Manager Pattern is preferred to preserve one coherent assistant.

Production does not learn policy directly from every user action. Runs are traced and evaluated; only controlled Skill candidates may be promoted after offline eval. Conversation, Preference, Business Experience, Workflow and Knowledge memories remain separate.

## ADR-030 — Every two-day increment must be product-demoable and regression-complete
Status: ACCEPTED — 2026-08-31

Short-term speed is achieved through coherent vertical increments, not demo-only hacks. D0–D2 starts with Global Sidecar + Universal Entity/Context + `@` mentions + one real cross-module Skill. Each following two-day increment adds a presentable layer while preserving exact-baseline patching, full regression, server trust boundaries and live acceptance.

## 2026-09-08 — Cycle 12.3 Agent Intelligence Kernel correction

Decision: keep the accepted North Star and safety boundaries, but replace the fixed Supplier/Trade/Shipment mini-report path as the target intelligence runtime with a bounded single-agent Tool/Observation/re-plan kernel.

Reason: commit `7415f1471573cce6e398317069567894a7209c4a` fixed the proven data/UI blockers, yet live management questions still returned grounded Evidence without a usable decision. This is an implementation deviation from the existing Master Spec, not evidence that the product direction is wrong.

Consequences:
- `business_supervisor.py` remains compatibility only;
- `agent_intelligence_kernel.py` is authoritative for the first promoted capability families;
- model selects opaque server-declared action IDs only;
- existing Proposal/Approval and deterministic Tool authority remain unchanged;
- MVP C Intelligent Home follows immediately after semantic live acceptance;
- ML/RAG/Multi-Agent remain separate eval-gated subsystems.

## ADR-031 — Capability-proven decision model route, configuration remains authoritative
Status: ACCEPTED — 2026-09-08

The Agent Intelligence Kernel must not assume that the nominal `analysis_model` is suitable merely because Ollama reports it as installed. A real preflight on the user Worker showed the planner contract passing on the small local model while the heavyweight analysis model exceeded the bounded timeout on the full decision schema.

Decision: test the actual decision/Evidence-ID contract across a bounded current-config model chain, persist only the first passing analyst model in the existing gitignored Worker data volume, and prefer it at runtime only while it remains one of the currently configured models. Stale runtime routes are ignored. This is a capability/latency route only; it does not own business truth, permissions or authorization.


### Agent Kernel analyst transport v1.4 — resilient capability route

Live recovery proved a narrower fact than “the Kernel is broken”: the planner contract passes, while every currently configured local analyst can still return truncated/invalid structured JSON. Runtime keeps `compact_decision_v2`, and the smoke now uses the same complete-object extractor as production. If an analyst passes, that model route is persisted. If none passes, preflight persists an explicit `deterministic_decision_fallback` capability route bound to the exact `kernel_version`, `analyst_contract` and configured model list. While that tuple is unchanged, runtime skips repeated slow analyst failures and immediately renders the server-validated decision-first fallback; any model/configuration change automatically reopens analyst evaluation.

The planner remains a hard live capability gate. Analyst degradation is an explicit safe operating mode, **not** semantic live acceptance: Supplier/Trade/Shipment browser gates must still prove that the resulting decision is useful before MVP B closes.

## ADR-032 — Analyst model is an optional reasoning accelerator; planner capability remains the hard Kernel gate
Status: ACCEPTED — 2026-09-09

The Cycle 12.3 live evidence showed `qwen3.5:0.8b` satisfying the bounded planner action contract while the configured analyst chain returned truncated/invalid JSON. Failing the whole release after deterministic ERP evidence, claim guards and deterministic decision rendering have already passed creates a false availability dependency on local model formatting.

Decision: planner semantics remain a hard preflight gate. Analyst preflight must use the same safe complete-object extraction as production. A passing analyst is preferred; if all current analysts fail, persist an exact-config `deterministic_decision_fallback` route and circuit-break those model calls until the configured model set or kernel/contract changes or preflight is deliberately rerun. This degradation never expands authority, never bypasses Evidence-ID validation, and never counts as semantic live acceptance.

## 2026-09-10 — Final packaging and review of v1.5

- Candidate-first installation stages source outside the project, without runtime secrets, and builds/tests before stopping the existing Worker. Byte-exact rollback retains the previous Worker image and restores the previous model route, including absence.
- Base compatibility uses exact normalized content digests from the submitted r6 working tree, not a fragile exact porcelain filename boundary. Unknown source differences remain a blocking preflight conflict; neither Git history nor user edits are reset.
- Corrected HTTP resource closure, wall-bounded inventory reads, legacy configured context/output preferences within capacity ceilings, and consistent Persian/Arabic FTS normalization.
- Source release gate distinguishes skipped PHP/Node from a full pass. Model qualification is three production-contract selections; default known-route planning is deterministic auto mode. Neither mode is evidence of unrestricted reasoning.
- Validation and remaining live gates: see 29-LOW-MEMORY-TEST-REPORT-FA.md. Roadmap remains semantic product acceptance, then MVP C.
