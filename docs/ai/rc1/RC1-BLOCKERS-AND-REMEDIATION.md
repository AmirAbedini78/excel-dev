# RC1 Blockers and Remediation

**Independent open P0:** **0**  
**Independent open P1 (blocking RC1 acceptance):** **0**  
**Independent open P2:** **1** (optional staging browser — not claimed STAGING_VERIFIED)  
**Verified `origin/main`:** `2c1cf6592f246553c66c3bc90d00385bf65d4c29` (PR #36 MERGED)  
**Verdict impact:** `RC1_ACCEPTED` on `main` — Path2 data rehearsal **PASS** + post-merge GHA Commercial + MySQL+PHP **SUCCESS**. Production **NOT_DEPLOYED**. Staging readiness ≠ `STAGING_VERIFIED` without owner remote auth.

## Contract class summary (independent)

| Contract | Class | Residual |
|----------|-------|----------|
| TR-1 | **PASS_REPRODUCED** | none blocking |
| TR-2 | **PASS_REPRODUCED** | none blocking |
| MF-1 | **PASS_REPRODUCED** | Valid residual: WIP/FG valuation not fully supported; `UNVALUED` / GL `BLOCKED`; no auto GL |
| SYS-1 | **PASS_EXISTING_EVIDENCE_VERIFIED** + **LOCAL_BROWSER_VERIFIED** | Not `STAGING_VERIFIED` (no staging URL) |
| OPS-1 | **PASS_PATH2_DISPOSABLE_REHEARSAL** | Anonymized restore + migrate×2 + financial/stock recon PASS; restore eng **CLOSED**; local browser closed separately |

## P1 — Blocking (independent acceptance)

### RC1-P1-001 — OPS-1 prior-deployment schema provenance / upgrade rehearsal

| Field | Value |
|-------|-------|
| Severity | **P1 → CLOSED** |
| Contract | OPS-1 |
| Class | **PASS_PATH2_DISPOSABLE_REHEARSAL** |
| Owner | User (Path2 dump) / T0 (rehearsal) |
| Status | **CLOSED** — `EXISTING_DEPLOYMENT_CONFIRMED` + `PATH2_ANON_SCHEMA_DATA_VERIFIED` + `SCHEMA_UPGRADE_TESTED` |

**Owner update (2026-11-21):** Prior ERPSMART install on server/cPanel **confirmed** → Path1 `FRESH_INSTALL` is **invalid**. Campaign dump used as Path2 (`prior-cpanel-anon-20261121`). Production **NOT_TOUCHED**.

**Candidate artifact:** owner path dump SHA-256 `2D695C068C…419EC` — **UNSAFE** (not in Git). Anonymized SHA-256 `F3B92C0088…41DE2`; leak_scan PASS. Details: `LEGACY-DEPLOYMENT-PROVENANCE.md`.

**Closure evidence:**
- Engineering disposable restore (synthetic) **CLOSED** (`ops1_backup_restore_closure.json`).
- Structural schema-only migrate×2 **PASS** (`ops1_legacy_schema_structural_rehearsal.json`) 110→182.
- Offline anonymization **PASS** (`ops1_legacy_anonymization.json`).
- Anonymized data restore → migrate×2 → financial/stock recon → recovery clone **PASS** (`ops1_legacy_data_rehearsal.json`, `ops1_legacy_data_reconciliation.json`). Dr=Cr `18063580000.00`; stock quantity/unit_cost preserved; mismatch_count=0. Baseline 110 untouched.
- Claim: **`SCHEMA_UPGRADE_TESTED`** (disposable Path2 only).

### RC1-P1-004 — SYS-1 / OPS-1 browser login+RTL walkthrough

| Field | Value |
|-------|-------|
| Severity | **P1 → CLOSED (local)** |
| Contract | SYS-1 + OPS-1 browser |
| Class | **LOCAL_BROWSER_VERIFIED** (not `STAGING_VERIFIED`) |
| Owner | Agent B / T0 integrated |
| Status | **CLOSED** for local disposable UAT; staging residual optional P2 |

**Evidence:** `T6-BROWSER-NOTES.md`, `agent_b_browser_status.json`, real screenshots under `docs/ai/rc1/evidence/browser/` (login/logout, trading+mfg cockpits, RTL/Jalali, IRR/Toman, isolation, limited role, CSRF 419, empty/blocked). Local stack: `erpsmart-rc1-browser-uat:8765` + DB `erpsmart_rc1_browser_uat`. Do **not** invent `STAGING_VERIFIED`.

### RC1-P1-006 — Mandatory GHA MySQL+PHP workflow

| Field | Value |
|-------|-------|
| Severity | **P1 → CLOSED (candidate)** |
| Contract | OPS-1 / CI gate |
| Class | **GHA_E2E_ENABLED** on final main SHA |
| Owner | T0 |
| Status | **CLOSED on main** — post-merge SUCCESS https://github.com/AmirAbedini78/excel-dev/actions/runs/44931684872 |

**Evidence:** `agent_a_gha_status.json` (candidate era) + post-merge runs on `2c1cf65…`. Installed `.github/workflows/rc1-mysql-php-gate.yml` with dual host + fail-on-skip. Local green ≠ claim basis — post-merge GHA SUCCESS is the claim basis.

## P1 — Engineering closed (on main via PR #32)

### RC1-P1-005 — Disposable backup restore + local release_gate E2E

| Field | Value |
|-------|-------|
| Severity | **P1** |
| Contract | OPS-1 / regression |
| Class | **CLOSED** (engineering) |
| Owner | T7 / T1 / T3 → T0 |
| Status | **CLOSED** on Final main `52fa7f12…` (merged PR #32) |

**Closed items:**
1. Privileged dump/restore + migrate×2 + rollback restore — PASS (`ops1_backup_restore_closure.json`).
2. `test_06_concurrent_issue_two_orders` — deterministic; WMS codes; on_hand A=4.
3. `test_tr1_exact_figures_and_cockpit_timeline` — `P2P_SUPPLIER_INVOICE_DUPLICATE` + Persian.
4. Local `scripts/rc1_ci_mysql_php_gate.py` fail-on-skip + isolated MF/trading DBs.
5. `release_gate.py` clears leftover `RC1_REQUIRED` for commercial discover.

## P2 — Non-blocking

| ID | Issue | Owner | Status |
|----|-------|-------|--------|
| P2-002 | Late T1 PR #29 CONFLICTING vs release | T0 | **CLOSED** — superseded by PR #32; PR #29 closed |

## P0

**None open.**

## Valid residual product states (do not weaken)

- MF lifecycle: tested / PASS_REPRODUCED
- MF valuation: WIP/FG **not fully supported** in RC1 — cost may be `UNVALUED`
- MF GL posting: `BLOCKED` / `GL_BLOCKED` — **no unsupported automatic WIP/FG GL** (`T5-VALUATION-DECISION.md`)
