# RC1 Evidence Index

**Pre-closure baseline SHA:** `2a30f5abac6291af2e6d3ccf1f87446335c84bad`  
**Final `main` SHA (PR #32 merged):** `52fa7f12f465653d6e445d484f1a8dda48215f45`  
**Closure branch:** `fix/rc1-stabilization-p1-closure` → **merged PR #32**  
**Environment:** Windows system-2; Docker MySQL + `erpsmart-o2c-php:8.2`  
**Secrets:** redacted — default disposable `p2p` / test DB only; never production.  
**Independent verdict:** `RC1_BLOCKED` — `NOT_DEPLOYED`

## Sandbox identity (beyond DB name)

| Field | Value |
|-------|-------|
| Container name | `erpsmart-p2p-mysql-test` |
| Container ID | `02eb215a87f6a726cf4efb1546f4b09865c9eb6eec9de1e9dc0e6b3163672db6` |
| Image | `mysql:8.0` |
| Host port | `3307` → 3306 |
| `@@server_uuid` | `a73a8a84-e4bd-11f1-ad33-7e073bee960b` |
| PHP runner | Docker `erpsmart-o2c-php:8.2` |

## Contract independent classifications (must match evidence JSON)

| Contract | Independent class | Evidence JSON | Notes |
|----------|-------------------|---------------|-------|
| TR-1 | **PASS_REPRODUCED** | `tr1_acceptance.json` | Script PASS + balances |
| TR-2 | **PASS_REPRODUCED** | `tr2_acceptance.json` | Script PASS + settle `0.00` |
| MF-1 | **PASS_REPRODUCED** | `mf1_acceptance.json` | Lifecycle PASS; `cost_class=UNVALUED`; `gl_valuation=BLOCKED`; WIP/FG valuation not fully supported; no auto GL |
| SYS-1 | **PASS_EXISTING_EVIDENCE_VERIFIED** + **LOCAL_BROWSER_VERIFIED** | `sys1_acceptance.json` + `agent_b_browser_status.json` + `evidence/browser/` | Local UAT done; not STAGING_VERIFIED |
| OPS-1 | **BLOCKED_EXTERNAL** | `ops1_acceptance.json` + `agent_c_provenance_status.json` | Provenance UNAVAILABLE; restore eng **CLOSED** |

## Commands

| Purpose | Command | Result |
|---------|---------|--------|
| RC1 MySQL CI gate (fail-on-skip) | `python scripts/rc1_ci_mysql_php_gate.py` | **PASS** (local) |
| RC1 required gate | `RC1_REQUIRED=1 RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED python scripts/rc1_acceptance_gate.py --strict-pass` | exit 0; PASS=5 script-level; independent still BLOCKED |
| Commercial source+E2E local | `python scripts/release_gate.py --require-php --require-node` | tip run (see `release_gate_closure_final.log`) |
| Disposable restore closure | privileged dump/restore + migrate×2 | **PASS** `ops1_backup_restore_closure.json` (eng CLOSED) |

## CI URLs

- PR #32 Commercial MVP (PR check): https://github.com/AmirAbedini78/excel-dev/actions/runs/44810526566  
- Final main push Commercial MVP @ `52fa7f12…`: https://github.com/AmirAbedini78/excel-dev/actions/runs/44810790482 (**SUCCESS**, source-only)  
- Mandatory RC1 MySQL workflow source (not installed): `docs/ai/rc1/ci/rc1-mysql-php-gate.yml`  
- **Agent A GHA MySQL+PHP:** **NOT_RUN** — not under `.github/workflows/`; owner auth `gh auth refresh -h github.com -s workflow` (`agent_a_gha_status.json`). **GHA E2E not enabled.**

## Artifacts (`docs/ai/rc1/evidence/`)

| File | Role | Independent class |
|------|------|-------------------|
| `rc1_gate_report.json` | Independent gate rollup (script PASS=5) | n/a (rollup) |
| `tr1_acceptance.json` / `tr1_sandbox.json` | TR-1 | **PASS_REPRODUCED** |
| `tr2_acceptance.json` | TR-2 | **PASS_REPRODUCED** |
| `mf1_acceptance.json` / `mf1_e2e.json` / `mf1_probe.json` | MF-1 | **PASS_REPRODUCED** |
| `sys1_acceptance.json` | SYS-1 smoke + local browser class | **PASS_EXISTING_EVIDENCE_VERIFIED** / **LOCAL_BROWSER_VERIFIED** |
| `ops1_acceptance.json` | OPS-1 (provenance UNAVAILABLE) | **BLOCKED_EXTERNAL** |
| `ops1_backup_restore_closure.json` | Privileged restore + migrate×2 | eng **CLOSED** |
| `ops1_closure_backup.sql` | Disposable dump artifact (sha256 in JSON) | — |
| `agent_a_gha_status.json` | GHA install/auth status | GHA **NOT_RUN** |
| `agent_b_browser_status.json` | Browser UAT status | **LOCAL_BROWSER_VERIFIED** |
| `browser/*.png` + logs | Genuine local UAT screenshots | **LOCAL_BROWSER_VERIFIED** |
| `agent_c_provenance_status.json` | Provenance Path1/Path2 | **BLOCKED_EXTERNAL** |
| `ops1_backup_restore_reverify.json` | Re-verify privileged restore+migrate×2 | eng CLOSED (reconfirm) |
| `../OPS1-OWNER-ACTIONS.md` | Path1 confirmation text / Path2 dump checklist | owner action |
| `release_gate_closure_final.log` | Local release_gate tip evidence | — |
| `rc1_acceptance_gate_closure.log` | Acceptance gate tip evidence | — |
| `sandbox_verified.json` | Sandbox verify helper output | — |

## Browser / screenshots

**LOCAL_BROWSER_VERIFIED** — `T6-BROWSER-NOTES.md` + `docs/ai/rc1/evidence/browser/` (9 PNGs). **Not** `STAGING_VERIFIED` (no staging URL).

## Classification legend used

`PASS_REPRODUCED` | `PASS_EXISTING_EVIDENCE_VERIFIED` | `FAIL_REPRODUCED` | `NOT_RUN` | `SKIPPED` | `BLOCKED_EXTERNAL`
