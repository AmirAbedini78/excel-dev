# Defect RC1-P1-001 — OPS-1 production/staging schema provenance unavailable

| Field | Value |
|-------|-------|
| ID | RC1-P1-001 |
| Severity | P1 |
| Contract | OPS-1 |
| Owner | **T0** (staging dump) / **T7** (rehearsal) |
| Status | OPEN / BLOCKED |
| Reporter | T7 |
| Date | 2026-11-20 |

## Summary
OPS-1 requires cumulative migration from an *actual prior deployed schema* where safely obtainable. None is available. Repo `RuntimeCache::SCHEMA_VERSION` is **not** production proof.

## Actual
Acceptance marks OPS-1 **BLOCKED**. Disposable migrate idempotency on `erpsmart_p2p_test` alone is demonstrated separately and does not clear this defect.

## Fix path
Provide anonymized staging dump + provenance env `RC1_PROD_SCHEMA_PROVENANCE=<id>`; T7 runs upgrade/rollback rehearsal on disposable restore. No cPanel/prod deploy by T7.
