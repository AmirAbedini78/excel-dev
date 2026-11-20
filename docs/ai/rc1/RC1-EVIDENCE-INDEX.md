# RC1 Evidence Index

**SHA under test:** `6da6a03796e1f89b5279424899adad5b5a23915c`  
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
| RC1 required gate | `RC1_REQUIRED=1 … python scripts/rc1_acceptance_gate.py --strict-pass` | exit 0; PASS=5 script-level |
| Commercial source gate (CI) | `python scripts/release_gate.py --require-php --require-node` on Actions | SUCCESS run 44770814148 |
| Commercial source gate (local+Docker E2E) | same | exit 1; 2 FAIL |
| Disposable dump | `mysqldump … erpsmart_rc1_tr1_test` inside container | 43422 bytes; sha256 `11BCA2A9…` |

## CI URLs

- PR #30 checks: https://github.com/AmirAbedini78/excel-dev/actions/runs/44770790058  
- Push main: https://github.com/AmirAbedini78/excel-dev/actions/runs/44770814148  
- PR #30: https://github.com/AmirAbedini78/excel-dev/pull/30  

## Artifacts (`docs/ai/rc1/evidence/`)

| File | Role |
|------|------|
| `rc1_gate_report.json` | Independent gate rollup |
| `tr1_acceptance.json` / `tr1_sandbox.json` | TR-1 |
| `tr2_acceptance.json` | TR-2 |
| `mf1_acceptance.json` / `mf1_e2e.json` / `mf1_probe.json` | MF-1 |
| `sys1_acceptance.json` | SYS-1 profile smoke |
| `ops1_acceptance.json` | OPS-1 (provenance UNAVAILABLE) |
| `ops1_tr1_backup.sql` | Disposable dump artifact |
| `ops1_backup_restore_drill.json` | Restore drill result (PARTIAL) |
| `sandbox_verified.json` | Sandbox verify helper output |

## Browser / screenshots

**NOT_RUN** — T6 notes: no authenticated local server walkthrough; no files under `docs/ai/rc1/screenshots/`.

## Classification legend used

`PASS_REPRODUCED` | `PASS_EXISTING_EVIDENCE_VERIFIED` | `FAIL_REPRODUCED` | `NOT_RUN` | `SKIPPED` | `BLOCKED_EXTERNAL`
