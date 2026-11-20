# RC1 Migration Pre-Flight

**Owner:** T7  
**Date:** 2026-11-20  
**Baseline SHA:** `921b6dac631763ce8fc772fe54cbc7d1e92a87a0`  
**Repo schema constant:** `RuntimeCache::SCHEMA_VERSION = 10.23.0`  

## Status: BLOCKED (provenance)

| Check | Result |
|-------|--------|
| Disposable MySQL identity verified | PASS — `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / port 3307 / `erpsmart_p2p_test` |
| Domain `migrate()` idempotency on disposable DB | PARTIAL — exercised via AP/O2C harness resets in TR-1/TR-2 runners |
| Prior **production/staging** schema dump provenance | **BLOCKED** — unavailable; defect RC1-P1-001 |
| Backup/restore rehearsal from prod-like dump | NOT RUN (blocked on provenance) |
| cPanel / production migrate | **FORBIDDEN** for T7 |

## Rules
1. Never treat git `main` / repo schema version as proof of deployed production schema.
2. Set `RC1_PROD_SCHEMA_PROVENANCE=<artifact-id>` only when an anonymized dump is available.
3. Additive migrations only; T0 owns central wiring merge order.
4. Refuse DROP/restore unless container ID, network, DB name, and credentials match the verified disposable sandbox.

## Planned rehearsal (when unblocked)
1. Restore anonymized dump into a **new** disposable schema (not prod).
2. Run cumulative migrate paths twice; capture row counts + `information_schema` diff.
3. Rollback/restore smoke; record checksums in release manifest.
4. Re-run `scripts/rc1_acceptance_gate.py --strict-pass`.

## Env template (non-secret)
```
O2C_DB_HOST=host.docker.internal
O2C_DB_PORT=3307
O2C_DB_USER=p2p
O2C_DB_NAME=erpsmart_p2p_test
# O2C_DB_PASS from local secret store — never commit
RC1_REQUIRED=1
```
