# OPS-1 Owner Actions — Provenance Unblock

**Agent:** C (Provenance & migration)  
**Date:** 2026-11-20  
**Contract:** OPS-1 / defect RC1-P1-001  
**Campaign status:** `EXISTING_DEPLOYMENT_CONFIRMED` (owner confirmed prior cPanel/server install)  
**Artifact status:** `LEGACY_SCHEMA_UNVERIFIED`  
**Engineering restore:** **CLOSED** (disposable synthetic)  
**Structural legacy schema-only rehearsal:** **PASS** (not data upgrade proof)  
**Provenance:** **BLOCKED_EXTERNAL** until Path2 anonymized **data** artifact  
**Do not invent `FRESH_INSTALL` or `SCHEMA_UPGRADE_TESTED`.**

---

## Current truth (T0 / Agent C)

| Item | Class |
|------|-------|
| Disposable privileged dump/restore (synthetic) | **CLOSED** — `ops1_backup_restore_closure.json` + reverify |
| Owner confirmed prior install | **YES** → Path1 invalid |
| Candidate dump `zzflgmfd_excel2.sql` | Received — SHA recorded; **UNSAFE** secrets/PII; **not in Git** |
| Schema-only sanitize + migrate×2 + rollback | **PASS** — `ops1_legacy_schema_structural_rehearsal.json` |
| Path2 anonymized schema+data | **REQUIRED / incomplete** |
| Data reconciliation (vouchers/stock/balances) | **NOT_RUN** |
| cPanel / production | **NOT_RUN** / forbidden |

Independent OPS-1 remains **BLOCKED** until Path2 data rehearsal PASS.

---

## Path 1 — INVALID for this campaign

Owner confirmed an earlier ERPSMART installation existed on server/cPanel with a database. **Do not** use FRESH_INSTALL wording.

---

## Path 2 — Authorized anonymized staging/prior dump (REQUIRED)

Primary path. Full checklist: `LEGACY-SCHEMA-EXPORT-INSTRUCTIONS.md`.

### Dump checklist (owner / staging ops)

1. **Authorization:** written approval to export schema (+ optional anonymized data) from staging/prior env only — **not** live prod secrets.
2. **Source identity:** host role (`staging` / `prior-uat`), MySQL version, schema name, approximate deploy date, code SHA if known.
3. **Anonymization:** scrub PII, credentials, API keys, bank/tax tokens, customer contacts; prefer schema+structure with synthetic rows if needed.
4. **Artifact:** single `.sql` (or `.sql.gz`) delivered out-of-band to the RC1 evidence drop; do not commit secrets.
5. **Checksum:** publish SHA-256 of the dump file.
6. **Env id:** choose stable id, e.g. `staging-anon-YYYYMMDD`, then set  
   `RC1_PROD_SCHEMA_PROVENANCE=<that-id>` for gate/rehearsal.
7. **Rehearsal (engineering, disposable only):** restore into a **new** disposable schema on `erpsmart-p2p-mysql-test` → migrate×2 → row/`information_schema` diff → rollback restore smoke → update release manifest. **No cPanel/prod.**

### Path2 reject conditions

- Dump contains live secrets / unredacted PII  
- Dump is claimed “prod” without anonymization approval  
- Using git `main` / `RuntimeCache::SCHEMA_VERSION` as substitute provenance  

---

## If neither Path1 nor Path2

**OPS-1 stays BLOCKED.** Class remains **UNAVAILABLE** / **BLOCKED_EXTERNAL**. No agent may invent FRESH_INSTALL from missing records.
