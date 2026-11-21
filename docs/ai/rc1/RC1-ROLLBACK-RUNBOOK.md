# RC1 Rollback Runbook (Disposable / Legacy rehearsal)

**Scope:** Disposable MySQL only. **Never** production/cPanel.  
**Date:** 2026-11-21  

## Principles

1. MySQL DDL is often **not** transaction-rollback-safe — prefer **restore from dump** over `ROLLBACK`.  
2. Keep an **untouched baseline** schema (`*_baseline`) separate from migrate clone.  
3. Verify container id + `@@server_uuid` before any DROP/restore.  
4. Never run destructive E2E resets against the sole restored baseline.

## Verified disposable identity

- Name: `erpsmart-p2p-mysql-test`  
- ID: `02eb215a87f6a726cf4efb1546f4b09865c9eb6eec9de1e9dc0e6b3163672db6`  
- UUID: `a73a8a84-e4bd-11f1-ad33-7e073bee960b`  
- Port: `3307`

## Rollback procedures

### A — Abandon failed migrate clone

```text
DROP DATABASE erpsmart_rc1_legacy_schema_migrate;
# Recreate + restore from sanitized dump OR clone again from *_baseline
```

### B — Restore rehearsal DB from dump file

```text
CREATE DATABASE erpsmart_rc1_legacy_schema_rollback ...;
mysql ... erpsmart_rc1_legacy_schema_rollback < sanitized.sql
```

**Evidence:** rollback DB restored to 110 tables PASS (2026-11-21).

### C — Application / cache mismatch

- Clear RuntimeCache `schema.json` for the disposable DB fingerprint only.  
- Do not mark production caches.  
- Re-run offline migrate runner after restore.

### D — Interrupted / partial DDL

Assume partial state is dirty → restore from dump into a **new** schema name; do not “fix forward” without inventory of `information_schema`.

## Production note

Any live rollback is **owner/DBA only**, out of band, after approved maintenance window. This runbook does **not** authorize production restore commands from agents.
