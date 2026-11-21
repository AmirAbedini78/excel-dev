# RC1 Migration Pre-Flight

**Owner:** T7 / Agent C (OPS-1)  
**Date:** 2026-11-20 (Agent C closure pass)  
**Workspace tip (this branch):** see git HEAD — expected merged main pin `52fa7f12f465653d6e445d484f1a8dda48215f45`  
**Repo schema constant:** `RuntimeCache::SCHEMA_VERSION = 10.24.0-rc1`  

## Status: engineering restore CLOSED · legacy structural PASS · provenance LEGACY_SCHEMA_UNVERIFIED

| Check | Result |
|-------|--------|
| Disposable MySQL identity verified | PASS — `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / port 3307 / `server_uuid=a73a8a84-e4bd-11f1-ad33-7e073bee960b` |
| Domain `migrate()` idempotency on disposable DB | PASS — TR-1/TR-2/MF harness resets + OPS-1 migrate×2 on restored disposable DB |
| Privileged disposable dump/restore + rollback | **CLOSED** — `ops1_backup_restore_closure.json`; reverify `ops1_backup_restore_reverify.json` (32→migrate×2→48 tables) |
| Owner prior-install confirmation | **`EXISTING_DEPLOYMENT_CONFIRMED`** — Path1 FRESH_INSTALL **invalid** |
| Candidate legacy dump | Received (SHA in provenance docs); **unsafe** secrets/PII; not in Git |
| Structural schema-only legacy migrate×2 | **PASS** — `ops1_legacy_schema_structural_rehearsal.json` (110→182); **not** `SCHEMA_UPGRADE_TESTED` |
| Prior schema provenance (verified anonymized data) | **`LEGACY_SCHEMA_UNVERIFIED`** — defect RC1-P1-001 OPEN |
| Backup/restore rehearsal from **anonymized data** dump | **NOT RUN** (blocked on Path2 data) |
| cPanel / production migrate | **FORBIDDEN** / **NOT_RUN** |

See also: `RC1-MIGRATION-PREFLIGHT.md` (legacy Path2 checklist).

## Provenance rules (hard)
1. Never treat git `main` / repo schema version as proof of deployed production schema.
2. Never invent `FRESH_INSTALL` from missing records. Path1 requires **owner written confirmation** (exact text in `OPS1-OWNER-ACTIONS.md`).
3. Path2: set `RC1_PROD_SCHEMA_PROVENANCE=<artifact-id>` only when an **authorized anonymized** dump is available.
4. If unknown → OPS-1 remains **BLOCKED**.
5. Additive migrations only; T0 owns central wiring merge order.
6. Refuse DROP/restore unless container ID, network, DB name, and credentials match the verified disposable sandbox.

## Planned rehearsal (when Path2 unblocks)
1. Restore anonymized dump into a **new** disposable schema (not prod).
2. Run cumulative migrate paths twice; capture row counts + `information_schema` diff.
3. Rollback/restore smoke; record checksums in release manifest.
4. Re-run `scripts/rc1_acceptance_gate.py --strict-pass`.

## Owner unblock
See **`docs/ai/rc1/OPS1-OWNER-ACTIONS.md`** — Path1 confirmation wording **or** Path2 anonymized dump checklist.

## Env template (non-secret)
```
O2C_DB_HOST=host.docker.internal
O2C_DB_PORT=3307
O2C_DB_USER=p2p
O2C_DB_NAME=erpsmart_p2p_test
# O2C_DB_PASS from local secret store — never commit
RC1_REQUIRED=1
# Provenance: leave unset / UNAVAILABLE until Path1 or Path2 — do not invent FRESH_INSTALL
```
