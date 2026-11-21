# ERPSMART Agentic Next — Master Execution Board

**Authority:** T0 Chief Architect  
**Campaign:** ENTERPRISE AI-NATIVE TRANSFORMATION  
**Board updated:** 2026-11-21 (resume inspection)  
**Verified `origin/main`:** `db849ca09b54c52e4729ee813abb1f6695549ac9`  
**CI @ tip:** Commercial MVP Gate SUCCESS · RC1 MySQL+PHP Gate SUCCESS  
**Production / cPanel / prod DB:** **NOT_AUTHORIZED** (non-negotiable)

---

## 0. Resume inspection (Step 1) — factual

| Surface | Finding |
|---------|---------|
| Open AI-related PRs | **None** |
| Branches `feature/agentic-*`, `enterprise-rag-*`, `erp-agent-tools-*`, `mcp-provider-*`, `ai-workspace-*` | **Did not exist** before this resume; created below from `origin/main` |
| `docs/ai/agentic-next/` | **Missing** before this board; created now |
| Multi-Task agents active on agentic waves | **None found** — prior Multi-Task history is RC1 closure/staging only |
| Worktrees `C:\Projects\erp-worktrees\rc1-t*` | **Preserve** (RC1 historical); **do not reuse** for agentic coding |
| Unmerged agentic implementation | **None to preserve** — campaign starts from existing AI foundation on `main` |

**Conclusion:** “Resume” means continue from **executable AI stack on `main`**, not from abandoned agentic feature branches. No valid unmerged agentic work was discarded.

---

## 1. Wave gates

| Wave | Exit gate | Status | Notes |
|------|-----------|--------|-------|
| WAVE1 Platform contracts + Agent core | `AI1_ACCEPTED` | **IN_PROGRESS** | Contracts freezing; T1+T6 assigned |
| WAVE2 Enterprise RAG + ERP tools | `AI2_ACCEPTED` | **PENDING** | Blocked on Wave1 contract freeze for shared surfaces |
| WAVE3 MCP + providers + guarded ops | `AI3_ACCEPTED` | **PENDING** | MCP **disabled by default** until security acceptance |
| WAVE4 Universal Workspace + full eval | `AI4_ACCEPTED` | **PENDING** | After Waves 1–3 integration |

**Campaign verdict (live):** `AGENTIC_PLATFORM_BLOCKED` until all four gates pass with evidence.

---

## 2. Workstream assignments (T0–T6)

| ID | Role | Worktree | Branch | Owns (primary) | Status |
|----|------|----------|--------|----------------|--------|
| **T0** | Chief Architect / integration | `C:\Projects\excel-dev` | `main` + integration PRs | Shared contracts, schema review, PR merge, final acceptance | ACTIVE |
| **T1** | Universal Agent Runtime | `...\erp-worktrees\agentic-t1` | `feature/agentic-core-wave1` | `engine/agent_intelligence_kernel.py`, task state machine, recovery | RUNNING [T1 kernel](540d3b96-8c80-484f-b0b6-0f5d59fca649) |
| **T2** | Enterprise RAG | `...\erp-worktrees\agentic-t2` | `feature/enterprise-rag-wave2` | Extend `engine/rag.py` + PHP ACL/lifecycle (no parallel index engine) | RUNNING [T2 RAG](9ad0864d-f30f-4258-8dd1-c7b93713969f) |
| **T3** | ERP Tools / guarded actions | `...\erp-worktrees\agentic-t3` | `feature/erp-agent-tools-wave2` | `AiToolRegistry` extensions, workflows A–H, MF UNVALUED/GL_BLOCKED | RUNNING [T3 tools](b4d5cef2-01fb-41bc-bfee-7b7dd7af99e9) |
| **T4** | MCP + providers | `...\erp-worktrees\agentic-t4` | `feature/mcp-provider-wave3` | MCP facade (off by default), provider discovery/qualification | RUNNING [T4 MCP](af6f2a91-5f02-4729-88b3-56471b85995c) |
| **T5** | Universal AI Workspace UX | `...\erp-worktrees\agentic-t5` | `feature/ai-workspace-wave4` | Copilot/Workspace RTL/Jalali, approval cards, browser journeys | RUNNING [T5 UX](d208e894-7672-46be-ba32-dd74b824b86b) |
| **T6** | QA / security / eval | `...\erp-worktrees\agentic-t6` | `test/agentic-evaluation-wave1` | Security/idempotency/ACL/browser harness; does not self-approve | RUNNING [T6 QA](658197fb-ccb0-4746-acba-888f7a208e22) |

### Shared-file freeze (T0)

No two agents may edit these without T0 contract PR first:

- `app/Core/AiToolRegistry.php`
- `app/Core/AiCapabilityRegistry.php`
- `app/Core/AiSchema.php` / AI migrations
- `app/Core/AiRepository.php` (job/proposal lease protocol)
- `engine/worker.py` (install order)
- `engine/provider_gateway.py` (routing policy surface)
- `docs/ai/agentic-next/04-AGENT-RUNTIME-CONTRACT.md` (authoritative)

---

## 3. Reuse map (anti-duplication)

| Component | Classification | Action |
|-----------|----------------|--------|
| `AiToolRegistry` | REUSABLE_WITH_EXTENSION | Extend descriptors toward v1 typed contract; keep proposal path |
| `AiCapabilityRegistry` | REUSABLE_WITH_EXTENSION | Align Skill metadata with capability IDs |
| Agent Intelligence Kernel | PARTIALLY_IMPLEMENTED | Extend durable state / recovery; **no new orchestrator** |
| Provider Gateway | REUSABLE_WITH_EXTENSION | Add discovery/qualification UX hooks; keep adapters |
| `engine/rag.py` SQLite FTS5 | PARTIALLY_IMPLEMENTED | Extend lifecycle + ACL; no Qdrant/ES unless measured |
| Proposals / approvals / conversations / jobs | IMPLEMENTED_AND_TESTED (core) | Reuse exclusively for mutations |
| MCP | MISSING | Facade only over existing tools; disabled until secure |
| Manufacturing Agent Tools | MISSING / UNSUPPORTED_BY_ERP_DOMAIN gap | Domain exists (`ProductionDomain`); tools not in registry — T3 |
| Universal AI Workspace | PARTIALLY_IMPLEMENTED | Copilot sidecar exists; unify Workspace UX — T5 |

---

## 4. Non-negotiables (always)

- No production / cPanel / prod DB / `.cpanel.yml` destination changes  
- No duplicate ledgers / parallel orchestration / parallel tool registry  
- No unbounded agent loops; bounded budgets remain  
- Material business writes: human approval + revalidation  
- MF costing: **UNVALUED / GL_BLOCKED** remains explicit  
- Isolated/synthetic tests only  
- Preserve RC1 gates: Commercial MVP + RC1 MySQL/PHP + TR-1..OPS-1  

---

## 5. Integration / PR policy

1. Small reviewed PRs per workstream capability.  
2. Continuous integrate into `main` after T0 review + required gates.  
3. After each accepted wave: record exact SHA + CI URLs here.  
4. After Wave 4: fix only release-blocking defects → final report → **STOP**.

---

## 6. Doc pack (canonical)

| File | Owner | Status |
|------|-------|--------|
| `00-MASTER-STATUS.md` | T0 | THIS FILE |
| `01-SOURCE-AUDIT.md` | T0 | Seeded |
| `02-ARCHITECTURE.md` | T0 | Pending |
| `03-CAPABILITY-COVERAGE-MATRIX.md` | T0/T3 | Pending |
| `04-AGENT-RUNTIME-CONTRACT.md` | T0/T1 | Freezing |
| `05-ERP-TOOLS-AND-PERMISSIONS.md` | T3 | Pending |
| `06-RAG-ARCHITECTURE-AND-ACL.md` | T2 | Pending |
| `07-MCP-SECURITY-AND-CONTRACT.md` | T4 | Pending |
| `08-PROVIDERS-AND-MODEL-QUALIFICATION.md` | T4 | Pending |
| `09-UNIVERSAL-AI-UX.md` | T5 | Pending |
| `10-TESTS-EVALUATION-AND-METRICS.md` | T6 | Pending |
| `11-RELEASE-AND-DEPLOYMENT-PLAN.md` | T0 | Pending (plan only; no deploy) |
| `12-EXECUTIVE-HANDOFF-FA.md` | T0 | After Wave4 |

---

## 7. Immediate next actions

1. T0: freeze `04-AGENT-RUNTIME-CONTRACT.md` + seed `01-SOURCE-AUDIT.md`.  
2. T1: durable task state on existing `ai_jobs` + recovery/idempotency tests.  
3. T6: Wave1 security suite (cross-company, invalid tool IDs, proposal gate).  
4. T2/T3: prepare against frozen contracts; no competing registries.  
5. T4/T5: scaffold only until Wave1/2 shared surfaces merge.

**Do not claim `AI*_ACCEPTED` without PHP/MySQL or browser evidence as required by each gate.**
