# RC1 Staging — Remote Authorization Checklist (explicit owner gate)

**Status:** Remote staging execute **NOT AUTHORIZED** until every item below is signed by the owner.  
**Immutable product SHA:** `2c1cf6592f246553c66c3bc90d00385bf65d4c29`  
**Labels:** `RC1_ACCEPTED` + engineering `STAGING_READY` ≠ `STAGING_VERIFIED` ≠ `PRODUCTION_READY`.

Agents must **not** deploy, SSH, FTP, or cPanel-mutate staging without a **new** explicit owner authorization that references this checklist.

---

## A. Identity pins (owner confirms)

| # | Item | Owner value / sign-off |
|---|------|------------------------|
| A1 | Product package SHA is exactly `2c1cf659…` (not a dirty tree) | ☐ |
| A2 | Staging host / URL (HTTPS) is non-production | ☐ URL: ________ |
| A3 | Staging MySQL DB name (≠ production) | ☐ DB: ________ |
| A4 | Production DB name declared for refuse-gate (`RC1_PROD_DB_NAME`) | ☐ |
| A5 | Staging document root / deploy method | ☐ Git / ZIP / other: ________ |

## B. Secrets & isolation (out-of-band; never commit)

| # | Item | Sign-off |
|---|------|----------|
| B1 | Staging DB credentials distinct from production | ☐ |
| B2 | Staging `app_key` distinct; encryption guard understood | ☐ |
| B3 | No unredacted dumps / PII in Git or shared chat | ☐ |
| B4 | RuntimeCache path staging-scoped (never clear prod `schema.json`) | ☐ |

## C. Operator migrate path (CLI only)

| # | Item | Sign-off |
|---|------|----------|
| C1 | Named operator for PHP CLI offline migrate | ☐ Name: ________ |
| C2 | Will use `scripts/rc1_staging_schema_migrate.php` (not `install.php`, not first HTTP hit) | ☐ |
| C3 | Pre-migrate backup path + SHA-256 recorded offline | ☐ |
| C4 | Dry-run / preflight reviewed (`RC1_STAGING_PREFLIGHT_REVIEWED=1`) | ☐ |
| C5 | Maintenance window + abort owner | ☐ Window: ________ / Abort: ________ |

## D. Explicit authorization text (required verbatim intent)

Owner must issue **new** written authorization including:

1. Scope = **staging only** (named URL + DB).  
2. Exclude production / live bank / tax / SMS / payment side effects.  
3. Pin package SHA `2c1cf6592f246553c66c3bc90d00385bf65d4c29`.  
4. Authorize offline CLI migrate via `rc1_staging_schema_migrate.php`.  
5. Acknowledge MF residual: WIP/FG may be `UNVALUED` / `GL_BLOCKED`.  
6. Acknowledge that success still requires smoke evidence before any `STAGING_VERIFIED` claim.

**Until D1–D6 exist in writing: remote staging = `NOT_AUTHORIZED` / `NOT_RUN`.**

## E. After authorized execute (owner/operator)

| # | Gate | Result |
|---|------|--------|
| E1 | Migrate ×2 JSON evidence retained | ☐ |
| E2 | Smoke / UAT per `RC1-STAGING-UAT-PROCEDURES.md` | ☐ |
| E3 | Owner decision: `STAGING_VERIFIED` or rollback | ☐ |

Production go-live requires a **separate** authorization — never implied by staging success.
