# RC1 Staging Safety Patch (bounded stabilization)

**Campaign:** ERPSMART FINAL STAGING SAFETY PATCH  
**Product feature baseline (immutable ancestry):** `2c1cf6592f246553c66c3bc90d00385bf65d4c29`  
**Authoritative staging-release package SHA:** `14fcf69c669f63ad33dc40f6e10043aa580e04d0`  
**Prior main before patch:** `4764b3648ad1ec5a143afb81c020514699c6378e` (PR #38 merged)

## One package rule

**Post-merge rule:** After this PR merges and both GHA workflows are green on `main`, replace every `14fcf69c669f63ad33dc40f6e10043aa580e04d0` token with that exact `origin/main` SHA (single authoritative staging-release pin). Until then the token is a deliberate placeholder so the commit does not self-reference the wrong hash.

Deployable staging ZIP / document-root pin = **ONE** SHA:

`14fcf69c669f63ad33dc40f6e10043aa580e04d0`

That tree includes:

- Accepted RC1 product baseline (`2c1cf65…` ancestry)
- PR #38 offline staging migrate CLI + maintenance lock
- This safety patch (auth expected mandatory, web cache sync, restorable backup gates, UAT honesty)

Do **not** ship product-only `2c1cf65` ZIP for staging execute — it lacks staging safeguards.

Separate ops-only artifact is **not** required. If ever split later, both SHAs and compatibility must be documented; current policy is a single executable release.

## Risks closed (engineering / disposable)

| # | Risk | Resolution |
|---|------|------------|
| 1 | Docs pinned deployable ZIP to `2c1cf65` while safeguards landed in #38+ | Single staging-release SHA above; product baseline retained as ancestry only |
| 2 | `RC1_STAGING_AUTH_EXPECTED` optional; `--release-maintenance` weaker | Expected token **required**; release path uses same `staging_require_auth()` |
| 3 | CLI schema marker under isolated cache ≠ web `storage/cache` | Default/required web cache root + same DB fingerprint; first-HTTP probe proves no DDL re-run |
| 4 | 68-byte placeholder backup / UAT page-open as pay proof | Min-bytes + SQL-shape gate; restorable mysqldump drill; UAT inventory classification |

## Explicit non-claims

- **Not** `STAGING_VERIFIED`
- **Not** production deploy / remote staging mutate
- No new product version / features

## Evidence (disposable)

- `evidence/staging_executor_migrate.json`
- `evidence/staging_executor_first_http_probe.json`
- `evidence/staging_executor_backup_integrity.json`
- `evidence/staging_executor_backup_restore_migrate.json`
- UAT classes: `RC1-STAGING-UAT-INVENTORY.md`
