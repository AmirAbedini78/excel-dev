# Defect RC1-P1-001 — OPS-1 production/staging schema provenance unavailable

| Field | Value |
|-------|-------|
| ID | RC1-P1-001 |
| Severity | P1 |
| Contract | OPS-1 |
| Owner | **User/staging** (dump) / **T7** (rehearsal) |
| Status | **OPEN** for RC1 acceptance (independent audit 2026-11-20) |
| Prior note | Campaign briefly labeled RESOLVED via `UNAVAILABLE_NOT_DEPLOYED` PASS — **rejected** by independent closure (must not redefine DONE) |
| Reporter | T7 / Independent auditor |
| Date | 2026-11-20 |

## Summary
OPS-1 requires cumulative migration from an *actual prior deployed schema* where safely obtainable. None is available. Repo `RuntimeCache::SCHEMA_VERSION` is **not** production proof.

## Actual
Gate script may exit PASS when `RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED`. Independent classification: **BLOCKED_EXTERNAL**. Disposable migrate alone does not clear this defect.

## Fix path
Provide anonymized staging dump + provenance env `RC1_PROD_SCHEMA_PROVENANCE=<id>`; T7 runs upgrade/rollback rehearsal on disposable restore. No cPanel/prod deploy by agents.
