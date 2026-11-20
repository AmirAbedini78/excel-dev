# RC1 Acceptance Report

**Lane:** T7 Independent QA  
**Baseline / G0 SHA:** `921b6dac631763ce8fc772fe54cbc7d1e92a87a0`  
**Branch:** `feature/rc1-t7-qa-release`  
**Report date:** 2026-11-20  
**Gate command:** `O2C_DB_NAME=erpsmart_rc1_t7_test RC1_REQUIRED=1 python scripts/rc1_acceptance_gate.py --strict-pass`  
**DATABASE_TESTED:** **true** (`docs/ai/rc1/evidence/rc1_gate_report.json`)

## Verdict (campaign)

**RC1_BLOCKED** — 3/5 contracts PASS; MF-1 and OPS-1 **BLOCKED** with owner defects. Commercial MVP Gate alone is **insufficient**.

## Contract matrix

| ID | Status | Evidence | Notes |
|----|--------|----------|-------|
| TR-1 Domestic procurement | **PASS** | `evidence/tr1_acceptance.json`, gate report | Real PHP AP/P2P; PO10@1000 → GR6 → AP6000 → pay2500+3500 → 0; 1 stock move; 0 receipt GL |
| TR-2 Sales / AR settle | **PASS** | `evidence/tr2_acceptance.json` | Real PHP O2C/AR; invoice 1000 IRR; receipts 400+600 → 0 |
| MF-1 Production | **BLOCKED** | `evidence/mf1_acceptance.json`, RC1-P0-001/002 | No issue/FG stock API; no costing resolver; pilot BOM only |
| SYS-1 Generality | **PASS** | `evidence/sys1_acceptance.json` | Service/retail profile smoke; no forced production; IRR functional |
| OPS-1 Release | **BLOCKED** | `evidence/ops1_acceptance.json`, RC1-P1-001 | No prod/staging schema provenance; disposable migrate only |

## Runtime identity

| Field | Value |
|-------|-------|
| MySQL container | `erpsmart-p2p-mysql-test` |
| Container ID | `02eb215a87f6a726cf4efb1546f4b09865c9eb6eec9de1e9dc0e6b3163672db6` |
| Port | `3307` |
| T7 schema | `erpsmart_rc1_t7_test` (isolated within verified container) |
| PHP | Docker `erpsmart-o2c-php:8.2` |
| cPanel / prod | **NOT_RUN / FORBIDDEN** |

## CI

| Workflow | Role | Status |
|----------|------|--------|
| `commercial-mvp-gate.yml` | Source/runtime only | unchanged semantics |
| `rc1-acceptance.yml` | Additive RC1 gate | added; hard-fail when `RC1_ACCEPTANCE_REQUIRED=true` |

Gate exit on this run: **2** (BLOCKED), not 0.

## Open P0/P1

| ID | Owner | Issue |
|----|-------|-------|
| RC1-P0-001 | T1/T2 | MF-1 stock API missing |
| RC1-P0-002 | T5 | Valuation resolver missing |
| RC1-P0-003 | T7 | Mitigated by `rc1_acceptance_gate.py` + workflow (not closed until required CI green) |
| RC1-P1-001 | T0/T7 | OPS-1 schema provenance missing |

## Sign-off

| Role | Decision |
|------|----------|
| T7 QA | **REJECT RC1 exit** until 5/5 PASS and 0 P0/P1 |
| T0 Integration | pending |
| User deploy auth | **NOT REQUESTED** |
