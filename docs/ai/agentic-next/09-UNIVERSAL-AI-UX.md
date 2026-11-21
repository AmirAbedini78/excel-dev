# 09 — Universal AI UX (Wave4)

**Owner:** T5  
**Branch:** `feature/ai-workspace-wave4`  
**Status:** WAVE4_PREP_IMPLEMENTED (scaffolded against frozen Wave1 contract)  
**Baseline:** `db849ca` + this workstream  

## 1. Principle

Universal AI Workspace **is** the existing Business Copilot sidecar — not a second chatbot.

| Reuse | Role |
|-------|------|
| `BusinessCopilot` / `BusinessCopilotApi` | Shell + API bridge |
| `assets/business-copilot.js` | Thread / @ / queue / poll |
| `assets/ai-live.js` | Command-center live stages (unchanged; labels shared conceptually) |
| `assets/ai-engine-selector.js` | Provider ↔ model sync |
| Proposals via `approve_proposal` / `reject_proposal` | Only mutation path |

New additive assets:

- `assets/universal-ai-workspace.js` — task progress, evidence inspector, approval cards, Jalali/IRR helpers  
- `assets/universal-ai-workspace.css` — RTL styles  

## 2. UX surfaces

### 2.1 Agent task progress

Maps to `04-AGENT-RUNTIME-CONTRACT.md` states:

`CREATED → VALIDATING → PLANNING → EXECUTING_READS → ANALYZING → PROPOSAL_READY → AWAITING_APPROVAL → EXECUTING_APPROVED_ACTION → VERIFYING → COMPLETED`

Terminal: `FAILED | CANCELLED | EXPIRED | BLOCKED | PARTIALLY_COMPLETED`

**Hard rules**

- Progress steps are marked only from server `job.agent_task.state` (or live message fallback).  
- **No fictitious progress** — without `agent_task`, UI shows live message only, not a fake step rail.  
- Persian labels for every state.

### 2.2 Evidence inspector

Renders bounded `job.evidence[]` with kinds:

- `LIVE_ERP_FACT`  
- `DOCUMENT_EVIDENCE`  
- `CALCULATED_FINDING`  
- `MODEL_INTERPRETATION` (explicitly non-authoritative)

Amounts use company **display** currency (IRR/Toman) while ledger remains IRR.

### 2.3 Proposal approval cards

- Status badge: `proposed` → «پیشنهاد — اجرا نشده»  
- Approve/reject only when `status === proposed`  
- After approve API success → «پیشنهاد پس از تأیید انسانی اجرا شد»  
- **Never** show a proposal as executed before server confirms  

### 2.4 Model selector

Existing engine/model selects remain in the sidecar; queue still posts `engine` + `model`. Hint copy clarifies models do not bypass RBAC/approval.

### 2.5 Persian empty / error / loading

Centralized in `ERPSMART_AI_WORKSPACE.COPY` (empty thread, search loading/error, job loading, poll timeout, proposal errors).

### 2.6 RTL + Jalali + IRR/Toman

- Sidecar remains `direction:rtl`  
- Welcome / evidence timestamps: Jalali via workspace helper  
- Config exposes `jalali_today`, `display_currency`, `money_label_fa`  

## 3. API enrichment (T5-owned)

`BusinessCopilotApi::enrichWorkspaceJob()` reads `result_json` via `jobForUser` and exposes:

- `agent_task` `{ state, message, updated_at, budget }`  
- `evidence[]` (bounded, kind-normalized)  
- `proposals[].executed` boolean derived from status  

Does **not** edit frozen `AiRepository` lease/proposal protocol.

## 4. Blockers for `AI4_ACCEPTED`

| Blocker | Owner | Impact |
|---------|-------|--------|
| Durable `agent_task` writer on jobs | T1 | Progress rail stays in live-message fallback until Wave1 emits state |
| Typed tool/RAG evidence payloads | T2/T3 | Evidence inspector empty until tools/RAG attach evidence |
| MCP/provider qualification UX hooks | T4 | Model list is current prefs only |
| Full browser eval harness + security suite | T6 | Synthetic contract tests exist; live UAT optional |

Wave4 UI is ready to consume Waves 1–3 once merged; until then scaffold is contract-faithful without inventing runtime progress.

## 5. Tests & evidence

- `tests/test_wave4_universal_ai_workspace.py` — static contract  
- `docs/ai/agentic-next/evidence/browser/` — synthetic seed + journey script (no production)  

## 6. Forbidden (reaffirmed)

- Fictitious progress  
- Showing proposals as executed before approval  
- Production / cPanel / prod DB deploy  
