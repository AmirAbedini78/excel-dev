# Defect RC1-P1-001 — OPS-1 production/staging schema provenance unavailable

| Field | Value |
|-------|-------|
| ID | RC1-P1-001 |
| Severity | P1 |
| Contract | OPS-1 |
| Owner | T0 (rehearsal) / User (Path2 dump) |
| Status | **CLOSED** — `EXISTING_DEPLOYMENT_CONFIRMED` / `PATH2_ANON_SCHEMA_DATA_VERIFIED` / `SCHEMA_UPGRADE_TESTED` |
| Prior note | Path1 `FRESH_INSTALL` **invalid** after owner confirmed prior cPanel/server install |
| Reporter | T7 / Independent auditor / T0 Migration Architect |
| Date | 2026-11-21 |

## Summary
OPS-1 required cumulative migration evidence from an *actual prior deployed schema*. Owner confirmed prior install → Path1 `FRESH_INSTALL` remains **invalid**. Owner-supplied candidate dump was anonymized offline (not in Git). Disposable restore → migrate×2 → financial/stock reconciliation **PASS**. Defect **CLOSED**. Production/cPanel **not** touched.

## Actual (closure)

- Deployment status: **`EXISTING_DEPLOYMENT_CONFIRMED`**.
- Candidate (offline, unsafe): SHA-256 `2D695C068CD8EBAE6C8010FE6A9FE254E6F0392B97B5D6FB1329DE07A63419EC`.
- Anonymized schema+data (offline): SHA-256 `F3B92C0088A874A739FCD64BF25FA714010078C3EB95DFDF81ADC92335841DE2`; leak_scan **PASS**.
- Provenance id: `prior-cpanel-anon-20261121` (`CAMPAIGN_PATH2_DUMP_PROVIDED`).
- Structural schema-only migrate×2: **PASS** (prior).
- Anonymized **data** restore → migrate×2 → recon → recovery clone: **PASS** (`ops1_legacy_data_rehearsal.json`, `ops1_legacy_data_reconciliation.json`).
  - Baseline 110 tables **untouched**.
  - Migrate clone 110→182; pass1+pass2 **ok**.
  - Recovery clone 182 **ok**.
  - Voucher Dr=Cr `18063580000.00`; stock `quantity`/`unit_cost` preserved; mismatch_count=0.
- Disposable MySQL: `erpsmart-p2p-mysql-test` / `9c00f40fc78a…` / bridge / tmpfs datadir / port 3307 / `@@server_uuid=ff0092ef-e5d1-11f1-9a87-2e6b1b469ce1`.
- Independent class: **`PASS_PATH2_DISPOSABLE_REHEARSAL`**.
- Engineering synthetic restore drill remains **CLOSED**.

## Owner actions

### Path1 — CLOSED / INVALID
Owner confirmed earlier installation existed → Path1 wording must **not** be used.

### Path2 — COMPLETE
Anonymized schema+data restored only into disposable schemas; dump never committed.

## Fix path
No cPanel/prod deploy. RC1-P1-001 **CLOSED** on Path2 disposable rehearsal evidence.
