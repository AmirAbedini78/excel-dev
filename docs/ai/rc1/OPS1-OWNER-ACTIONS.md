# OPS-1 Owner Actions — Provenance Unblock

**Agent:** C (Provenance & migration)  
**Date:** 2026-11-20  
**Contract:** OPS-1 / defect RC1-P1-001  
**Campaign:** `NOT_DEPLOYED`  
**Engineering restore:** **CLOSED** (disposable)  
**Provenance:** **BLOCKED_EXTERNAL** / class **UNAVAILABLE**  
**Do not invent `FRESH_INSTALL`.**

---

## Current truth (Agent C)

| Item | Class |
|------|-------|
| Disposable privileged dump/restore | **CLOSED** — `ops1_backup_restore_closure.json` + reverify `ops1_backup_restore_reverify.json` |
| Migrate×2 on restored disposable DB | **CLOSED** |
| Rollback restore (second disposable DB) | **CLOSED** |
| Prior deployed / staging schema provenance | **UNAVAILABLE** → OPS-1 acceptance **BLOCKED_EXTERNAL** |
| Path1 `FRESH_INSTALL` | **NOT claimed** — owner confirmation absent in this chat |
| Path2 anonymized dump | **NOT provided** |
| cPanel / production | **NOT_RUN** / forbidden without permission |

Until Path1 **or** Path2 completes, independent OPS-1 remains **BLOCKED**. Script PASS under `RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED` is **not** acceptance closure.

---

## Path 1 — Owner written confirmation (never installed)

Use **only** if there has never been any staging/production/cPanel deploy of this product schema for this campaign.

### Exact confirmation text (copy/paste reply)

> I confirm as product/ops owner that ErpSmart for this RC1 campaign has **never** been installed or deployed to any staging or production host (including cPanel). There is **no** prior deployed MySQL schema to migrate from. I authorize classifying provenance as **FRESH_INSTALL** for OPS-1 / RC1-P1-001. Campaign remains **NOT_DEPLOYED** until an explicit future go-live.

### After Path1 is received

1. Record confirmation quote + date/actor in `defects/RC1-P1-001-ops1-schema-provenance.md`.
2. Set evidence provenance to `FRESH_INSTALL` (not invented earlier).
3. Keep disposable restore evidence as engineering proof only.
4. Do **not** treat Path1 as license to touch production.

---

## Path 2 — Authorized anonymized staging/prior dump

Use when any prior deploy may exist or owner cannot swear Path1.

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
