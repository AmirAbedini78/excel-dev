# T7 Handoff — QA / Release Engineering

## Identity
- **Baseline SHA:** `921b6dac631763ce8fc772fe54cbc7d1e92a87a0` (G0)
- **Branch / worktree:** `feature/rc1-t7-qa-release` @ `C:\Projects\erp-worktrees\rc1-t7`
- **Author:** AmirHosein `<abedinnezhad1999@gmail.com>`
- **PR target:** `release/erpsmart-rc1`
- **Migrations owned:** none (harness/docs/CI only)

## Implemented
- RC1 acceptance suite scaffolding: TR-1, TR-2, MF-1, SYS-1, OPS-1
- Composite PHP runners (one Docker invocation per contract) against real domains
- `scripts/rc1_acceptance_gate.py` — RC1 REQUIRED; SKIP/missing deps → exit 1; BLOCKED → exit 2
- Additive `.github/workflows/rc1-acceptance.yml` + note in `release_gate.py`
- Release docs + defect tickets + evidence under `docs/ai/rc1/`
- Isolated disposable schema `erpsmart_rc1_t7_test` in verified sandbox container

## Non-claims
- Did **not** alter business domains to green tests
- Did **not** deploy cPanel/prod
- Did **not** invent MF-1 PASS or OPS-1 provenance
- Commercial MVP Gate remains source-only

## Acceptance results (this handoff)
| Contract | Status |
|----------|--------|
| TR-1 | **PASS** |
| TR-2 | **PASS** |
| MF-1 | **BLOCKED** |
| SYS-1 | **PASS** |
| OPS-1 | **BLOCKED** |

**DATABASE_TESTED:** true  
**Gate log:** `docs/ai/rc1/evidence/rc1_gate_run.log`  
**Gate report:** `docs/ai/rc1/evidence/rc1_gate_report.json`

### Commands
```powershell
$env:O2C_DB_NAME='erpsmart_rc1_t7_test'
$env:RC1_REQUIRED='1'
py -3 scripts/rc1_acceptance_gate.py --strict-pass
```

### Sandbox
- Container `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / port 3307
- PHP `erpsmart-o2c-php:8.2`

## Residual P0/P1
| ID | Sev | Owner | Next action |
|----|-----|-------|-------------|
| RC1-P0-001 | P0 | T1/T2 | Implement stock API; T7 retest MF-1 |
| RC1-P0-002 | P0 | T5 | Valuation resolver / BLOCKED policy |
| RC1-P0-003 | P0 | T7 | Keep required until CI hosts PHP image + MySQL parity |
| RC1-P1-001 | P1 | T0/T7 | Provide staging dump provenance |

## Integrator (T0) action
1. Merge T7 PR into `release/erpsmart-rc1` (QA infra only — safe early).
2. Do **not** treat Commercial MVP Gate green as RC1 acceptance.
3. Block RC exit while MF-1/OPS-1 BLOCKED or P0 open.
4. After T1/T2/T5 land APIs, re-run `rc1_acceptance_gate.py --strict-pass`.
