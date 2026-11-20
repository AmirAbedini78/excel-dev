# RC1 Evidence Index

**Baseline SHA:** `2a30f5abac6291af2e6d3ccf1f87446335c84bad`  
**Closure branch:** `fix/rc1-stabilization-p1-closure`  
**Environment:** Windows system-2; Docker MySQL + `erpsmart-o2c-php:8.2`  
**Secrets:** redacted — default disposable `p2p` / test DB only; never production.

## Sandbox identity (beyond DB name)

| Field | Value |
|-------|-------|
| Container name | `erpsmart-p2p-mysql-test` |
| Container ID | `02eb215a87f6a726cf4efb1546f4b09865c9eb6eec9de1e9dc0e6b3163672db6` |
| Image | `mysql:8.0` |
| Host port | `3307` → 3306 |
| `@@server_uuid` | `a73a8a84-e4bd-11f1-ad33-7e073bee960b` |
| PHP runner | Docker `erpsmart-o2c-php:8.2` |

## Commands

| Purpose | Command | Result |
|---------|---------|--------|
| RC1 MySQL CI gate (fail-on-skip) | `python scripts/rc1_ci_mysql_php_gate.py` | **PASS** |
| RC1 required gate | `RC1_REQUIRED=1 RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED python scripts/rc1_acceptance_gate.py --strict-pass` | exit 0; PASS=5 script-level |
| Commercial source+E2E local | `python scripts/release_gate.py --require-php --require-node` | tip run (see `release_gate_closure_final.log`) |
| Disposable restore closure | privileged dump/restore + migrate×2 | **PASS** `ops1_backup_restore_closure.json` |

## CI URLs

- PR #30 checks: https://github.com/AmirAbedini78/excel-dev/actions/runs/44770790058  
- Push main: https://github.com/AmirAbedini78/excel-dev/actions/runs/44770814148  
- Mandatory RC1 MySQL workflow: `docs/ai/rc1/ci/rc1-mysql-php-gate.yml`  

## Artifacts (`docs/ai/rc1/evidence/`)

| File | Role |
|------|------|
| `rc1_gate_report.json` | Independent gate rollup |
| `tr1_acceptance.json` / `tr1_sandbox.json` | TR-1 |
| `tr2_acceptance.json` | TR-2 |
| `mf1_acceptance.json` / `mf1_e2e.json` / `mf1_probe.json` | MF-1 |
| `sys1_acceptance.json` | SYS-1 profile smoke (not browser) |
| `ops1_acceptance.json` | OPS-1 (provenance UNAVAILABLE) |
| `ops1_backup_restore_closure.json` | Privileged restore + migrate×2 PASS |
| `ops1_closure_backup.sql` | Disposable dump artifact (sha256 in JSON) |
| `release_gate_closure_final.log` | Local release_gate tip evidence |
| `rc1_acceptance_gate_closure.log` | Acceptance gate tip evidence |
| `sandbox_verified.json` | Sandbox verify helper output |

## Browser / screenshots

**NOT_RUN** — `T6-BROWSER-NOTES.md`: `BLOCKED_EXTERNAL`; no files under `docs/ai/rc1/screenshots/`.

## Classification legend used

`PASS_REPRODUCED` | `PASS_EXISTING_EVIDENCE_VERIFIED` | `FAIL_REPRODUCED` | `NOT_RUN` | `SKIPPED` | `BLOCKED_EXTERNAL`
