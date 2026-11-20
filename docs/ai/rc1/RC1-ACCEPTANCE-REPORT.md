# RC1 Acceptance Report

**Lane:** T7 Independent QA  
**Baseline / G0 SHA:** `921b6dac631763ce8fc772fe54cbc7d1e92a87a0`  
**Branch:** `feature/rc1-t7-qa-release`  
**Report date:** 2026-11-20  
**Gate command:** `python scripts/rc1_acceptance_gate.py`  
**DATABASE_TESTED:** see evidence `docs/ai/rc1/evidence/sandbox_verified.json` / gate report  

## Verdict (campaign)

**RC1_BLOCKED** — not all five contracts PASS. Mandatory E2E harness exists; MF-1 and OPS-1 remain BLOCKED with owner defects. Commercial MVP Gate alone is **insufficient**.

## Contract matrix

| ID | Status | Evidence | Notes |
|----|--------|----------|-------|
| TR-1 Domestic procurement | PENDING_RUN → filled after gate | `evidence/tr1_acceptance.json`, `evidence/tr1_legacy_e2e_1023.log` | Real PHP AP/P2P domains; amounts PO10@1000 → GR6 → AP6000 → pay2500+3500 |
| TR-2 Sales / AR settle | PENDING_RUN → filled after gate | `evidence/tr2_acceptance.json` | Real PHP O2C/AR; invoice 1000 IRR; receipts 400+600 |
| MF-1 Production | **BLOCKED** | `evidence/mf1_acceptance.json`, defects RC1-P0-001/002 | No issue/FG stock API; no costing resolver |
| SYS-1 Generality | PENDING_RUN → filled after gate | `evidence/sys1_acceptance.json` | Service/retail profile smoke; no forced production |
| OPS-1 Release | **BLOCKED** | `evidence/ops1_acceptance.json`, RC1-P1-001 | No prod/staging schema provenance; disposable migrate only |

## Runtime identity

| Field | Value |
|-------|-------|
| MySQL container | `erpsmart-p2p-mysql-test` |
| Container ID | `02eb215a87f6a726cf4efb1546f4b09865c9eb6eec9de1e9dc0e6b3163672db6` |
| Port / DB | `3307` / `erpsmart_p2p_test` |
| PHP | Docker `erpsmart-o2c-php:8.2` |
| cPanel / prod | **NOT_RUN / FORBIDDEN** |

## CI

| Workflow | Role |
|----------|------|
| `commercial-mvp-gate.yml` | Source/runtime only — **not** RC1 E2E proof |
| `rc1-acceptance.yml` | Additive RC1 gate; SKIP/missing deps fail when `RC1_ACCEPTANCE_REQUIRED=true` |

## Open P0/P1

See `docs/ai/rc1/defects/`. Residual list maintained in `T7-HANDOFF.md`.

## Sign-off

| Role | Decision | Signature |
|------|----------|-----------|
| T7 QA | **REJECT RC1 exit** until 5/5 PASS and 0 P0/P1 | AmirHosein / T7 agent 2026-11-20 |
| T0 Integration | pending | — |
| User deploy auth | **NOT REQUESTED** | — |
