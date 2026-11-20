# RC1 P1 Closure Status Board

**Branch:** `fix/rc1-stabilization-p1-closure`  
**Base SHA:** `2a30f5abac6291af2e6d3ccf1f87446335c84bad`  
**Production:** `NOT_DEPLOYED`

| Blocker ID | Owner | Repro | Fix branch | Test | Integration | Remaining dependency |
|------------|-------|-------|------------|------|-------------|----------------------|
| RC1-P1-005 MF concurrency | T1/T2 → T0 | `test_06` reservation race | same | **PASS** concurrent issue (1 ok + 1 WMS code reject; on_hand A=4) | absorbed | none (engineering) |
| RC1-P1-005 Trading Persian | T3 → T0 | `تکراری` / Unicode decode | same | **PASS** `P2P_SUPPLIER_INVOICE_DUPLICATE` + NFC Persian | absorbed | none (engineering) |
| RC1-P1-004 Browser SYS-1 | T6 | authenticated RTL walkthrough | same | **BLOCKED_EXTERNAL** | n/a | staging-like HTTP + login + screenshots |
| RC1-P1-001 OPS provenance | T7/User | no deploy dump | same | restore **PASS**; provenance **OPEN** | n/a | owner dump **or** written FRESH_INSTALL confirmation |
| RC1-P1-005 OPS restore | T7 | privileged restore | same | **PASS** (`ops1_backup_restore_closure.json`) | absorbed | none (engineering) |
| P2-002 PR #29 | T0 | late T1 vs release; CONFLICTING | close/supersede | lock ideas absorbed | document | close after stabilization merge |

**PR #29:** OPEN vs `release/erpsmart-rc1`, CONFLICTING; tip `967df78`. Safe lock/DDL-outside-TX ideas absorbed into this branch; **close as superseded** after closure PR merges.

**Mandatory gates (local, this closure):**
- `python scripts/rc1_ci_mysql_php_gate.py` → PASS
- `python scripts/rc1_acceptance_gate.py --strict-pass` (+ `RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED`) → script PASS=5 (independent acceptance still **RC1_BLOCKED** on browser + provenance honesty)
- `python scripts/release_gate.py --require-php --require-node` → run on tip
- Proposed CI workflow `docs/ai/rc1/ci/rc1-mysql-php-gate.yml` (install to `.github/workflows/` with workflow-scoped token); `scripts/rc1_ci_mysql_php_gate.py` fails on skip

**Independent verdict:** `RC1_BLOCKED` — engineering P1-005 closed; SYS-1 browser + OPS-1 provenance remain external. `NOT_DEPLOYED`.
