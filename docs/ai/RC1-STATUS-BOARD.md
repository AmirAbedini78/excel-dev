# RC1 Status Board

**Updated:** 2026-11-20 (G5 exit)  
**Integration:** `release/erpsmart-rc1`  
**Exit:** `RC1_ACCEPTED` — feature scope frozen; **NOT_DEPLOYED**

## Gate status
| Gate | Status | Notes |
|------|--------|-------|
| G0 inventory + freeze | PASS | Ownership + shared contracts frozen; worktrees created; MySQL/PHP sandbox verified |
| G1 parallel lanes | PASS | T1–T7 delivered in dedicated worktrees |
| G2 integration | PASS | Merged T2→T5→T1→T3/T4/T6→T7 into release |
| G3 freeze | PASS | Scope frozen after contracts met; stabilize-only |
| G4 acceptance | PASS | 5/5 PHP+MySQL gate on disposable sandbox |
| G5 release PR | IN_PROGRESS | Open `release/erpsmart-rc1` → `main`; NO DEPLOY |

## Acceptance contracts
| ID | Status | Evidence |
|----|--------|----------|
| TR-1 Domestic procurement | **PASS** | `docs/ai/rc1/evidence/tr1_acceptance.json` |
| TR-2 Sales | **PASS** | `docs/ai/rc1/evidence/tr2_acceptance.json` |
| MF-1 Production | **PASS** | `docs/ai/rc1/evidence/mf1_acceptance.json`, `mf1_e2e.json` |
| SYS-1 Generality | **PASS** | `docs/ai/rc1/evidence/sys1_acceptance.json` |
| OPS-1 Release | **PASS** | `docs/ai/rc1/evidence/ops1_acceptance.json` (provenance `UNAVAILABLE_NOT_DEPLOYED`) |

Gate rollup: `docs/ai/rc1/evidence/rc1_gate_report.json` — PASS=5.

## Open P0/P1
**None** for RC1 exit. Historical: `docs/ai/rc1/defects/` (RESOLVED).

## Runtime
- DATABASE sandbox: VERIFIED (`erpsmart-p2p-mysql-test` / `02eb215a87f6…` / port `3307`)
- PHP: Docker `erpsmart-o2c-php:8.2`
- Staging/prod: **NOT_DEPLOYED**

## Lane PR tracker
| Lane | Branch | PR | Status |
|------|--------|----|--------|
| T1 | feature/rc1-t1-manufacturing | #23 | MERGED |
| T2 | feature/rc1-t2-warehouse | #21 | MERGED |
| T3 | feature/rc1-t3-trading | #24 | MERGED |
| T4 | feature/rc1-t4-iran-first | #19 | MERGED |
| T5 | feature/rc1-t5-financial | #22 | MERGED |
| T6 | feature/rc1-t6-platform-ux | #20 | MERGED |
| T7 | feature/rc1-t7-qa-release | #25 | MERGED |

Duplicate late PRs #26/#27/#28 superseded / closed.
