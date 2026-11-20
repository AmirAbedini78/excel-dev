# RC1 Acceptance Report

**Owner:** T0 P1 closure / independent auditor  
**Baseline main SHA:** `2a30f5abac6291af2e6d3ccf1f87446335c84bad`  
**Closure branch:** `fix/rc1-stabilization-p1-closure`  
**Report date:** 2026-11-20  
**DATABASE_TESTED:** **true** (disposable sandbox)  
**Independent verdict:** **RC1_BLOCKED** — **NOT_DEPLOYED**

---

## Historical claim (prior campaign — do not erase)

| Field | Value |
|-------|-------|
| Prior gate | `python scripts/rc1_acceptance_gate.py --strict-pass` → PASS=5 |
| Prior verdict | `RC1_ACCEPTED` (handoff tip `c9f6eaf…`) |
| Prior note | OPS-1 marked PASS with provenance `UNAVAILABLE_NOT_DEPLOYED` |

---

## Closure append (2026-11-20)

**Sandbox:** `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / port 3307 / `server_uuid=a73a8a84-e4bd-11f1-ad33-7e073bee960b` / PHP `erpsmart-o2c-php:8.2`

| Contract | Script status | Independent class | Proof summary |
|----------|---------------|-------------------|---------------|
| TR-1 | PASS | **PASS_REPRODUCED** | outstanding_after_ap `6000.00` → final `0.00`; duplicate code `P2P_SUPPLIER_INVOICE_DUPLICATE` |
| TR-2 | PASS | **PASS_REPRODUCED** | invoice/receipt settle to outstanding `0.00` |
| MF-1 | PASS | **PASS_REPRODUCED** | concurrent issue 1+1; on_hand A=4; `UNVALUED` / GL `BLOCKED` |
| SYS-1 | PASS (smoke) | **BLOCKED_EXTERNAL** | Profile/tenant smoke only; **no** real login/UI |
| OPS-1 | PASS (script w/ UNAVAILABLE) | **BLOCKED_EXTERNAL** | Restore drill PASS; provenance unavailable; browser PENDING |

**Mandatory E2E skipped in acceptance gate:** **0** (under RC1_REQUIRED).

**Commercial MVP CI:** source-only — **insufficient** alone for RC1 acceptance.

**Local gates:** `rc1_ci_mysql_php_gate` PASS; `rc1_acceptance_gate --strict-pass` script PASS=5; `release_gate --require-php --require-node` on tip.

### Evidence paths

- `docs/ai/rc1/evidence/rc1_gate_report.json`
- `docs/ai/rc1/evidence/tr1_acceptance.json` / `tr2_acceptance.json`
- `docs/ai/rc1/evidence/mf1_acceptance.json` / `mf1_e2e.json`
- `docs/ai/rc1/evidence/sys1_acceptance.json`
- `docs/ai/rc1/evidence/ops1_acceptance.json`
- `docs/ai/rc1/evidence/ops1_backup_restore_closure.json`
- `docs/ai/rc1/T6-BROWSER-NOTES.md`
