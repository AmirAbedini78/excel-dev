# RC1 Staging Release Package Manifest

**Agent B / T0 safety patch** — reproducible staging artifact metadata (no deploy authorization).

---

## Release identity

| Field | Value |
|-------|--------|
| Repository | `AmirAbedini78/excel-dev` |
| **Authoritative staging-release SHA** | **`POST_MERGE_MAIN_TIP_CI_GREEN`** |
| Short pin | *(first 7 of staging-release SHA)* |
| Product feature baseline (ancestry) | `2c1cf6592f246553c66c3bc90d00385bf65d4c29` |
| Includes | RC1 product + PR #38 staging executor + safety patch (auth/cache/backup) |
| Schema (repo constant) | `10.24.0-rc1` (`RuntimeCache::SCHEMA_VERSION`) |
| Functional currency | IRR (Toman display optional) |
| RC1 engineering decision | `RC1_ACCEPTED` on product baseline; staging package supersedes product-only ZIP |

### Explicit non-claims

| Label | Status |
|-------|--------|
| **STAGING_VERIFIED** | **NO** — remote staging auth not granted |
| **PRODUCTION_READY** | **NO** |
| **PRODUCTION_DEPLOYED** | **NO** |
| Local browser class | `LOCAL_BROWSER_VERIFIED` (separate from this manifest) |

---

## Package artifact

| Field | Guidance |
|-------|----------|
| Suggested filename | `erpsmart-rc1-staging-POST_MERGE_MAIN_TIP_CI_GREEN7.zip` |
| Build command | `git archive --format=zip -o erpsmart-rc1-staging.zip POST_MERGE_MAIN_TIP_CI_GREEN` |
| Integrity doc | `RC1-STAGING-PACKAGE-INTEGRITY.md` |
| Must include | `scripts/rc1_staging_schema_migrate.php`, `scripts/lib/rc1_cumulative_migrate.php`, `storage/maintenance` bootstrap path, `app/bootstrap.php` offline lock |

### Rollback refs

| Ref | Role |
|-----|------|
| Staging-release SHA | Forward deploy / recreate ZIP |
| `4764b36…` (PR #38 merge) | Pre-safety-patch main |
| `2c1cf65…` | Product-only baseline — **not** sufficient for staging execute |

---

## How to compute file hashes

**PowerShell:** `Get-FileHash -Algorithm SHA256 erpsmart-rc1-staging.zip`  
**POSIX:** `sha256sum erpsmart-rc1-staging.zip`  
**Tree manifest:** `python scripts/rc1_staging_package_hash.py erpsmart-rc1-staging.zip`

Record archive SHA-256 in operator evidence after each build — do not reuse historical `2c1cf65` ZIP digests.

---

## Dependency inventory

Unchanged runtime: PHP **8.2+**, MySQL **8.0.x**, extensions per integrity doc. No Composer.

Operator migrate requires CLI env: `RC1_STAGING_OPERATOR_AUTH`, **`RC1_STAGING_AUTH_EXPECTED` (mandatory)**, identity, preflight, backup path+SHA, staging DB keys, `RC1_PROD_DB_NAME`.

---

## CI evidence

Post-merge gates on the **staging-release SHA** (both workflows green) are authoritative for this package. Historical product-SHA runs remain valid for ancestry only.

---

## Operator sign-off block (blank)

| Check | Owner initials / date |
|-------|------------------------|
| `git rev-parse` == staging-release SHA | |
| Archive SHA-256 recorded | |
| Package includes migrate CLI + maintenance guard | |
| Staging DB isolated from production | |
| **`STAGING_VERIFIED`** | **Not claimed in this manifest** |

## References

- `RC1-STAGING-SAFETY-PATCH.md`
- `RC1-STAGING-PACKAGE-INTEGRITY.md`
- `RC1-STAGING-REMOTE-AUTH-CHECKLIST.md`
