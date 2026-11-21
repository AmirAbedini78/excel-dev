# Legacy Deployment Provenance — RC1-P1-001

**Owner:** T0 Migration Architect  
**Date:** 2026-11-21  
**origin/main (verified):** `2c1cf6592f246553c66c3bc90d00385bf65d4c29` (PR #36; Path2 work historically on `c5c644c…`)  
**Repo schema constant:** `RuntimeCache::SCHEMA_VERSION = 10.24.0-rc1`  

## Status labels (authoritative)

| Label | Value |
|-------|-------|
| Deployment | **`EXISTING_DEPLOYMENT_CONFIRMED`** (owner: prior ERPSMART install on server/cPanel with DB) |
| Artifact | **`PATH2_ANON_SCHEMA_DATA_VERIFIED`** |
| Provenance id | **`prior-cpanel-anon-20261121`** |
| Fresh install | **`NOT_ASSUMED`** — never invent `FRESH_INSTALL` |
| Schema upgrade tested | **`SCHEMA_UPGRADE_TESTED`** (disposable Path2 anonymized data only) |
| RC1-P1-001 | **CLOSED** |

## Candidate artifact (owner-supplied path — redacted metadata only)

| Field | Value |
|-------|-------|
| Path | `c:\Users\h\Downloads\zzflgmfd_excel2.sql` (**not in Git**) |
| SHA-256 | `2D695C068CD8EBAE6C8010FE6A9FE254E6F0392B97B5D6FB1329DE07A63419EC` |
| Bytes | 1 585 278 |
| Generator | phpMyAdmin 5.2.3 |
| Claimed server | MySQL `8.0.46-cll-lve` (cPanel/CloudLinux signature) |
| Database name | `zzflgmfd_excel2` |
| Kind | **SCHEMA_PLUS_DATA** (110 `CREATE TABLE`, 97 `INSERT`) |
| DEFINER | 0 |
| Safety | **`UNSAFE_UNREDACTED_SECRETS_AND_PII`** — **must not enter Git** |

## Anonymized Path2 artifact (offline — not in Git)

| Field | Value |
|-------|-------|
| SHA-256 | `F3B92C0088A874A739FCD64BF25FA714010078C3EB95DFDF81ADC92335841DE2` |
| Bytes | 519 328 |
| Kind | SCHEMA_PLUS_ANONYMIZED_DATA |
| leak_scan | **PASS** |
| Evidence | `docs/ai/rc1/evidence/ops1_legacy_anonymization.json` |

### Settings markers observed (from candidate; not production proof of code SHA)

- `schema_version` = `3.1.0`
- `saas_schema_version` = `5.0.0`
- `accounting_extended_schema_version` = `7.0.0`
- `ai_schema_version` = `1.1.0`

## What is proven

- Owner confirmed a prior install existed → Path1 `FRESH_INSTALL` is **invalid**.
- Candidate dump matches cPanel-style hosting (`*-cll-lve`, DB prefix naming).
- Offline schema-aware anonymization + leak_scan **PASS**.
- Disposable anonymized restore → clone → migrate×2 → financial/stock recon → recovery clone **PASS**.
- Baseline left unmigrated (110 tables). Migrate/recovery 182 tables.
- Voucher lines Dr=Cr preserved; stock `quantity`/`unit_cost` preserved; mismatch_count=0.

## Hard rules

1. Repo `SCHEMA_VERSION` is **not** production provenance.
2. Do not invent SHA / `FRESH_INSTALL`.
3. No production MySQL, cPanel, install.php-on-live, or live migrate.
4. Prefer offline CLI migrate runner (`scripts/rc1_legacy_schema_migrate_rehearsal.php`) over first HTTP hit.

## Closure criteria — met

1. Verified anonymized legacy artifact with published SHA-256 + provenance id `prior-cpanel-anon-20261121`.
2. Disposable restore → migrate×2 → data reconciliation PASS.
3. Evidence under `docs/ai/rc1/evidence/`.
4. Defect **CLOSED**; `SCHEMA_UPGRADE_TESTED` claimed for disposable Path2 only.
