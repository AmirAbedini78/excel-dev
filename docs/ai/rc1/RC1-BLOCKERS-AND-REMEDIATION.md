# RC1 Blockers and Remediation

**Independent open P0:** **0**  
**Independent open P1 (blocking RC1 acceptance):** **2** (browser + provenance)  
**Independent open P2:** **1** (PR #29 supersede pending close)  
**Verdict impact:** `RC1_BLOCKED`

## P1 — Blocking (independent acceptance)

### RC1-P1-001 — OPS-1 production/staging schema provenance unavailable

| Field | Value |
|-------|-------|
| Severity | **P1** |
| Contract | OPS-1 |
| Class | **BLOCKED_EXTERNAL** |
| Owner | User/staging ops |
| Status | **OPEN** (engineering restore closed) |

**Repro:** No anonymized staging/prod dump id provided; campaign `NOT_DEPLOYED`.

**Closure progress:** Disposable dump/restore with privileged admin **PASS** (`ops1_backup_restore_closure.json`); migrate×2 + rollback restore **PASS**. Provenance still unresolved — **do not invent** dump id; `FRESH_INSTALL` only after owner written confirmation that no prior deploy exists. Script may PASS under `RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED`; independent acceptance remains blocked until owner confirms.

### RC1-P1-004 — SYS-1 / OPS-1 browser login+RTL walkthrough missing

| Field | Value |
|-------|-------|
| Severity | **P1** |
| Contract | SYS-1 + OPS-1 |
| Class | **BLOCKED_EXTERNAL** |
| Owner | T6/T7 + user staging URL |
| Status | **OPEN** |

**Evidence:** `T6-BROWSER-NOTES.md` — host PHP missing; no authenticated staging URL; screenshots **NOT_RUN**. Code/PHP harness E2E is **not** a substitute.

## P1 — Engineering closed on stabilization branch

### RC1-P1-005 — Disposable backup restore + local release_gate E2E

| Field | Value |
|-------|-------|
| Severity | **P1** |
| Contract | OPS-1 / regression |
| Class | **CLOSED** (engineering) |
| Owner | T7 / T1 / T3 → T0 |
| Status | **CLOSED** on `fix/rc1-stabilization-p1-closure` |

**Closed items:**
1. Privileged dump/restore + migrate×2 + rollback restore — PASS (`ops1_backup_restore_closure.json`). Restricted `p2p` restore without grant — expected Access denied.
2. `test_06_concurrent_issue_two_orders` — deterministic fixture (o1 reserved / o2 force-released); WMS error codes; locking read absorb from PR #29; on_hand A=4.
3. `test_tr1_exact_figures_and_cockpit_timeline` — `P2P_SUPPLIER_INVOICE_DUPLICATE` code + UTF-8 subprocess decode for Persian UI (duplicate reject kept).
4. Mandatory CI workflow `docs/ai/rc1/ci/rc1-mysql-php-gate.yml (copy into .github/workflows/)` + `scripts/rc1_ci_mysql_php_gate.py` (fails on skip; isolated MF/trading DBs).
5. `release_gate.py` clears leftover `RC1_REQUIRED` so commercial discover does not race shared RC1 schemas.

## P2 — Non-blocking

| ID | Issue | Owner |
|----|-------|-------|
| P2-002 | Open PR #29 late T1 vs release; CONFLICTING; superseded by stabilization | T0 close after merge |

## P0

**None open.**

## Valid residual product states (do not weaken)

- MF valuation: `UNVALUED`
- MF GL posting: `BLOCKED` / `GL_BLOCKED`
