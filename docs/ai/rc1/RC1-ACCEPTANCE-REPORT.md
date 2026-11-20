# RC1 Acceptance Report

**Owner:** Independent auditor (closure) / historical T0+T7  
**Verified main SHA:** `6da6a03796e1f89b5279424899adad5b5a23915c`  
**Branch at merge:** `release/erpsmart-rc1` → `main` via PR #30  
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

## Independent append (2026-11-20)

**Command:** `RC1_REQUIRED=1` + disposable MySQL + `RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED` → `python scripts/rc1_acceptance_gate.py --strict-pass`  
**Runtime:** ~206s (start ~17:15:42Z finish ~17:19:08Z)  
**Exit code:** 0 (script)  
**Sandbox:** `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / port 3307 / `server_uuid=a73a8a84-e4bd-11f1-ad33-7e073bee960b` / PHP `erpsmart-o2c-php:8.2`

| Contract | Script status | Independent class | Proof summary |
|----------|---------------|-------------------|---------------|
| TR-1 | PASS | **PASS_REPRODUCED** | outstanding_after_ap `6000.00` → final `0.00`; stock_movements `1`; receipt_gl `0`; IRR |
| TR-2 | PASS | **PASS_REPRODUCED** | invoice `1000.00`; receipts `400`+`600`; outstanding `0.00` |
| MF-1 | PASS | **PASS_REPRODUCED** | order closed; issue/FG movements; scrap/QC; `UNVALUED` / GL `BLOCKED` |
| SYS-1 | PASS | **FAIL_REPRODUCED** (strict) | Profile/tenant smoke only; **no** real login/UI action |
| OPS-1 | PASS | **BLOCKED_EXTERNAL** | Provenance unavailable; browser PENDING; restore drill PARTIAL |

**Mandatory E2E skipped in this gate run:** **0** (under RC1_REQUIRED).

**Commercial MVP CI:** SUCCESS on SHA `6da6a03` but **source-only**; MySQL E2E skipped in Actions.

**Local full release_gate:** FAIL (2) — concurrent MF issue; trading cockpit Persian assert — see blockers doc.

### Evidence paths

- `docs/ai/rc1/evidence/rc1_gate_report.json`
- `docs/ai/rc1/evidence/tr1_acceptance.json`
- `docs/ai/rc1/evidence/tr2_acceptance.json`
- `docs/ai/rc1/evidence/mf1_acceptance.json` / `mf1_e2e.json`
- `docs/ai/rc1/evidence/sys1_acceptance.json`
- `docs/ai/rc1/evidence/ops1_acceptance.json`
- `docs/ai/rc1/evidence/ops1_backup_restore_drill.json`
