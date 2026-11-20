# RC1 Status Board

**Updated:** 2026-11-20 (G0)  
**Integration:** `release/erpsmart-rc1` @ baseline `db6d6e5eadd5fd7517c02d7df48b748ad2c47c9f`  
**Exit:** pending — target `RC1_ACCEPTED` or `RC1_BLOCKED` only

## Gate status
| Gate | Status | Notes |
|------|--------|-------|
| G0 inventory + freeze | PASS | Ownership + shared contracts frozen; worktrees created; MySQL/PHP sandbox verified |
| G1 parallel lanes | IN_PROGRESS | T1–T7 launched |
| G2 integration | PENDING | Merge T2/T5 → T1 → T3/T4/T6 |
| G3 freeze | PENDING | Max 2 stabilization rounds |
| G4 acceptance | PENDING | TR-1..OPS-1 evidence required |
| G5 release PR | PENDING | NO DEPLOY |

## Acceptance contracts
| ID | Status | Evidence |
|----|--------|----------|
| TR-1 Domestic procurement | PARTIAL_PREEXISTING | v10.23 E2E amounts exist; T3/T7 must re-prove on RC1 SHA |
| TR-2 Sales | PARTIAL_PREEXISTING | O2C/AR kernels exist; T7 must re-prove |
| MF-1 Production | NOT_STARTED | ProductionDomain pilot only (BOM/plan/preview) |
| SYS-1 Generality | NOT_STARTED | Profile smoke pending T4/T6/T7 |
| OPS-1 Release | NOT_STARTED | T7 migration/browser/security |

## Open P0/P1
| ID | Sev | Owner | Issue |
|----|-----|-------|-------|
| RC1-P0-001 | P0 | T1/T2 | No production issue/FG stock API yet |
| RC1-P0-002 | P0 | T5 | Manufacturing valuation resolver + GL BLOCKED policy not coded |
| RC1-P0-003 | P0 | T7 | CI release_gate does not mandatorily run PHP+MySQL E2E |

## Runtime
- DATABASE sandbox: VERIFIED (`erpsmart-p2p-mysql-test` / `02eb215a87f6…`)
- Host PHP: missing — use `erpsmart-o2c-php:8.2`
- Staging/prod: **NOT_DEPLOYED**

## Lane PR tracker
| Lane | Branch | PR | Status |
|------|--------|----|--------|
| T1 | feature/rc1-t1-manufacturing | — | pending |
| T2 | feature/rc1-t2-warehouse | — | pending |
| T3 | feature/rc1-t3-trading | — | pending |
| T4 | feature/rc1-t4-iran-first | — | pending |
| T5 | feature/rc1-t5-financial | — | pending |
| T6 | feature/rc1-t6-platform-ux | — | pending |
| T7 | feature/rc1-t7-qa-release | — | pending |
