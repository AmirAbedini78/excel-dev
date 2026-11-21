# RC1 Migration Rehearsal Log

**Date:** 2026-11-21  
**Classification:** `DATA_REHEARSAL_PASS` / `SCHEMA_UPGRADE_TESTED` (disposable Path2)  
**Evidence:** `docs/ai/rc1/evidence/ops1_legacy_data_rehearsal.json` + `ops1_legacy_data_reconciliation.json`

## Disposable identity (Path2 data pass)

| Field | Value |
|-------|-------|
| Container | `erpsmart-p2p-mysql-test` |
| ID | `9c00f40fc78a2d1b6cd6ea3b6cb6384c5295a04ffe5834eba487949a5e35833c` |
| `@@server_uuid` | `ff0092ef-e5d1-11f1-9a87-2e6b1b469ce1` |
| Port | 3307 |
| Network | bridge (not host) |
| Datadir | tmpfs (disposable) |
| MySQL | 8.0.46 |

## Artifact used

- **Not used for restore:** unredacted candidate dump (secrets/PII).  
- **Used:** offline anonymized schema+data SHA-256 `F3B92C0088A874A739FCD64BF25FA714010078C3EB95DFDF81ADC92335841DE2`.

## Steps executed (Path2 data)

1. Restore anonymized dump → `erpsmart_rc1_legacy_data_baseline` (110 tables).  
2. Clone → `erpsmart_rc1_legacy_data_migrate`.  
3. Offline cumulative migrate ×2 via `scripts/rc1_legacy_schema_migrate_rehearsal.php`.  
4. Result: tables **182**; pass1+pass2 **ok**.  
5. Pre/post recon: counts match; voucher Dr=Cr `18063580000.00`; stock quantity `4.0000` / inventory qty `1094.0000` preserved; mismatch_count=0.  
6. Recovery clone migrate **PASS** (182).  
7. Rollback restore clone **PASS**.  
8. Baseline left **unmigrated** at 110.

## Recovery scenarios covered

| Scenario | Result |
|----------|--------|
| Migration on clone only | PASS — baseline intact |
| Migrate ×2 idempotency | PASS |
| Recovery clone from untouched baseline | PASS |
| Restore from anonymized dump after “failure” | PASS (rollback DB) |
| Financial/stock recon | PASS |

Prior structural-only pass remains recorded in `ops1_legacy_schema_structural_rehearsal.json` (container `02eb215a87f6…`).
