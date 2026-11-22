# RC1 Staging Release Package Manifest

**Agent B / T0 safety patch** — reproducible staging artifact metadata (no deploy authorization).

---

## Release identity

| Field | Value |
|-------|--------|
| Repository | `AmirAbedini78/excel-dev` |
| **Authoritative staging-release SHA** | **`14fcf69c669f63ad33dc40f6e10043aa580e04d0`** |
| Short pin | `14fcf69` |
| Product feature baseline (ancestry) | `2c1cf6592f246553c66c3bc90d00385bf65d4c29` |
| Includes | RC1 product + PR #38 staging executor + safety patch (auth/cache/backup) |
| Schema (repo constant) | `10.24.0-rc1` (`RuntimeCache::SCHEMA_VERSION`) |
| Functional currency | IRR (Toman display optional) |
| RC1 engineering decision | `RC1_ACCEPTED` on product baseline; staging package supersedes product-only ZIP |

### Explicit non-claims

| Label | Status |
|-------|--------|
| **STAGING_VERIFIED** | **NO** — remote staging auth not granted |
| **WAITING_FOR_STAGING_HOST** | **YES** — package + offline executor ready; owner URL/DB/auth missing |
| **PRODUCTION_READY** | **NO** |
| **PRODUCTION_DEPLOYED** | **NO** |
| Local browser class | `LOCAL_BROWSER_VERIFIED` (separate from this manifest) |

### Local rebuild evidence (2026-11-22 — operator cache only, not committed)

| Field | Value |
|-------|--------|
| Build | `git archive --format=zip -o erpsmart-rc1-staging.zip 14fcf69c669f63ad33dc40f6e10043aa580e04d0` |
| Bytes | `2188129` |
| Archive SHA-256 | `f9400aebdeb16b8d7f0ce39085354157624a02bfd9578d496a043da196cfdbf2` |
| Tree manifest SHA-256 | `f3a5c012cbe26be1b1334cfe9c2695e1d35e829ad816f1ef7ff9a061e39bdc03` |
| File count (hashed tree) | `489` |
| Disposable migrate executor | `tests.rc1.test_rc1_staging_migrate_executor` **12/12 OK** (local MySQL sandbox; not remote) |

---

## Package artifact

| Field | Guidance |
|-------|----------|
| Suggested filename | `erpsmart-rc1-staging-14fcf69c669f63ad33dc40f6e10043aa580e04d07.zip` |
| Build command | `git archive --format=zip -o erpsmart-rc1-staging.zip 14fcf69c669f63ad33dc40f6e10043aa580e04d0` |
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
