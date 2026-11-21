# RC1 Evidence Index

**Pre-closure baseline SHA:** `2a30f5abac6291af2e6d3ccf1f87446335c84bad`  
**Final `main` SHA:** `2c1cf6592f246553c66c3bc90d00385bf65d4c29` (PR #36 MERGED)  
**Historical intermediate:** PR #32 `52fa7f12…`  
**Environment:** Windows system-2; Docker MySQL + `erpsmart-o2c-php:8.2`  
**Secrets:** redacted — default disposable `p2p` / test DB only; never production.  
**Independent verdict:** `RC1_ACCEPTED` on `main` — production `NOT_DEPLOYED`; browser `LOCAL_BROWSER_VERIFIED` only

## Sandbox identity (beyond DB name)

| Field | Value |
|-------|-------|
| Container name | `erpsmart-p2p-mysql-test` |
| Container ID (Path2 data) | `9c00f40fc78a2d1b6cd6ea3b6cb6384c5295a04ffe5834eba487949a5e35833c` |
| Image | `mysql:8.0` |
| Host port | `3307` → 3306 |
| Network | bridge; datadir tmpfs |
| `@@server_uuid` | `ff0092ef-e5d1-11f1-9a87-2e6b1b469ce1` |
| PHP runner | Docker `erpsmart-o2c-php:8.2` |

## Contract independent classifications (must match evidence JSON)

| Contract | Independent class | Evidence JSON | Notes |
|----------|-------------------|---------------|-------|
| TR-1 | **PASS_REPRODUCED** | `tr1_acceptance.json` | Script PASS + balances |
| TR-2 | **PASS_REPRODUCED** | `tr2_acceptance.json` | Script PASS + settle `0.00` |
| MF-1 | **PASS_REPRODUCED** | `mf1_acceptance.json` | Lifecycle PASS; `cost_class=UNVALUED`; `gl_valuation=BLOCKED`; WIP/FG valuation not fully supported; no auto GL |
| SYS-1 | **PASS_EXISTING_EVIDENCE_VERIFIED** + **LOCAL_BROWSER_VERIFIED** | `sys1_acceptance.json` + `agent_b_browser_status.json` + `evidence/browser/` | Local UAT done; not STAGING_VERIFIED |
| OPS-1 | **PASS_PATH2_DISPOSABLE_REHEARSAL** | `ops1_legacy_data_rehearsal.json` + `ops1_legacy_data_reconciliation.json` + `agent_c_provenance_status.json` | Path2 anon restore+recon PASS; restore eng **CLOSED** |

## Commands

| Purpose | Command | Result |
|---------|---------|--------|
| RC1 MySQL CI gate (fail-on-skip) | `python scripts/rc1_ci_mysql_php_gate.py` | **PASS** (local) |
| RC1 required gate | `RC1_REQUIRED=1 RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED python scripts/rc1_acceptance_gate.py --strict-pass` | exit 0; PASS=5 script-level; independent still BLOCKED |
| Commercial source+E2E local | `python scripts/release_gate.py --require-php --require-node` | tip run (see `release_gate_closure_final.log`) |
| Disposable restore closure | privileged dump/restore + migrate×2 | **PASS** `ops1_backup_restore_closure.json` (eng CLOSED) |

## CI URLs (pin final SHA)

- Commercial MVP @ `2c1cf65…`: https://github.com/AmirAbedini78/excel-dev/actions/runs/44931684788 (**SUCCESS**, source-only)  
- RC1 MySQL+PHP @ `2c1cf65…`: https://github.com/AmirAbedini78/excel-dev/actions/runs/44931684872 (**SUCCESS**)  
- Mandatory RC1 MySQL workflow: `.github/workflows/rc1-mysql-php-gate.yml`  
- Historical candidate GHA: https://github.com/AmirAbedini78/excel-dev/actions/runs/44894856555 (`agent_a_gha_status.json`)  
- Staging readiness summary: `staging_readiness_t0_status.json` + `RC1-STAGING-READINESS-FA.md`

## Artifacts (`docs/ai/rc1/evidence/`)

| File | Role | Independent class |
|------|------|-------------------|
| `rc1_gate_report.json` | Independent gate rollup (script PASS=5) | n/a (rollup) |
| `tr1_acceptance.json` / `tr1_sandbox.json` | TR-1 | **PASS_REPRODUCED** |
| `tr2_acceptance.json` | TR-2 | **PASS_REPRODUCED** |
| `mf1_acceptance.json` / `mf1_e2e.json` / `mf1_probe.json` | MF-1 | **PASS_REPRODUCED** |
| `sys1_acceptance.json` | SYS-1 smoke + local browser class | **PASS_EXISTING_EVIDENCE_VERIFIED** / **LOCAL_BROWSER_VERIFIED** |
| `ops1_acceptance.json` | OPS-1 historical script rollup | superseded by Path2 rehearsal |
| `ops1_legacy_anonymization.json` | Offline anonymize + leak_scan | **PASS** |
| `ops1_legacy_data_rehearsal.json` | Anon restore + migrate×2 + recovery | **PASS** |
| `ops1_legacy_data_reconciliation.json` | Financial/stock pre/post recon | **PASS** |
| `ops1_backup_restore_closure.json` | Privileged restore + migrate×2 | eng **CLOSED** |
| `ops1_closure_backup.sql` | Disposable dump artifact (sha256 in JSON) | — |
| `agent_a_gha_status.json` | GHA install/auth status | GHA **SUCCESS** / P1-006 CLOSED |
| `agent_b_browser_status.json` | Browser UAT status | **LOCAL_BROWSER_VERIFIED** |
| `browser/*.png` + logs | Genuine local UAT screenshots | **LOCAL_BROWSER_VERIFIED** |
| `agent_c_provenance_status.json` | Provenance Path2 | **PASS_PATH2_DISPOSABLE_REHEARSAL** |
| `ops1_backup_restore_reverify.json` | Re-verify privileged restore+migrate×2 | eng CLOSED (reconfirm) |
| `../OPS1-OWNER-ACTIONS.md` | Path1 confirmation text / Path2 dump checklist | owner action |
| `release_gate_closure_final.log` | Local release_gate tip evidence | — |
| `rc1_acceptance_gate_closure.log` | Acceptance gate tip evidence | — |
| `sandbox_verified.json` | Sandbox verify helper output | — |

## Browser / screenshots

**LOCAL_BROWSER_VERIFIED** — `T6-BROWSER-NOTES.md` + `docs/ai/rc1/evidence/browser/` (9 PNGs). **Not** `STAGING_VERIFIED` (no staging URL).

## Classification legend used

`PASS_REPRODUCED` | `PASS_EXISTING_EVIDENCE_VERIFIED` | `FAIL_REPRODUCED` | `NOT_RUN` | `SKIPPED` | `BLOCKED_EXTERNAL`
