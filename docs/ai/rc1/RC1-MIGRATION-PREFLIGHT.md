# RC1 Migration Preflight — Legacy Path2

**Owner:** T0  
**Date:** 2026-11-21  
**Also see:** `RC1-MIGRATION-PRE-FLIGHT.md` (prior engineering restore CLOSED)  
**Repo schema:** `10.24.0-rc1`  
**origin/main:** `e474ff377731ad2d482b2bf4fec6c3d1a7d32c5c`

## Gate checklist

| Check | Result |
|-------|--------|
| Existing deployment confirmed by owner | **PASS** → `EXISTING_DEPLOYMENT_CONFIRMED` |
| Candidate dump SHA-256 recorded | **PASS** (redacted metadata) |
| Candidate safe for Git | **FAIL** — secrets/PII |
| Sanitized schema-only offline | **PASS** (outside Git) |
| Disposable MySQL identity | **PASS** — `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / port 3307 / `server_uuid=a73a8a84-e4bd-11f1-ad33-7e073bee960b` |
| Untouched baseline preserved | **PASS** — `erpsmart_rc1_legacy_schema_baseline` @ 110 tables |
| Structural migrate×2 on clone | **PASS** — 110→182; pass2 idempotent |
| Rollback restore drill | **PASS** — `erpsmart_rc1_legacy_schema_rollback` @ 110 |
| Anonymized data Path2 verified | **OPEN** → `LEGACY_SCHEMA_UNVERIFIED` |
| Full voucher/stock reconciliation | **NOT_RUN** (schema-only) |
| `SCHEMA_UPGRADE_TESTED` | **NOT_CLAIMED** |
| Production / cPanel | **FORBIDDEN** / **NOT_RUN** |

## Preferred migrate path (offline)

Use CLI runner — **not** first browser/HTTP hit:

```text
scripts/rc1_legacy_schema_migrate_rehearsal.php
```

Env (non-secret template):

```
O2C_DB_HOST=host.docker.internal
O2C_DB_PORT=3307
O2C_DB_USER=rc1_admin
O2C_DB_NAME=erpsmart_rc1_legacy_schema_migrate
# O2C_DB_PASS from local secret store
```

Bootstrap/cache failure modes to watch:

- `RuntimeCache::schemaReady` skipping migrates after partial failure → clear `schema.json` for rehearsal only  
- `install.php` / `migrate_v2.php` → **incomplete** (base `Schema::migrate` only); never use as RC1 upgrade path  
- Cache dir pointing at wrong DB fingerprint / non-writable cache falling back to temp → split schema gate  
- `app_key` mismatch vs encrypted credential fingerprint → hard fail (`enforce_data_persistence_guard`)  
- Legacy `workspace_api_tokens` vs RC1 `api_access_tokens` — no silent DROP during expand (see schema diff)  

## Go / No-Go

- **GO (structural rehearsal):** sanitized schema-only + disposable IDs verified.  
- **NO-GO (RC1-P1-001 close / SCHEMA_UPGRADE_TESTED):** until anonymized schema+data artifact + reconciliation PASS.
