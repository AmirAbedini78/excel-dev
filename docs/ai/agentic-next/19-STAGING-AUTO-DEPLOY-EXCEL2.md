# Staging auto-deploy — excel2.bcsrp.ir only

**Scope:** `https://excel2.bcsrp.ir/` → `/home3/zzflgmfd/excel2.bcsrp/`  
**Forbidden:** production `excel.bcsrp.ir` / `/home3/zzflgmfd/excel.bcsrp/`  
**Primary branch:** `main`  
**Authoritative sync:** `scripts/agentic_controlled_cpanel_sync.py` (+ remote apply wrapper)

## Status model

| Label | Meaning |
|---|---|
| `STAGING_AUTO_DEPLOY_READY` | SSH secrets + pinned known_hosts + identity marker on host; workflow apply succeeds + health OK |
| `STAGING_AUTO_DEPLOY_PARTIAL` | Package/gates/artifact automation live; remote apply blocked (no SSH / no marker / no cPanel Git pull automation) |
| `STAGING_AUTO_DEPLOY_BLOCKED` | Cannot safely automate (missing host access path and no operator alternative enabled) |

## GitHub Actions

Workflow: `.github/workflows/staging-deploy.yml`

Triggers: `push` to `main`, `workflow_dispatch`.

Steps:

1. Controlled-sync selftest + health allowlist selftest + package build (exact commit) + dry-run plan  
2. Commercial release gate (same strength)  
3. Await `release-gate` + `rc1-mysql-php` SUCCESS on the **same SHA**  
4. `environment: staging` deploy job:
   - If `STAGING_SSH_*` secrets missing → **PARTIAL** (artifact retained; no mutate)
   - If present → OpenSSH with **StrictHostKeyChecking=yes** + pinned `STAGING_SSH_KNOWN_HOSTS`
   - Allowlisted file copy only; no `rm -rf`; no `rsync --delete`
   - Preserve `app/config.php` + storage uploads/logs/cache
   - Post HTTPS health to `https://excel2.bcsrp.ir/` only
5. Migrations: **NOT_RUN** (see `staging-migrate.yml` — dispatch ACK only; CLI migrate remains operator-owned)
6. Worker/`engine/`: **OUT_OF_SCOPE** (version-match separately or leave PARTIAL)

### Secrets (names only)

- `STAGING_SSH_HOST`
- `STAGING_SSH_USER`
- `STAGING_SSH_PRIVATE_KEY`
- `STAGING_SSH_KNOWN_HOSTS`
- optional `STAGING_SSH_PORT`, `STAGING_REMOTE_DEST`

Create GitHub Environment `staging` (no production environment linkage).

### Host precondition

On the staging document root, operator must create:

```text
.erpsmart_deploy_identity
```

Content exactly:

```text
excel2.bcsrp:/home3/zzflgmfd/excel2.bcsrp/
```

## cPanel capabilities (shared hosting)

Do **not** assume Docker, Redis, Node daemon, rsync daemon, or unrestricted SSH.

| Capability | Staging use |
|---|---|
| PHP web | Required |
| MySQL | Staging DB only (verify name before migrate) |
| Cron | Optional worker heartbeat — not auto-wired here |
| Git Version Control | Optional manual Update/Deploy — **`.cpanel.yml` remains fail-closed** (`/bin/false`) so blind Git deploy cannot overwrite storage |
| SSH/SFTP | Preferred automation path when owner enables + pins host key |

### If SSH unavailable

1. Keep Actions packaging (PARTIAL).  
2. Owner downloads `staging-pkg-<sha>` artifact.  
3. On a verified mirror, dry-run:

```bash
python scripts/agentic_controlled_cpanel_sync.py --src <pkg> --dest <mirror> --manifest-out dry.json
```

4. Apply only after backup + identity marker:

```bash
python scripts/agentic_controlled_cpanel_sync.py --src <pkg> --dest <verified> --apply --i-understand-production-risk --require-identity-marker --manifest-out apply.json
```

5. Or enable SSH + secrets to reach `STAGING_AUTO_DEPLOY_READY`.  
6. Do **not** re-enable blind `.cpanel.yml` deploy.

## Migrations

Inventory (additive / first-request domains — not auto-run on deploy):

- `AiSchema::migrate` — AI tables / proposals  
- `ErpSuiteDomain` schema paths — suite entities  
- `EnterpriseDomain::migrate` — enterprise extension tables  
- RC1 cumulative migrate CLI: `scripts/rc1_staging_schema_migrate.php` (auth + backup + prod-DB refuse)

**First deploy after PR #57:** code-only. Migrate only after staging DB name/identity verified from secure config + restorable backup + explicit operator flag. Workflow `staging-migrate.yml` is fail-closed ACK (does not SSH-migrate in v1).

## Rollback

- Code: redeploy prior package SHA / restore `_erpsmart_staging_code_bak_*`  
- DB: restore pre-migrate dump only (no automated down-migration)  
- Never claim DB rollback from the deploy workflow

## Production

**No production deploy from this workflow or document.**
