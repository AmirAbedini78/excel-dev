# RC1 Blockers and Remediation

**Independent open P0:** **0**  
**Independent open P1 (blocking RC1 acceptance):** **2** (provenance + GHA MySQL workflow not installed)  
**Independent open P2:** **1** (optional staging browser — not claimed STAGING_VERIFIED)  
**Engineering product SHA (PR #32):** `52fa7f12f465653d6e445d484f1a8dda48215f45`  
**Docs tip after PR #33:** `7455b7074c05c0ba63683fc8e435d3db6a069620`  
**Verdict impact:** `RC1_BLOCKED` — `NOT_DEPLOYED`

## Contract class summary (independent)

| Contract | Class | Residual |
|----------|-------|----------|
| TR-1 | **PASS_REPRODUCED** | none blocking |
| TR-2 | **PASS_REPRODUCED** | none blocking |
| MF-1 | **PASS_REPRODUCED** | Valid residual: WIP/FG valuation not fully supported; `UNVALUED` / GL `BLOCKED`; no auto GL |
| SYS-1 | **PASS_EXISTING_EVIDENCE_VERIFIED** + **LOCAL_BROWSER_VERIFIED** | Not `STAGING_VERIFIED` (no staging URL) |
| OPS-1 | **BLOCKED_EXTERNAL** | Provenance UNAVAILABLE; restore eng **CLOSED**; local browser closed separately |

## P1 — Blocking (independent acceptance)

### RC1-P1-001 — OPS-1 production/staging schema provenance unavailable

| Field | Value |
|-------|-------|
| Severity | **P1** |
| Contract | OPS-1 |
| Class | **BLOCKED_EXTERNAL** |
| Owner | User/staging ops |
| Status | **OPEN** (engineering restore **CLOSED**) |

**Repro:** No anonymized staging/prod dump id provided; campaign `NOT_DEPLOYED`. Owner has **not** confirmed never-installed → provenance class **UNAVAILABLE** → **do not invent** `FRESH_INSTALL`.

**Closure progress:** Disposable dump/restore with privileged admin **CLOSED** (`ops1_backup_restore_closure.json` + Agent C reverify `ops1_backup_restore_reverify.json`); migrate×2 + rollback restore **PASS**. Full Path1/Path2 wording: `OPS1-OWNER-ACTIONS.md`.

**Owner Path1 (only if true):** paste exact English confirmation in `OPS1-OWNER-ACTIONS.md` (or FA equivalent: *«تأیید می‌کنم ERPSMART/excel-dev هرگز روی staging/production نصب/استقرار نشده؛ provenance می‌تواند FRESH_INSTALL باشد.»*).

**Owner Path2:** authorized anonymized dump + SHA-256 + `RC1_PROD_SCHEMA_PROVENANCE=<id>` + disposable restore/migrate×2 rehearsal (checklist in `OPS1-OWNER-ACTIONS.md`).

### RC1-P1-004 — SYS-1 / OPS-1 browser login+RTL walkthrough

| Field | Value |
|-------|-------|
| Severity | **P1 → CLOSED (local)** |
| Contract | SYS-1 + OPS-1 browser |
| Class | **LOCAL_BROWSER_VERIFIED** (not `STAGING_VERIFIED`) |
| Owner | Agent B / T0 integrated |
| Status | **CLOSED** for local disposable UAT; staging residual optional P2 |

**Evidence:** `T6-BROWSER-NOTES.md`, `agent_b_browser_status.json`, real screenshots under `docs/ai/rc1/evidence/browser/` (login/logout, trading+mfg cockpits, RTL/Jalali, IRR/Toman, isolation, limited role, CSRF 419, empty/blocked). Local stack: `erpsmart-rc1-browser-uat:8765` + DB `erpsmart_rc1_browser_uat`. Do **not** invent `STAGING_VERIFIED`.

### RC1-P1-006 — Mandatory GHA MySQL+PHP workflow not installed on Actions

| Field | Value |
|-------|-------|
| Severity | **P1** |
| Contract | OPS-1 / CI gate |
| Class | **BLOCKED_EXTERNAL** |
| Owner | Repo owner (OAuth `workflow` scope) |
| Status | **OPEN** |

**Evidence:** `agent_a_gha_status.json`. `.github/workflows/` contains only `commercial-mvp-gate.yml`. Proposed mandatory gate lives at `docs/ai/rc1/ci/rc1-mysql-php-gate.yml` (Linux `O2C_DB_HOST=127.0.0.1`). Token scopes: `gist`, `read:org`, `repo` — **no `workflow`**.

**One-time owner auth:** `gh auth refresh -h github.com -s workflow`  
Then copy YAML → `.github/workflows/rc1-mysql-php-gate.yml`, push, and obtain a real Actions **SUCCESS** before claiming GHA E2E enabled. Local green ≠ GHA green. Real run today: **NOT_RUN**.

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
