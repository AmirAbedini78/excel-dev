# RC1 Blockers and Remediation

**Independent open P0:** **0**  
**Independent open P1 (blocking RC1 acceptance):** **3**  
**Independent open P2:** **2**  
**Verdict impact:** `RC1_BLOCKED`

## P1 — Blocking

### RC1-P1-001 — OPS-1 production/staging schema provenance unavailable

| Field | Value |
|-------|-------|
| Severity | **P1** (reopened for acceptance) |
| Contract | OPS-1 |
| Class | **BLOCKED_EXTERNAL** |
| Owner | User/staging ops → then T7 rehearsal |
| Status | **OPEN** for RC1 DONE (prior “RESOLVED by UNAVAILABLE PASS” rejected) |

**Repro:** Omit real dump id; set `RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED`; OPS-1 script PASSes by policy, but binding matrix forbids completing OPS-1 without provenance or honest BLOCKED.

**Next action:** Provide anonymized staging/prod-like dump + provenance id; run cumulative migrate twice on disposable restore; document checksums. No cPanel/prod by agents.

### RC1-P1-004 — SYS-1 / OPS-1 browser login+RTL walkthrough missing

| Field | Value |
|-------|-------|
| Severity | **P1** |
| Contract | SYS-1 + OPS-1 |
| Class | **NOT_RUN** |
| Owner | T6/T7 + user staging URL |

**Repro:** `docs/ai/rc1/T6-BROWSER-NOTES.md` states automation not run; no screenshots; SYS-1 runner is PHP profile smoke only.

**Next action:** Staging-like authenticated Persian RTL session: trading + manufacturing cockpits, Jalali/IRR-Toman, two-company isolation, permission negative path. Capture screenshots under `docs/ai/rc1/screenshots/`.

### RC1-P1-005 — Disposable backup restore incomplete + local release_gate E2E fails

| Field | Value |
|-------|-------|
| Severity | **P1** |
| Contract | OPS-1 |
| Class | **FAIL_REPRODUCED** (partial) |
| Owner | T7 / T1 / T3 |

**Evidence:**

1. Dump of `erpsmart_rc1_tr1_test` OK (43422 B); restore into new DB as `p2p` → `Access denied` (`ops1_backup_restore_drill.json`).
2. Local `python scripts/release_gate.py --require-php --require-node` → FAIL:
   - `test_06_concurrent_issue_two_orders` — both threads fail reservation-scoped issue (expected 1 ok / 1 insufficient).
   - `test_tr1_exact_figures_and_cockpit_timeline` — Persian `تکراری` assert encoding mismatch.

**Next action:** Grant disposable restore privileges or root-only restore path in sandbox docs; fix concurrent issue reservation race; normalize Unicode assert (NFC / substring without yeh ambiguity). Re-run local release_gate + RC1 gate.

## P2 — Non-blocking for product scope (track)

| ID | Issue | Owner |
|----|-------|-------|
| P2-001 | Handoff Final SHA stale (`c9f6eaf` vs `6da6a03`) | Docs |
| P2-002 | Open PR #29 late T1 commits vs release; not on main tip | T1/T0 triage |

## Nonblocking post-RC backlog (explicit)

- Taxpayer / Sayad / bank direct integrations  
- Statutory payroll; full foreign FX/customs GL  
- Automatic WIP/FG GL valuation (currently BLOCKED/UNVALUED)  
- Advanced WMS FEFO/lot/bin  

## P0

**None open** with reproduced data-loss / wrong-company money posting in TR-1/TR-2/MF-1 happy-path gate.
