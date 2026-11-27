# ERPSMART Staging Cron Pull Bootstrap (excel2 only) — PHP host runtime

One-time install under a **private path outside the web docroot**.

> **Host note (cPanel jailshell):** Python3 is **not** available. The Deployment Manager is **PHP CLI** (8.1+). GitHub Actions may still use Python for package build.

## Exact install path

```text
/home3/zzflgmfd/erpsmart-deploy/
```

Forbidden: anything under `/home3/zzflgmfd/excel.bcsrp/` (production).

## Layout after extract

```text
/home3/zzflgmfd/erpsmart-deploy/
  bin/staging_cron_deploy.php         # PHP CLI Deployment Manager (trusted)
  lib/erpsmart_controlled_sync.php    # Controlled Sync contracts
  lib/erpsmart_staging_health.php
  config.json                         # from config.example.json
  state/state.json                    # published SHA history
  locks/deploy.lock
  backups/
  work/
  logs/cron_deploy.log
```

## Docroot identity marker (required before apply)

On `/home3/zzflgmfd/excel2.bcsrp/` create `.erpsmart_deploy_identity` with exact content:

```text
excel2.bcsrp:/home3/zzflgmfd/excel2.bcsrp/
```

## Cron (every 5 minutes) — PHP

```cron
*/5 * * * * cd /home3/zzflgmfd/erpsmart-deploy && /usr/bin/php /home3/zzflgmfd/erpsmart-deploy/bin/staging_cron_deploy.php --bootstrap /home3/zzflgmfd/erpsmart-deploy --config /home3/zzflgmfd/erpsmart-deploy/config.json --apply --i-understand-staging-risk >> /home3/zzflgmfd/erpsmart-deploy/logs/cron_deploy.log 2>&1
```

If the account uses a selector binary (e.g. `/usr/local/bin/php`), set that path instead of `/usr/bin/php`. Default CLI on this host is PHP 8.1.34.

## Modes

```bash
cd /home3/zzflgmfd/erpsmart-deploy
cp -n config.example.json config.json   # keep "enabled": false

# Dry-run (plan only)
/usr/bin/php bin/staging_cron_deploy.php --bootstrap "$PWD" --config config.json --dry-run

# Status
/usr/bin/php bin/staging_cron_deploy.php --bootstrap "$PWD" --config config.json --status

# Apply (after enabled=true + identity marker)
/usr/bin/php bin/staging_cron_deploy.php --bootstrap "$PWD" --config config.json --apply --i-understand-staging-risk

# Manual code rollback (latest backup)
/usr/bin/php bin/staging_cron_deploy.php --bootstrap "$PWD" --config config.json --rollback --i-understand-staging-risk

# Selftest (fixture only)
/usr/bin/php bin/staging_cron_deploy.php --selftest
```

## Log path

`/home3/zzflgmfd/erpsmart-deploy/logs/cron_deploy.log`

## Published SHA

`/home3/zzflgmfd/erpsmart-deploy/state/state.json` → `published_sha`  
Also published to docroot as `/ERPSMART_STAGING_RELEASE.json` (`git_sha`).

## Rollback

Automatic on health/SHA failure (restores `_erpsmart_staging_code_bak_*` under `backups/`).  
Manual: `--rollback` (never restores DB).

## Worker (Python) — independent of cPanel cron PHP

cPanel cron **cannot** run the Python Worker. Keep Worker on the owner Windows machine (or any host with Python3), version-matched to the same `git_sha`:

```text
# On Windows Worker host (keys only in private engine/.env.* — never commit)
cd engine
git fetch && git checkout <published_sha>
# preserve config.json / .env.gemini / .env.xai
python gemini_check.py
python list_provider_models.py --provider gemini
python -c "import provider_gateway as g; print('gateway_ok')"
python list_provider_models.py --provider xai
python worker.py --config config.json --once --callback https://excel2.bcsrp.ir/ai_live.php
```

Migrations remain **OFF** on cron deploy. Use migrate preflight + staging DB identity backup before any manual migrate.

## Safety

- Never executes Release ZIP contents as code before checksum + manifest + gates validation.
- Never overwrites `app/config.php`, uploads, env, or unknown dest files.
- Concurrent cron is locked (`flock`); second instance exits cleanly.
- `STAGING_DEPLOY_VERIFIED` / `STAGING_PHP_CRON_READY` only after real host cron + package publish + SHA match — not from local ZIP build alone.
