# RC1 Acceptance Report

**Owner:** T0 final RC1 acceptance closure  
**Pre-closure baseline `main` SHA:** `2a30f5abac6291af2e6d3ccf1f87446335c84bad`  
**Final `main` SHA (PR #32 merged):** `52fa7f12f465653d6e445d484f1a8dda48215f45`  
**Closure branch:** `fix/rc1-stabilization-p1-closure` (merged via PR #32)  
**Report date:** 2026-11-21  
**DATABASE_TESTED:** **true** (disposable sandbox)  
**Independent verdict:** **RC1_BLOCKED** — **NOT_DEPLOYED**

---

## Historical claim (prior campaign — do not erase)

| Field | Value |
|-------|-------|
| Prior gate | `python scripts/rc1_acceptance_gate.py --strict-pass` → PASS=5 |
| Prior verdict | `RC1_ACCEPTED` (handoff tip `c9f6eaf…`) |
| Prior note | OPS-1 marked PASS with provenance `UNAVAILABLE_NOT_DEPLOYED` |

---

## Closure append (2026-11-21) — post PR #32

**Sandbox:** `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / port 3307 / `server_uuid=a73a8a84-e4bd-11f1-ad33-7e073bee960b` / PHP `erpsmart-o2c-php:8.2`

| Contract | Script status | Independent class | Proof summary |
|----------|---------------|-------------------|---------------|
| TR-1 | PASS | **PASS_REPRODUCED** | outstanding_after_ap `6000.00` → final `0.00`; duplicate code `P2P_SUPPLIER_INVOICE_DUPLICATE` |
| TR-2 | PASS | **PASS_REPRODUCED** | invoice/receipt settle to outstanding `0.00` |
| MF-1 | PASS | **PASS_REPRODUCED** | Manufacturing **lifecycle** tested (BOM→plan→issue→FG/QC); concurrent issue 1+1; on_hand A=4; cost class `UNVALUED`; WIP/FG valuation **not fully supported**; GL posting `BLOCKED` — **no unsupported auto GL** (see `T5-VALUATION-DECISION.md`) |
| SYS-1 | PASS (smoke) | **BLOCKED_EXTERNAL** | Profile/tenant smoke only; authenticated browser **NOT_RUN** (`T6-BROWSER-NOTES.md`, `agent_b_browser_status.json`) |
| OPS-1 | PASS (script w/ UNAVAILABLE) | **BLOCKED_EXTERNAL** | Restore drill engineering **CLOSED**; provenance `UNAVAILABLE_NOT_DEPLOYED` — **no invented FRESH_INSTALL**; browser PENDING |

**Mandatory E2E skipped in acceptance gate:** **0** (under RC1_REQUIRED).

**Commercial MVP CI on Final main:** SUCCESS (source-only) — https://github.com/AmirAbedini78/excel-dev/actions/runs/44810790482 — **insufficient** alone for RC1 acceptance.

**GHA MySQL+PHP mandatory workflow:** **NOT installed** under `.github/workflows/` — token lacks `workflow` scope. Proposed YAML: `docs/ai/rc1/ci/rc1-mysql-php-gate.yml`. Real GHA run: **NOT_RUN**. Do **not** claim GHA E2E enabled. Owner one-time auth: `gh auth refresh -h github.com -s workflow`. Evidence: `agent_a_gha_status.json`.

**Local gates:** `rc1_ci_mysql_php_gate` PASS; `rc1_acceptance_gate --strict-pass` script PASS=5; `release_gate --require-php --require-node` on tip.

### MF costing (truthful scope)

- **In scope / tested:** operational manufacturing lifecycle + stock movements; cost rollup may classify `UNVALUED` / `PROVISIONAL` / `POSTED_ACTUAL` / `BLOCKED`.
- **Not fully supported in RC1:** WIP/FG general-ledger valuation (policy `WIP/FG GL = BLOCKED` per `T5-VALUATION-DECISION.md`).
- **Forbidden claim:** unsupported automatic WIP/FG GL posting — `gl_valuation=BLOCKED`, `gl_posting=BLOCKED` in `mf1_acceptance.json`.

### Peer agent lanes (closed this pass)

| Lane | Status | Result |
|------|--------|--------|
| Agent A — GHA | **DONE** | Workflow **not** under `.github/workflows/`; GHA E2E **not** enabled; one-time `gh auth refresh -h github.com -s workflow` |
| Agent B — Browser | **DONE** | `BLOCKED_EXTERNAL`; no screenshots; exact blockers in `T6-BROWSER-NOTES.md` |
| Agent C — Provenance | **DONE** | Provenance `UNAVAILABLE_NOT_DEPLOYED`; restore eng CLOSED; no FRESH_INSTALL |
| Agent D — Docs | **DONE** | Classes/SHA/MF wording reconciled; verdict remains `RC1_BLOCKED` |

### Evidence paths

- `docs/ai/rc1/evidence/rc1_gate_report.json`
- `docs/ai/rc1/evidence/tr1_acceptance.json` / `tr2_acceptance.json`
- `docs/ai/rc1/evidence/mf1_acceptance.json` / `mf1_e2e.json`
- `docs/ai/rc1/evidence/sys1_acceptance.json`
- `docs/ai/rc1/evidence/ops1_acceptance.json`
- `docs/ai/rc1/evidence/ops1_backup_restore_closure.json`
- `docs/ai/rc1/evidence/agent_a_gha_status.json`
- `docs/ai/rc1/evidence/agent_b_browser_status.json`
- `docs/ai/rc1/evidence/agent_c_provenance_status.json`
- `docs/ai/rc1/T6-BROWSER-NOTES.md`
- `docs/ai/rc1/T5-VALUATION-DECISION.md`
