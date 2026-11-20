# Defect RC1-P1-001 — OPS-1 production/staging schema provenance unavailable

| Field | Value |
|-------|-------|
| ID | RC1-P1-001 |
| Severity | P1 |
| Contract | OPS-1 |
| Owner | **User/staging** (dump or Path1 confirmation) / **T7** (rehearsal) |
| Status | **OPEN** for RC1 acceptance (final closure 2026-11-21) |
| Prior note | Campaign briefly labeled RESOLVED via `UNAVAILABLE_NOT_DEPLOYED` PASS — **rejected** by independent closure (must not redefine DONE) |
| Reporter | T7 / Independent auditor / Agent C |
| Date | 2026-11-21 |

## Summary
OPS-1 requires cumulative migration from an *actual prior deployed schema* where safely obtainable, **or** an explicit owner Path1 never-installed confirmation. Neither is available. Repo `RuntimeCache::SCHEMA_VERSION` is **not** production proof. **Do not invent `FRESH_INSTALL`.**

## Actual
- Gate script may exit PASS when `RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED`.
- Independent classification: **BLOCKED_EXTERNAL**.
- Disposable backup/restore + migrate×2 + rollback: engineering **CLOSED** (`ops1_backup_restore_closure.json`).
- Provenance class remains `UNAVAILABLE_NOT_DEPLOYED` (`agent_c_provenance_status.json`).

## Owner actions

### Path1 — only if literally true
Written confirmation (Persian or English), e.g.:

> I confirm that ERPSMART / excel-dev has never been installed or deployed on any staging or production host for this campaign; schema provenance may be recorded as FRESH_INSTALL.

### Path2 — anonymized prior schema
1. Authorize anonymized export (PII scrubbed).  
2. Provide dump artifact id + sha256.  
3. Set `RC1_PROD_SCHEMA_PROVENANCE=<artifact-id>`.  
4. Restore into a **new** disposable schema only.  
5. Run cumulative migrate ×2 + rollback restore; attach evidence under `docs/ai/rc1/evidence/`.

## Fix path
No cPanel/prod deploy by agents. After Path1 or Path2, re-run OPS-1 acceptance and update blockers.
