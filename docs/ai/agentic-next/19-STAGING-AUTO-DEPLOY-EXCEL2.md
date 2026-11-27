# Staging auto-deploy — excel2.bcsrp.ir only

**Scope:** `https://excel2.bcsrp.ir/` → `/home3/zzflgmfd/excel2.bcsrp/`  
**Forbidden:** production `excel.bcsrp.ir` / `/home3/zzflgmfd/excel.bcsrp/`  
**Primary branch:** `main` only for real apply  
**Authoritative sync:** `scripts/staging_remote_apply.py` + `scripts/agentic_controlled_cpanel_sync.py`

## Status model

| Label | Meaning |
|---|---|
| `STAGING_AUTO_DEPLOY_READY` | SSH secrets + pinned known_hosts + identity marker; apply + release health OK |
| `STAGING_AUTO_DEPLOY_PARTIAL` | Package/gates/artifact automation live; remote apply blocked (no SSH / no marker) |
| `STAGING_AUTO_DEPLOY_BLOCKED` | Cannot safely automate |

## Hardening (T0)

1. Apply boolean: **never** `A && B || true` (that coerced `false→true`). Decision helper: `scripts/staging_apply_decision.py` + unit tests.
2. Real apply: `refs/heads/main` only + required CI gates (`release-gate`, `rc1-mysql-php`) — **no skip bypass**.
3. Backup must succeed before any destination mutation; failure → zero dest changes.
4. Scaffold/preserve decisions from **real** remote existence (not a fake dest).
5. Preserve `app/config.php` + storage uploads/logs/cache/user data.
6. Stage-then-publish; atomic file publish when same-FS rename works; else maintenance + backup + rollback.
7. Post-publish verify SHA + `ERPSMART_STAGING_RELEASE.json` checksums; health requires release identity (login page alone ≠ success); failure restores backup.
8. Concurrent deploy lock on host; adversarial unit tests cover SSH drop / backup fail / partial publish / wrong dest / dry-run.
9. Worker: `scripts/staging_worker_install.py` (cron-compatible; no forced daemon).
10. Migrations: preflight gates in `scripts/staging_migrate_preflight.py` — **no auto migrate**; no prod DB.

## GitHub Actions

Workflow: `.github/workflows/staging-deploy.yml`

Triggers: `push` to `main`, `workflow_dispatch` (`apply`, `dry_run_only`).

### Exact SSH activation steps (owner)

1. Create GitHub Environment **`staging`** (not production).
2. Add repository/environment secrets (values never logged/committed):

| Secret | Purpose |
|---|---|
| `STAGING_SSH_HOST` | cPanel SSH hostname/IP |
| `STAGING_SSH_USER` | SSH user |
| `STAGING_SSH_PRIVATE_KEY` | Deploy key (private) |
| `STAGING_SSH_KNOWN_HOSTS` | Pinned host key line(s) for StrictHostKeyChecking=yes |
| optional `STAGING_SSH_PORT` | default 22 |
| optional `STAGING_REMOTE_DEST` | must be `/home3/zzflgmfd/excel2.bcsrp` |

3. On the staging document root create marker file `.erpsmart_deploy_identity` with exact content:

```text
excel2.bcsrp:/home3/zzflgmfd/excel2.bcsrp/
```

4. Push to `main` (or dispatch with `apply=true`) after CI green → remote apply.
5. Confirm artifact `staging-deploy-evidence-<sha>` contains apply + health JSON with matching `git_sha`.

### If SSH secrets missing

Declare **Remote Deploy BLOCKED / PARTIAL** only — packaging + product work continue.  
`.cpanel.yml` remains fail-closed (`/bin/false`). Do not re-enable blind Git sync.

## Worker + provider rebuild (keys private env only)

```bash
# Dry-run worker sync plan (staging dest only)
python scripts/staging_worker_install.py --dest /path/to/staging/engine --print-provider-rebuild

# Apply code sync; preserves host .env* / config.json
python scripts/staging_worker_install.py --src engine --dest /path/to/staging/engine \
  --apply --i-understand-staging-risk

# Install suggested cron (cPanel Cron UI) — no systemd required
# */5 * * * * cd <dest> && python3 worker.py --once --callback https://excel2.bcsrp.ir/ai_live.php
```

### Gemini

```text
cd engine
# keys ONLY in private engine/.env.gemini (never commit)
cp -n config.gemini.example.json config.gemini.json
python gemini_check.py
python list_provider_models.py --provider gemini
```

### Grok / xAI

```text
cd engine
# keys ONLY in private engine/.env.xai (never commit)
python -c "import provider_gateway as g; print('gateway_ok')"
python list_provider_models.py --provider xai
```

## Migrations

See `20-STAGING-MIGRATION-INVENTORY.md`. Preflight:

```bash
python scripts/staging_migrate_preflight.py \
  --db-name <staging_db_from_secure_config> \
  --backup /secure/path/staging.sql.gz \
  --ack MIGRATE_STAGING_EXCEL2
```

Auto-migrate remains **DISABLED** until identity + backup gates pass. Never production DB.

## Rollback

- Code: restore `_erpsmart_staging_code_bak_*` (automatic on verify/health failure)
- DB: restore pre-migrate dump only
- Never claim DB rollback from deploy workflow

## Production

**No production deploy from this workflow or document.**
