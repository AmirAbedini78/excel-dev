# RC1 P1 Closure Status Board

**Branch:** `fix/rc1-stabilization-p1-closure` → merged **PR #32**  
**Pre-closure base SHA:** `2a30f5abac6291af2e6d3ccf1f87446335c84bad`  
**Final main SHA:** `52fa7f12f465653d6e445d484f1a8dda48215f45`  
**Production:** `NOT_DEPLOYED`  
**Independent verdict:** `RC1_ACCEPTED` on candidate PR #36 (merge to `main` still pending; production `NOT_DEPLOYED`)

## Contract matrix (independent)

| Contract | Independent class | Notes |
|----------|-------------------|-------|
| TR-1 | **PASS_REPRODUCED** | `tr1_acceptance.json` |
| TR-2 | **PASS_REPRODUCED** | `tr2_acceptance.json` |
| MF-1 | **PASS_REPRODUCED** | Lifecycle tested; WIP/FG valuation not fully supported; `UNVALUED` / GL `BLOCKED`; no unsupported auto GL |
| SYS-1 | **PASS_EXISTING_EVIDENCE_VERIFIED** + **LOCAL_BROWSER_VERIFIED** | Local UAT screenshots; not STAGING_VERIFIED |
| OPS-1 | **PASS_PATH2_DISPOSABLE_REHEARSAL** | Path2 anon restore + migrate×2 + recon PASS; restore eng **CLOSED** |

## Blocker board

| Blocker ID | Owner | Status | Notes |
|------------|-------|--------|-------|
| RC1-P1-005 MF concurrency | T0 | **CLOSED** | test_06 PASS; WMS codes; on_hand A=4 |
| RC1-P1-005 Trading Persian | T0 | **CLOSED** | `P2P_SUPPLIER_INVOICE_DUPLICATE` + Persian |
| RC1-P1-005 OPS restore | T0 | **CLOSED** | privileged restore+migrate×2 PASS |
| RC1-P1-004 Browser SYS-1 | Agent B / T0 | **CLOSED (local)** | `LOCAL_BROWSER_VERIFIED`; staging residual P2 |
| RC1-P1-001 OPS provenance | User + T0 | **CLOSED** | Path2 anon restore+migrate×2+financial/stock recon PASS; `SCHEMA_UPGRADE_TESTED` |
| RC1-P1-006 GHA MySQL workflow | T0 | **CLOSED_ON_CANDIDATE_SHA** | GHA SUCCESS https://github.com/AmirAbedini78/excel-dev/actions/runs/44894856555 ; merge PR #36 |
| P2-002 PR #29 | T0 | **CLOSED** | superseded by PR #32; PR #29 closed |

## Peer agent lanes (final)

| Agent | Scope | Status |
|-------|-------|--------|
| **Agent A** | GHA / mandatory RC1 MySQL workflow | **DONE** — installed; GHA SUCCESS; P1-006 **CLOSED_ON_CANDIDATE_SHA** |
| **Agent B** | Authenticated browser RTL/login | **DONE** — **LOCAL_BROWSER_VERIFIED**; `evidence/browser/` |
| **Agent C** | Provenance / migration | **DONE** — Path2 data rehearsal **PASS**; P1-001 **CLOSED**; `SCHEMA_UPGRADE_TESTED` |
| **Agent D** | Docs/acceptance reconciliation | **DONE** — SHA/classes/MF wording aligned |

**Gates:** local `rc1_ci_mysql_php_gate` PASS; GHA RC1 MySQL+PHP SUCCESS on PR #36; Path2 data recon PASS.

**Independent verdict:** `RC1_ACCEPTED` on candidate (merge PR #36 to land on `main`)
