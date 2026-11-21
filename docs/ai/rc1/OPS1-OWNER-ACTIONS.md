# OPS-1 Owner Actions — Provenance Unblock

**Agent:** C (Provenance & migration)  
**Date:** 2026-11-21  
**Contract:** OPS-1 / defect RC1-P1-001  
**Campaign status:** `EXISTING_DEPLOYMENT_CONFIRMED`  
**Artifact status:** `PATH2_ANON_SCHEMA_DATA_VERIFIED`  
**Engineering restore:** **CLOSED** (disposable synthetic)  
**Path2 data rehearsal:** **PASS** / `SCHEMA_UPGRADE_TESTED`  
**RC1-P1-001:** **CLOSED**  
**Do not invent `FRESH_INSTALL`.** Production **NOT_TOUCHED**.

---

## Current truth (T0 / Agent C)

| Item | Class |
|------|-------|
| Disposable privileged dump/restore (synthetic) | **CLOSED** — `ops1_backup_restore_closure.json` + reverify |
| Owner confirmed prior install | **YES** → Path1 invalid |
| Candidate dump | Received — SHA recorded; **UNSAFE** secrets/PII; **not in Git** |
| Schema-only sanitize + migrate×2 + rollback | **PASS** — `ops1_legacy_schema_structural_rehearsal.json` |
| Path2 anonymized schema+data | **PASS** — anon SHA `F3B92C0088…41DE2`; leak_scan PASS; **not in Git** |
| Provenance id | `prior-cpanel-anon-20261121` (`CAMPAIGN_PATH2_DUMP_PROVIDED`) |
| Data reconciliation (vouchers/stock/balances) | **PASS** — `ops1_legacy_data_reconciliation.json` |
| cPanel / production | **NOT_RUN** / forbidden |

Independent OPS-1: **PASS_PATH2_DISPOSABLE_REHEARSAL**.

---

## Path 1 — INVALID for this campaign

Owner confirmed an earlier ERPSMART installation existed on server/cPanel with a database. **Do not** use FRESH_INSTALL wording.

---

## Path 2 — COMPLETE

Anonymized dump restored only into disposable schemas (`erpsmart_rc1_legacy_data_*`). Dump never committed. Full checklist remains in `LEGACY-SCHEMA-EXPORT-INSTRUCTIONS.md` for any future export.
