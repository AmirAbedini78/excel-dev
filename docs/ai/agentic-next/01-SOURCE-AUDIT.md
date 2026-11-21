# 01 — Source Audit (executable inventory)

**Baseline SHA:** `db849ca09b54c52e4729ee813abb1f6695549ac9`  
**Method:** filesystem + code inspection on `main` (not docs-only).

## PHP control plane

| Asset | Bytes (approx) | Classification | Notes |
|-------|----------------|----------------|-------|
| `app/Core/AiToolRegistry.php` | ~88KB | REUSABLE_WITH_EXTENSION | Large read + proposal catalog; no MF tools yet |
| `app/Core/AiCapabilityRegistry.php` | ~11KB | REUSABLE_WITH_EXTENSION | Skill catalog |
| `app/Core/AiEntityRegistry.php` | ~40KB | REUSABLE_WITH_EXTENSION | Entity grounding |
| `app/Core/AiContextResolver.php` | ~1.2KB | PARTIALLY_IMPLEMENTED | Thin; needs company-change reauth hardening |
| `app/Core/AiContextEnvelope.php` | ~1.3KB | REUSABLE_WITH_EXTENSION | Versioned envelope |
| `app/Core/AiPageContext.php` | ~3.5KB | REUSABLE_WITH_EXTENSION | Page context |
| `app/Core/AiRepository.php` | ~31KB | IMPLEMENTED_AND_TESTED (core) | Jobs, leases, proposals |
| `app/Core/AiSchema.php` | ~12KB | REUSABLE_WITH_EXTENSION | `ai_jobs`, proposals, conversations |
| `app/Core/AiProposalGuardrails.php` | ~5.5KB | IMPLEMENTED_AND_TESTED | RC1 T6 guardrails |
| `app/Core/BusinessCopilot.php` / `Api` | ~8KB each | PARTIALLY_IMPLEMENTED | Sidecar bridge |
| `app/Core/AiEnginePreferences.php` | ~2.7KB | REUSABLE_WITH_EXTENSION | Engine prefs |
| `app/Modules/AiModule.php` | ~27KB | PARTIALLY_IMPLEMENTED | Settings/UI surface |
| `ai_api.php` / `ai_live.php` / `copilot_api.php` | present | REUSE | Worker + live endpoints |

## Python engine

| Asset | Classification | Notes |
|-------|----------------|-------|
| `engine/agent_intelligence_kernel.py` | PARTIALLY_IMPLEMENTED | Bounded loop; no durable AI1 state machine named in source |
| `engine/worker.py` | REUSE | Lease/callback install order |
| `engine/action_orchestrator.py` | REUSE | Action path |
| `engine/agent_guard.py` | REUSE | Guardrails |
| `engine/provider_gateway.py` | REUSABLE_WITH_EXTENSION | Multi-provider routing |
| `engine/rag.py` | PARTIALLY_IMPLEMENTED | Scoped SQLite FTS5 + optional embeddings; no enterprise lifecycle/ACL PHP gate |
| `engine/proactive_agent.py` | PARTIALLY_IMPLEMENTED | Bounded proactive |
| `engine/commercial_hardening.py` | REUSE | Outer wrapper |

## Frontend

| Asset | Classification | Notes |
|-------|----------------|-------|
| `assets/ai-live.js` | PARTIALLY_IMPLEMENTED | Live chat/SSE |
| `assets/ai-engine-selector.js` | PARTIALLY_IMPLEMENTED | Thin selector |
| Copilot sidecar | PARTIALLY_IMPLEMENTED | Exists; not full Universal Workspace |

## Tests found

- `tests/test_cycle12_3_agent_intelligence_kernel.py`
- `tests/test_cycle12_2_agent_core_rescue.py`
- `tests/test_cycle12_skills_capability_nlp.py`
- `tests/test_cycle11_workspace_copilot_mvp.py`
- `tests/test_cycle10_universal_copilot_foundation.py`
- `tests/test_cycle9_universal_copilot_spec.py`
- `tests/php_rc1_t6_guardrails_test.php`

**Gap:** No dedicated enterprise RAG ACL suite, MCP suite, or AI1 durable-state recovery suite yet.

## MCP

**MISSING** — no production MCP facade in tree at audit time.

## Manufacturing tools

`ProductionDomain` / MF-1 domain exists; **no** manufacturing tool names in `AiToolRegistry` descriptors (grep empty). T3 must expose honest read tools + keep UNVALUED/GL_BLOCKED.
