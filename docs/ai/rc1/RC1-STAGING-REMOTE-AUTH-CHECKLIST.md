# RC1 Staging — Remote Authorization Checklist (explicit owner gate)

**Status:** Remote staging execute **NOT AUTHORIZED** until every item below is signed by the owner.  
**Authoritative staging-release SHA:** `POST_MERGE_MAIN_TIP_CI_GREEN`  
**Product feature baseline (ancestry):** `2c1cf6592f246553c66c3bc90d00385bf65d4c29`  
**Labels:** `RC1_ACCEPTED` + engineering `STAGING_READY` â‰  `STAGING_VERIFIED` â‰  `PRODUCTION_READY`.

Agents must **not** deploy, SSH, FTP, or cPanel-mutate staging without a **new** explicit owner authorization that references this checklist.

---

## A. Identity pins (owner confirms)

| # | Item | Owner value / sign-off |
|---|------|------------------------|
| A1 | Staging-release package SHA is exactly `POST_MERGE_MAIN_TIP_CI_GREEN` (includes migrate safeguards; not product-only `2c1cf65`) | âکگ |
| A2 | Staging host / URL (HTTPS) is non-production | âکگ URL: ________ |
| A3 | Staging MySQL DB name (â‰  production) | âکگ DB: ________ |
| A4 | Production DB name declared for refuse-gate (`RC1_PROD_DB_NAME`) | âکگ |
| A5 | Staging document root / deploy method | âکگ Git / ZIP / other: ________ |

## B. Secrets & isolation (out-of-band; never commit)

| # | Item | Sign-off |
|---|------|----------|
| B1 | Staging DB credentials distinct from production | âکگ |
| B2 | Staging `app_key` distinct; encryption guard understood | âکگ |
| B3 | No unredacted dumps / PII in Git or shared chat | âکگ |
| B4 | RuntimeCache root matches web (`storage/cache`) + same DB fingerprint; never clear prod `schema.json` | âکگ |

## C. Operator migrate path (CLI only)

| # | Item | Sign-off |
|---|------|----------|
| C1 | Named operator for PHP CLI offline migrate | âکگ Name: ________ |
| C2 | Will use `scripts/rc1_staging_schema_migrate.php` (not `install.php`, not first HTTP hit) | âکگ |
| C3 | Pre-migrate **restorable** SQL backup path + SHA-256 recorded offline (not placeholder) | âکگ |
| C4 | Dry-run / preflight reviewed (`RC1_STAGING_PREFLIGHT_REVIEWED=1`) | âکگ |
| C5 | Maintenance window + abort owner | âکگ Window: ________ / Abort: ________ |
| C6 | `RC1_STAGING_AUTH_EXPECTED` set and matches operator token (mandatory) | âکگ |

## D. Explicit authorization text (required verbatim intent)

Owner must issue **new** written authorization including:

1. Scope = **staging only** (named URL + DB).  
2. Exclude production / live bank / tax / SMS / payment side effects.  
3. Pin staging-release package SHA `POST_MERGE_MAIN_TIP_CI_GREEN`.  
4. Authorize offline CLI migrate via `rc1_staging_schema_migrate.php` with mandatory `RC1_STAGING_AUTH_EXPECTED`.  
5. Acknowledge MF residual: WIP/FG may be `UNVALUED` / `GL_BLOCKED`.  
6. Acknowledge that success still requires smoke evidence before any `STAGING_VERIFIED` claim.

**Until D1â€“D6 exist in writing: remote staging = `NOT_AUTHORIZED` / `NOT_RUN`.**

## E. After authorized execute (owner/operator)

| # | Gate | Result |
|---|------|--------|
| E1 | Migrate أ—2 JSON evidence retained | âکگ |
| E2 | Smoke / UAT per `RC1-STAGING-UAT-PROCEDURES.md` | âکگ |
| E3 | Owner decision: `STAGING_VERIFIED` or rollback | âکگ |

Production go-live requires a **separate** authorization — never implied by staging success.
