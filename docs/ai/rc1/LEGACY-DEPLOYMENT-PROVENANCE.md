# Legacy Deployment Provenance — RC1-P1-001

**Owner:** T0 Migration Architect  
**Date:** 2026-11-21  
**origin/main (verified):** `e474ff377731ad2d482b2bf4fec6c3d1a7d32c5c`  
**Repo schema constant:** `RuntimeCache::SCHEMA_VERSION = 10.24.0-rc1`  

## Status labels (authoritative)

| Label | Value |
|-------|-------|
| Deployment | **`EXISTING_DEPLOYMENT_CONFIRMED`** (owner: prior ERPSMART install on server/cPanel with DB) |
| Artifact | **`LEGACY_SCHEMA_UNVERIFIED`** |
| Fresh install | **`NOT_ASSUMED`** — never invent `FRESH_INSTALL` |
| Schema upgrade tested | **`NOT_CLAIMED`** — structural-only rehearsal ≠ full upgrade proof |
| RC1-P1-001 | **OPEN** / `BLOCKED_WAITING_FOR_LEGACY_SCHEMA` (sanitized data Path2 incomplete) |

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
| Safety | **`UNSAFE_UNREDACTED_SECRETS_AND_PII`** — password hashes, `cron_secret`, tokens, emails/phones; **must not enter Git** |

### Settings markers observed (from candidate; not production proof of code SHA)

- `schema_version` = `3.1.0`
- `saas_schema_version` = `5.0.0`
- `accounting_extended_schema_version` = `7.0.0`
- `ai_schema_version` = `1.1.0`

## What is known

- Owner confirmed a prior install existed → Path1 `FRESH_INSTALL` is **invalid**.
- Candidate dump matches cPanel-style hosting (`*-cll-lve`, DB prefix naming).
- Offline schema-only sanitize produced structural restore input (SHA-256 `3256AABC0EFC1E52AD5ADDA7576F39BA25D09B986AC18C0DF447E0BFEB832015`) **outside Git**.
- Disposable structural migrate×2 on clone: **PASS** (110→182 tables). See `evidence/ops1_legacy_schema_structural_rehearsal.json`.

## What remains unknown / unverified

- Exact production/staging host identity and deploy code SHA at dump time.
- Whether dump is complete vs selective tables.
- Business-data preservation (vouchers, stock, balances) — **schema-only cannot prove**.
- Owner authorization that the sanitized Path2 artifact is the accepted provenance id.

## Hard rules

1. Repo `SCHEMA_VERSION` is **not** production provenance.
2. Do not invent SHA / `FRESH_INSTALL` / `SCHEMA_UPGRADE_TESTED`.
3. No production MySQL, cPanel, install.php-on-live, or live migrate.
4. Prefer offline CLI migrate runner (`scripts/rc1_legacy_schema_migrate_rehearsal.php`) over first HTTP hit.

## Closure criteria for RC1-P1-001

1. Verified **anonymized** legacy artifact (schema+data) with published SHA-256 + owner Path2 id.  
2. Disposable restore → pre-invariants → migrate to RC1 → migrate×2 → data reconciliation PASS.  
3. Evidence attached under `docs/ai/rc1/evidence/`.  
4. Then and only then: close defect and claim upgrade tested.
