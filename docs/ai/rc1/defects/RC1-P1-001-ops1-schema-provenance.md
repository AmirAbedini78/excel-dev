# Defect RC1-P1-001 — OPS-1 production/staging schema provenance unavailable

| Field | Value |
|-------|-------|
| ID | RC1-P1-001 |
| Severity | P1 |
| Contract | OPS-1 |
| Owner | **User/staging** (dump or Path1 confirmation) / **T7** (rehearsal) |
| Status | **OPEN** — `EXISTING_DEPLOYMENT_CONFIRMED` / `LEGACY_SCHEMA_UNVERIFIED` |
| Prior note | Path1 `FRESH_INSTALL` **invalid** after owner confirmed prior cPanel/server install |
| Reporter | T7 / Independent auditor / T0 Migration Architect |
| Date | 2026-11-21 |

## Summary
OPS-1 requires cumulative migration evidence from an *actual prior deployed schema*. Owner confirmed prior install → **do not invent `FRESH_INSTALL`**. Candidate dump received but **unredacted secrets/PII** → artifact remains `LEGACY_SCHEMA_UNVERIFIED` until anonymized Path2 + data reconciliation. Repo `RuntimeCache::SCHEMA_VERSION` is **not** production proof. **Do not claim `SCHEMA_UPGRADE_TESTED`** on schema-only rehearsal alone.

## Actual
- Deployment status: **`EXISTING_DEPLOYMENT_CONFIRMED`**.
- Candidate: `zzflgmfd_excel2.sql` SHA-256 `2D695C068CD8EBAE6C8010FE6A9FE254E6F0392B97B5D6FB1329DE07A63419EC` (offline only).
- Structural schema-only sanitize + disposable migrate×2 + rollback: **PASS** (`ops1_legacy_schema_structural_rehearsal.json`) — **not** full upgrade acceptance.
- Independent classification: **BLOCKED_EXTERNAL** until Path2 data artifact verified.
- Engineering synthetic restore drill remains **CLOSED** (`ops1_backup_restore_closure.json`).

## Owner actions

### Path1 — CLOSED / INVALID
Owner confirmed earlier installation existed → Path1 wording must **not** be used.

### Path2 — anonymized prior schema + data (REQUIRED)
1. Authorize anonymized export (PII/secrets scrubbed) — see `LEGACY-SCHEMA-EXPORT-INSTRUCTIONS.md`.  
2. Provide dump artifact id + sha256 (anonymized file).  
3. Set `RC1_PROD_SCHEMA_PROVENANCE=<artifact-id>`.  
4. Restore into a **new** disposable schema only (keep untouched baseline).  
5. Run cumulative migrate ×2 + data reconciliation + rollback restore; attach evidence under `docs/ai/rc1/evidence/`.

## Fix path
No cPanel/prod deploy by agents. After Path2 data rehearsal PASS, close RC1-P1-001 and update blockers/acceptance.
