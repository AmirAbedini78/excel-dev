# 26 — Agent Intelligence Kernel v1

## 2026-09-10 — v10.10.2 / Kernel v1.5 low-memory candidate

Current implementation contract: **27-LOW-MEMORY-INTELLIGENCE.md**. Status: **LOCAL-VALIDATED**; Windows/Docker/Ollama and product Live Gate remain pending. This entry supersedes earlier r6/v1.4 working-state and transport descriptions, without changing the North Star or frozen financial/approval contracts.

The candidate fixes explicit-model routing, uses grounded_selection_v3 (finding/action IDs only), removes unnecessary known-route planner calls, bounds local inference and endpoint failover, preserves per-supplier comparisons during degradation, and isolates bounded lexical RAG by workspace/company. Model qualification, installation integrity and ERP semantic acceptance are separate gates. See the new contract for settings, limitations, measured synthetic input reduction and file map.


Status: `IMPLEMENTED-CANDIDATE / LOCAL-VALIDATION`

Baseline: `7415f1471573cce6e398317069567894a7209c4a`

## 1. Purpose

Cycle 12.3 closes the semantic gap discovered after the Cycle 12.2 browser gate. ERPSMART already had trusted ERP Tools, Entity/Context contracts and Proposal/Approval safety, but Supplier/Trade/Shipment analysis still behaved too much like fixed reports. The Agent Intelligence Kernel restores the original Master Spec contract without changing the North Star.

Target outcome: a manager asks a normal Persian business question and receives a useful decision, rationale, trade-off, confidence and next action. Evidence remains drill-down support rather than the main response.

## 2. Runtime flow

```text
Goal
→ validated Context / explicit EntityRefs
→ capability family
→ initial server-compiled Tool action
→ Observation
→ server-generated bounded action menu
→ planner selects action_id only
→ server maps action_id to Tool + arguments
→ Observation
→ optional re-plan within budgets
→ Decision Evidence
→ reasoning model
→ claim/schema validation
→ useful deterministic decision-first fallback if needed
```

Budgets in v1: max 4 read Tool calls, max 3 planner calls, bounded Evidence set, max 6 Evidence references in final response.

## 3. Agency versus authority

The model may:
- understand the business goal;
- choose one opaque action ID from a server-declared menu;
- decide whether further evidence is useful;
- prioritize and explain grounded evidence.

The model may not:
- invent or submit Tool names;
- invent Tool arguments, ERP IDs, SQL, dates, quantities or money;
- receive operational DB credentials;
- turn Context/Memory/RAG into authority;
- execute write/Proposal Tools from the generic loop.

Write requests bypass the kernel and keep the existing `Proposal → Human Approval → deterministic execution → verify/audit` contract.

## 4. Initial capability families

### `supplier.performance`
Primary Tool: `supplier_performance_summary`. Optional drill-down actions are compiled from returned supplier IDs and current goal: purchase mix (`document_analytics`), Trade cases (`search_trade_cases`) and party ledger only when the user's question has a finance dimension. Explicit `@` Supplier IDs are preserved exactly. Explicit mixed types fail closed instead of widening to the portfolio.

### `trade.risk`
Primary Tool: `trade_risk_summary`. The kernel may inspect the highest-priority real case through `trade_case_snapshot`, `landed_cost_summary` or `shipment_commitment_impact` when the question and observation justify it.

### `shipment.impact`
Primary Tool: `shipment_commitment_impact`, requiring a validated `trade.case` or `shipment` from explicit/current-page Context. The kernel may inspect the Trade Case snapshot to distinguish exposure from known causality.

## 5. File / code map

### AI Worker runtime

- `engine/agent_intelligence_kernel.py` — authoritative Cycle 12.3 bounded single-agent loop.
- `engine/agent_kernel_smoke.py` — real local-model planner + analyst capability preflight.
- `engine/worker.py` — installs the kernel after legacy `business_supervisor` and before provider/commercial wrappers.
- `engine/business_supervisor.py` — Cycle 12.2 compatibility path; superseded for kernel-supported flows.
- `engine/business_skills.py` — existing Skill/NLP/context helpers reused by the kernel.
- `engine/provider_gateway.py` — provider transport abstraction; does not own business truth.
- `engine/commercial_hardening.py` — outer release/safety/observability wrapper; remains last.
- `engine/forecast_risk.py` — numeric forecast/risk baseline; separate from LLM planning.
- `engine/Dockerfile` — includes kernel and real smoke files in Worker image.

### Server truth / capability / context

- `app/Core/AiToolRegistry.php` — typed deterministic Tool catalog and Worker execution gateway.
- `app/Core/BusinessIntelligenceDomain.php` — deterministic Supplier/Shipment cross-module evidence calculations.
- `app/Core/AiCapabilityRegistry.php` — Skill/capability metadata.
- `app/Core/AiEntityRegistry.php` — universal Entity provider registry.
- `app/Core/AiContextResolver.php` — server validation of user/workspace/company/RBAC EntityRefs.
- `app/Core/AiContextEnvelope.php` — versioned context envelope.
- `app/Core/BusinessCopilot.php` / `BusinessCopilotApi.php` — Sidecar integration and server context/chat bridge.

### Tests / evals

- `tests/test_cycle12_3_agent_intelligence_kernel.py` — focused agency/authority/entity/budget/fallback/source contracts.
- `tests/fixtures/agent_intelligence_kernel_benchmark.json` — 58 natural-language semantic routing/context cases.
- `tests/test_cycle12_2_agent_core_rescue.py` — prior grounding/evidence contracts retained.
- `tests/test_cycle12_skills_capability_nlp.py` — Cycle 12 compatibility contracts retained.
- `scripts/release_gate.py` — full regression/release entrypoint.

## 6. Final response contract

The primary user response should be decision-first:

```text
جمع‌بندی / تصمیم
چرا
موازنه یا ریسک تصمیم
اعتماد / محدودیت
اقدام بعدی
شواهد کلیدی (bounded)
```

A raw Evidence Pack is never the preferred final answer. If the reasoning model fails validation, deterministic fallback must still produce a useful business conclusion or a precise "cannot decide yet" with the missing evidence and next action.

## 7. Model capability gate

The previous `{"status":"ok"}` smoke only proved JSON transport. `engine/agent_kernel_smoke.py` must prove two actual skills on the configured runtime models:

1. Planner: choose one allowed `action_id` from a schema-bounded menu after an Observation.
2. Analyst: produce a schema-valid decision object bound to supplied Evidence IDs.

Real Docker/Ollama smoke is performed on the user's Worker environment; source/static regression in environments without Docker cannot substitute for it.

## 8. ML / RAG / optimization boundary

Agent Intelligence Kernel is orchestration, not the numeric prediction engine. The roadmap remains neuro-symbolic:

```text
LLM           → goal understanding / planning / qualitative reasoning
ERP Tools     → current structured truth
Stat/ML       → forecasts, probabilities, learned scores after backtest
Solvers       → constrained optimization when a real business problem requires it
RAG           → access-controlled document/knowledge context
Policy        → authority / approval / execution
```

No bespoke Deep Learning model is added to this 2–3 day critical path. ML depth is introduced when a target has sufficient historical data and an explicit offline metric/backtest.

## 9. Acceptance gate

Before promotion/commit of this candidate:

- focused Agent Kernel tests green;
- Cycle 12.2 and Cycle 12 compatibility green;
- full regression/release gate green;
- PHP/Node/source syntax green;
- exact changed-file boundary/rollback package;
- real planner Ollama semantics hard-gate green; analyst preflight must be either model-contract PASS or explicit exact-config deterministic-degraded PASS;
- browser Supplier question returns a useful conclusion rather than Evidence dump;
- two explicit Supplier mentions preserve exactly those Supplier IDs or fail closed on type mismatch;
- Trade Risk explains why the priority risk matters;
- Shipment Impact resolves real Context and distinguishes exposure from causality;
- write request still goes through Proposal/Approval.

After live semantic acceptance, close MVP B and immediately continue with MVP C Intelligent Home.

## 10. External design references used as patterns, not dependencies

The design is compatible with the principles reviewed from the attached Agent/RAG/Security references and with public ERP agent implementations: single-agent Tool/Observation loops first; ERP permission/truth stays authoritative; retrieval is for relevant context rather than dumping all data; deterministic solvers/Tools handle calculations; high-impact actions retain human approval. ERPSMART does not copy their framework-specific architecture or weaken its existing trust boundary.

## 11. Runtime decision-model capability route

The first real Windows/Docker preflight proved an important deployment constraint: the configured `analysis_model` can be installed and reachable yet still exceed the bounded latency budget on the full Agent decision schema. Model presence is therefore not equivalent to capability fitness.

`engine/agent_kernel_smoke.py` now tests the actual analyst contract across a bounded chain of currently configured models (`analysis`, `fallback`, then `agent` when distinct). The first model that passes the decision/Evidence-ID contract is written only to the existing gitignored Worker data volume. If none passes, the same file records an explicit exact-config deterministic-degraded capability route:

```text
engine/data/agent_kernel_model_route.json
```

`engine/agent_intelligence_kernel.py` prefers a verified analyst only while the route kernel/contract and configured model list still match. A matching degraded route returns an empty analyst chain so the deterministic decision renderer is immediate instead of paying repeated timeouts; changing the model configuration automatically invalidates that circuit. Repository config, ERP truth and authorization are not mutated by this route.

The production analyst call is also intentionally compact (`num_ctx`/`num_predict` bounded) so the user receives a decision in a practical interactive budget. If every configured model fails the strict claim contract, deterministic decision fallback remains the safe terminal response; the system never falls back to a raw Evidence dump.


### Agent Kernel analyst transport v1.4 — resilient capability route

Live recovery proved a narrower fact than “the Kernel is broken”: the planner contract passes, while every currently configured local analyst can still return truncated/invalid structured JSON. Runtime keeps `compact_decision_v2`, and the smoke now uses the same complete-object extractor as production. If an analyst passes, that model route is persisted. If none passes, preflight persists an explicit `deterministic_decision_fallback` capability route bound to the exact `kernel_version`, `analyst_contract` and configured model list. While that tuple is unchanged, runtime skips repeated slow analyst failures and immediately renders the server-validated decision-first fallback; any model/configuration change automatically reopens analyst evaluation.

The planner remains a hard live capability gate. Analyst degradation is an explicit safe operating mode, **not** semantic live acceptance: Supplier/Trade/Shipment browser gates must still prove that the resulting decision is useful before MVP B closes.
