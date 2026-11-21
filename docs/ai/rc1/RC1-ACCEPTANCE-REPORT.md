# RC1 Acceptance Report

**Owner:** T0 final RC1 acceptance closure  
**Pre-closure baseline `main` SHA:** `2a30f5abac6291af2e6d3ccf1f87446335c84bad`  
**Final `main` SHA (PR #32 merged):** `52fa7f12f465653d6e445d484f1a8dda48215f45`  
**Verified `origin/main` (2026-11-21):** `c5c644c386cbedde788c4e12e0585c43dc1b5a5d` (PR #35 tip)  
**Closure branch:** `fix/rc1-final-legacy-data-ci-closure` (PR #36)  
**Report date:** 2026-11-21  
**DATABASE_TESTED:** **true** (disposable sandbox; Path2 anonymized data)  
**Independent verdict:** **RC1_ACCEPTED** on candidate — P1-001 **CLOSED** (`SCHEMA_UPGRADE_TESTED` disposable Path2) + P1-006 **CLOSED** (GHA SUCCESS). Merge PR #36 to land on `main`. Production **NOT_DEPLOYED**. No `FRESH_INSTALL`.

---

## Historical claim (prior campaign — do not erase)

| Field | Value |
|-------|-------|
| Prior gate | `python scripts/rc1_acceptance_gate.py --strict-pass` → PASS=5 |
| Prior verdict | `RC1_ACCEPTED` (handoff tip `c9f6eaf…`) |
| Prior note | OPS-1 marked PASS with provenance `UNAVAILABLE_NOT_DEPLOYED` |

---

## Closure append (2026-11-21) — post PR #32

**Sandbox:** `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / port 3307 / `server_uuid=a73a8a84-e4bd-11f1-ad33-7e073bee960b` / PHP `erpsmart-o2c-php:8.2`

| Contract | Script status | Independent class | Proof summary |
|----------|---------------|-------------------|---------------|
| TR-1 | PASS | **PASS_REPRODUCED** | outstanding_after_ap `6000.00` → final `0.00`; duplicate code `P2P_SUPPLIER_INVOICE_DUPLICATE` |
| TR-2 | PASS | **PASS_REPRODUCED** | invoice/receipt settle to outstanding `0.00` |
| MF-1 | PASS | **PASS_REPRODUCED** | Manufacturing **lifecycle** tested (BOM→plan→issue→FG/QC); concurrent issue 1+1; on_hand A=4; cost class `UNVALUED`; WIP/FG valuation **not fully supported**; GL posting `BLOCKED` — **no unsupported auto GL** (see `T5-VALUATION-DECISION.md`) |
| SYS-1 | PASS (smoke) | **PASS_EXISTING_EVIDENCE_VERIFIED** + **LOCAL_BROWSER_VERIFIED** | Profile smoke + Agent B local UAT (`evidence/browser/`); **not** `STAGING_VERIFIED` |
| OPS-1 | PASS Path2 rehearsal | **PASS_PATH2_DISPOSABLE_REHEARSAL** | Owner confirmed prior cPanel/server install → **`EXISTING_DEPLOYMENT_CONFIRMED`**. Anonymized dump (offline) restore → migrate×2 → financial/stock recon **PASS**; **`SCHEMA_UPGRADE_TESTED`** on disposable Path2. No `FRESH_INSTALL`. Production not touched. |

**Mandatory E2E skipped in acceptance gate:** **0** (under RC1_REQUIRED).

**Commercial MVP CI on Final main:** SUCCESS (source-only) — https://github.com/AmirAbedini78/excel-dev/actions/runs/44810790482 — **insufficient** alone for RC1 acceptance.

**GHA MySQL+PHP mandatory workflow:** **INSTALLED** at `.github/workflows/rc1-mysql-php-gate.yml` (dual `O2C_DB_HOST` / `O2C_DB_HOST_PHP`). Real GHA run on candidate `03728b1…`: **SUCCESS** — https://github.com/AmirAbedini78/excel-dev/actions/runs/44894856555. RC1-P1-006 **CLOSED** on candidate SHA (merge to `main` still pending). Evidence: `agent_a_gha_status.json`.

**Local gates:** `rc1_ci_mysql_php_gate` PASS; `rc1_acceptance_gate --strict-pass` script PASS=5; `release_gate --require-php --require-node` on tip.

### MF costing (truthful scope)

- **In scope / tested:** operational manufacturing lifecycle + stock movements; cost rollup may classify `UNVALUED` / `PROVISIONAL` / `POSTED_ACTUAL` / `BLOCKED`.
- **Not fully supported in RC1:** WIP/FG general-ledger valuation (policy `WIP/FG GL = BLOCKED` per `T5-VALUATION-DECISION.md`).
- **Forbidden claim:** unsupported automatic WIP/FG GL posting — `gl_valuation=BLOCKED`, `gl_posting=BLOCKED` in `mf1_acceptance.json`.

### Peer agent lanes (closed this pass)

| Lane | Status | Result |
|------|--------|--------|
| Agent A — GHA | **DONE** | Workflow installed; GHA SUCCESS on `03728b1…` (run 44894856555); P1-006 CLOSED on candidate |
| Agent B — Browser | **DONE** | **`LOCAL_BROWSER_VERIFIED`** — real screenshots under `evidence/browser/`; not `STAGING_VERIFIED` |
| Agent C — Provenance | **DONE** | Path2 anon restore + migrate×2 + recon PASS; P1-001 **CLOSED**; `SCHEMA_UPGRADE_TESTED` |
| Agent D — Docs | **DONE** | Classes/SHA/MF wording reconciled; verdict remains `RC1_BLOCKED` |

### Append — RC1-P1-001 migration architect pass (2026-11-21)

| Item | Result |
|------|--------|
| `origin/main` | `c5c644c386cbedde788c4e12e0585c43dc1b5a5d` |
| Deployment label | `EXISTING_DEPLOYMENT_CONFIRMED` |
| Artifact label | `PATH2_ANON_SCHEMA_DATA_VERIFIED` |
| Provenance id | `prior-cpanel-anon-20261121` |
| Candidate dump SHA-256 | `2D695C068CD8EBAE6C8010FE6A9FE254E6F0392B97B5D6FB1329DE07A63419EC` (offline) |
| Anonymized dump SHA-256 | `F3B92C0088A874A739FCD64BF25FA714010078C3EB95DFDF81ADC92335841DE2` (offline; leak_scan PASS) |
| Structural migrate×2 | PASS (110→182) on disposable clone; baseline untouched |
| Data reconciliation | **PASS** — vouchers 47 / lines 117 Dr=Cr `18063580000.00`; stock quantity `4.0000` + inventory qty `1094.0000` preserved; mismatch_count=0 |
| Recovery clone | **PASS** (182 tables) |
| Disposable MySQL | `erpsmart-p2p-mysql-test` / `9c00f40fc78a…` / tmpfs / bridge / port 3307 / uuid `ff0092ef-e5d1-11f1-9a87-2e6b1b469ce1` |
| Owner Path2 | `CAMPAIGN_PATH2_DUMP_PROVIDED` |
| `SCHEMA_UPGRADE_TESTED` | **CLAIMED** (disposable Path2 only) |
| RC1-P1-001 | **CLOSED** |
| RC1-P1-006 | **CLOSED** on candidate SHA (GHA SUCCESS) |
| Full RC1 acceptance | **RC1_ACCEPTED** on candidate (merge PR #36 to land on main) |

Deliverables: anonymizer/reconcile scripts, `ops1_legacy_anonymization.json`, GHA workflow install, blockers/acceptance updates.

### Evidence paths

- `docs/ai/rc1/evidence/ops1_legacy_schema_structural_rehearsal.json`
- `docs/ai/rc1/evidence/ops1_legacy_anonymization.json`
- `docs/ai/rc1/evidence/ops1_legacy_data_rehearsal.json`
- `docs/ai/rc1/evidence/ops1_legacy_data_reconciliation.json`
- `docs/ai/rc1/evidence/agent_c_provenance_status.json`
- `docs/ai/rc1/evidence/rc1_gate_report.json`
- `docs/ai/rc1/evidence/tr1_acceptance.json` / `tr2_acceptance.json`
- `docs/ai/rc1/evidence/mf1_acceptance.json` / `mf1_e2e.json`
- `docs/ai/rc1/evidence/sys1_acceptance.json`
- `docs/ai/rc1/evidence/ops1_acceptance.json`
- `docs/ai/rc1/evidence/ops1_backup_restore_closure.json`
- `docs/ai/rc1/evidence/ops1_backup_restore_reverify.json`
- `docs/ai/rc1/evidence/agent_c_provenance_status.json`
- `docs/ai/rc1/OPS1-OWNER-ACTIONS.md`
- `docs/ai/rc1/evidence/agent_a_gha_status.json`
- `docs/ai/rc1/evidence/agent_b_browser_status.json`
- `docs/ai/rc1/evidence/browser/` (01–09 PNG + csrf/session logs)
- `docs/ai/rc1/T6-BROWSER-NOTES.md`
- `docs/ai/rc1/T5-VALUATION-DECISION.md`
