# RC1 Migration Rehearsal Log

**Date:** 2026-11-21  
**Classification:** `STRUCTURAL_ONLY_NOT_DATA_RECONCILED`  
**Evidence:** `docs/ai/rc1/evidence/ops1_legacy_schema_structural_rehearsal.json`

## Disposable identity

| Field | Value |
|-------|-------|
| Container | `erpsmart-p2p-mysql-test` |
| ID | `02eb215a87f6a726cf4efb1546f4b09865c9eb6eec9de1e9dc0e6b3163672db6` |
| `@@server_uuid` | `a73a8a84-e4bd-11f1-ad33-7e073bee960b` |
| Port | 3307 |
| MySQL | 8.0.46 |

## Artifact used

- **Not used for restore:** unredacted `zzflgmfd_excel2.sql` (secrets/PII).  
- **Used:** offline schema-only sanitize SHA-256 `3256AABC0EFC1E52AD5ADDA7576F39BA25D09B986AC18C0DF447E0BFEB832015`.

## Steps executed

1. Restore schema-only → `erpsmart_rc1_legacy_schema_baseline` (110 tables).  
2. Clone → `erpsmart_rc1_legacy_schema_migrate` (110).  
3. Offline cumulative migrate ×2 via `scripts/rc1_legacy_schema_migrate_rehearsal.php`.  
4. Result: tables **182**; pass1 ~63s; pass2 ~12s; both **ok**.  
5. RC1 additive tables present (`erp_ar_postings`, `erp_ap_postings`, `erp_mf_production_orders`, …).  
6. Rollback restore → `erpsmart_rc1_legacy_schema_rollback` (110) **PASS**.  
7. Baseline left **unmigrated** at 110.

## Not executed (blocked on verified data artifact)

- Pre-migrate row/voucher/stock invariants on real anonymized data  
- Post-migrate Dr/Cr / inventory / FK / open-obligation reconciliation  
- RC1 acceptance gates on migrated **data** clone  
- Claim `SCHEMA_UPGRADE_TESTED`

## Recovery scenarios covered (structural)

| Scenario | Result |
|----------|--------|
| Migration on clone only | PASS — baseline intact |
| Migrate ×2 idempotency | PASS |
| Restore from schema-only dump after “failure” | PASS (rollback DB) |
| Partial DDL / implicit commit | Mitigated by baseline+restore, not TX rollback |

## Next rehearsal (when Path2 data arrives)

Restore anonymized dump → clone → invariants → migrate×2 → reconciliation → optional RC1 gates on **second** clone → never destroy sole restored baseline.
