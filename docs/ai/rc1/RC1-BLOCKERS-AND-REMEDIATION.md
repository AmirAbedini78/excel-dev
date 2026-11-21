# RC1 Blockers and Remediation

**Independent open P0:** **0**  
**Independent open P1 (blocking RC1 acceptance):** **1** (RC1-P1-001 Path2 data recon + owner attestation)  
**Independent open P2:** **1** (optional staging browser — not claimed STAGING_VERIFIED)  
**Verified `origin/main`:** `c5c644c386cbedde788c4e12e0585c43dc1b5a5d` (PR #35)  
**Candidate tip (PR #36):** `03728b145e67f0f6fe2179fe6a7e1df0badad8e5`  
**Verdict impact:** `RC1_BLOCKED` — `EXISTING_DEPLOYMENT_CONFIRMED` + `LEGACY_SCHEMA_UNVERIFIED`  
**Do not invent `FRESH_INSTALL` or `SCHEMA_UPGRADE_TESTED`.**

## Contract class summary (independent)

| Contract | Class | Residual |
|----------|-------|----------|
| TR-1 | **PASS_REPRODUCED** | none blocking |
| TR-2 | **PASS_REPRODUCED** | none blocking |
| MF-1 | **PASS_REPRODUCED** | Valid residual: WIP/FG valuation not fully supported; `UNVALUED` / GL `BLOCKED`; no auto GL |
| SYS-1 | **PASS_EXISTING_EVIDENCE_VERIFIED** + **LOCAL_BROWSER_VERIFIED** | Not `STAGING_VERIFIED` (no staging URL) |
| OPS-1 | **BLOCKED_EXTERNAL** | Provenance UNAVAILABLE; restore eng **CLOSED**; local browser closed separately |

## P1 — Blocking (independent acceptance)

### RC1-P1-001 — OPS-1 prior-deployment schema provenance / upgrade rehearsal

| Field | Value |
|-------|-------|
| Severity | **P1** |
| Contract | OPS-1 |
| Class | **BLOCKED_EXTERNAL** |
| Owner | User/staging ops (Path2 anonymized data) / T0 (rehearsal) |
| Status | **OPEN** — `EXISTING_DEPLOYMENT_CONFIRMED` + `LEGACY_SCHEMA_UNVERIFIED` |

**Owner update (2026-11-21):** Prior ERPSMART install on server/cPanel **confirmed** → Path1 `FRESH_INSTALL` is **invalid**. Campaign label `NOT_DEPLOYED` must not be re-used as fresh-install proof.

**Candidate artifact:** owner path `zzflgmfd_excel2.sql` SHA-256 `2D695C068C…419EC` — phpMyAdmin schema+data; **UNSAFE_UNREDACTED_SECRETS_AND_PII** (not in Git). Details: `LEGACY-DEPLOYMENT-PROVENANCE.md`.

**Closure progress:**
- Engineering disposable restore (synthetic) still **CLOSED** (`ops1_backup_restore_closure.json`).
- **Structural** legacy schema-only sanitize → restore → migrate×2 → rollback restore **PASS** (`ops1_legacy_schema_structural_rehearsal.json`). Tables 110→182.
- Offline schema-aware anonymization of candidate **PASS** + leak_scan PASS — SHA-256 `F3B92C0088…41DE2` (`ops1_legacy_anonymization.json`); dump **not** in Git.
- **Data** restore → migrate×2 → voucher/stock reconciliation: **NOT_RUN** this pass (local disposable MySQL sandbox was zombie-recreated and still initializing; no silent financial rewrite claimed).
- **Owner attestation** linking candidate dump to prior deployment: **NOT_PROVIDED**.
- **Not claimed:** `SCHEMA_UPGRADE_TESTED`.

**Owner Path2 (required to close):** written attestation that `zzflgmfd_excel2.sql` / anon SHA is the accepted prior-deploy artifact + `RC1_PROD_SCHEMA_PROVENANCE=<id>`; then complete disposable data reconciliation. **Do not** invent Path1.

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
| Class | **GHA_E2E_ENABLED** on candidate SHA |
| Owner | T0 |
| Status | **CLOSED_ON_CANDIDATE_SHA** — merge PR #36 to land on `main` |

**Evidence:** `agent_a_gha_status.json`. Installed `.github/workflows/rc1-mysql-php-gate.yml` with dual host (`O2C_DB_HOST=127.0.0.1`, `O2C_DB_HOST_PHP=host.docker.internal`) + fail-on-skip. Real Actions **SUCCESS**: https://github.com/AmirAbedini78/excel-dev/actions/runs/44894856555 on `03728b145e67…`. Local green ≠ claim basis — GHA SUCCESS is the claim basis.

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
