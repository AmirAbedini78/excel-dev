# Staging migration inventory (no auto-migrate on first deploy)

**Rule:** Successful staging **code** deploy ≠ migrate authorization.

## Inventory

| Area | Entry | Notes |
|---|---|---|
| AI control plane | `AiSchema::migrate` | proposals, jobs, RAG tables; additive IF NOT EXISTS style |
| Suite | `ErpSuiteDomain` bootstrap/migrate paths | leave/expense/employees/… |
| Enterprise | `EnterpriseDomain::migrate` | `erp_ext_*` + catalog tables |
| RC1 cumulative | `scripts/rc1_staging_schema_migrate.php` | operator CLI; backup + auth + prod refuse |

## Pre-migrate checklist (staging only)

1. Read DB name from **secure staging config** (not chat, not git).  
2. Confirm name ≠ production / refuse-list.  
3. Restorable mysqldump + SHA-256 offline.  
4. Code package SHA already deployed and healthy.  
5. Explicit owner/operator flag (`MIGRATE_STAGING_EXCEL2` / CLI auth env).  
6. Run CLI migrate; keep JSON evidence.  
7. No production host/DB.

## First deploy policy (Universal ERP / PR #57)

**Do not migrate automatically.** Browser/API may show schema-missing errors until operator migrate — that is preferred over mutating an unverified DB.
