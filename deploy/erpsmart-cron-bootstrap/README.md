# ERPSMART Staging Cron Pull Bootstrap (excel2 only)

One-time install under a **private path outside the web docroot**.

## Exact install path

```text
/home3/zzflgmfd/erpsmart-deploy/
```

Forbidden: anything under `/home3/zzflgmfd/excel.bcsrp/` (production).

## Layout after extract

```text
/home3/zzflgmfd/erpsmart-deploy/
  bin/staging_cron_deploy.py          # fixed trusted CLI (copy from repo scripts/)
  lib/                                # copies of controlled sync + health helpers
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

## Cron (every 5 minutes)

```cron
*/5 * * * * cd /home3/zzflgmfd/erpsmart-deploy && /usr/bin/python3 /home3/zzflgmfd/erpsmart-deploy/bin/staging_cron_deploy.py --bootstrap /home3/zzflgmfd/erpsmart-deploy --config /home3/zzflgmfd/erpsmart-deploy/config.json --apply --i-understand-staging-risk >> /home3/zzflgmfd/erpsmart-deploy/logs/cron_deploy.log 2>&1
```

## Dry-run then enable apply

```bash
cd /home3/zzflgmfd/erpsmart-deploy
cp -n config.example.json config.json
# keep "enabled": false
/usr/bin/python3 bin/staging_cron_deploy.py --bootstrap "$PWD" --config config.json --dry-run
# review logs/cron_deploy.log — then set "enabled": true
```

## Log path

`/home3/zzflgmfd/erpsmart-deploy/logs/cron_deploy.log`

## Published SHA

`/home3/zzflgmfd/erpsmart-deploy/state/state.json` → `published_sha`  
Also published to docroot as `/ERPSMART_STAGING_RELEASE.json` (`git_sha`).

## Rollback

Automatic on health/SHA failure (restores `_erpsmart_staging_code_bak_*` under `backups/`).  
Manual: copy files from latest `backups/_erpsmart_staging_code_bak_*` back into docroot (never restore DB from this tool).

## Safety

- Never executes Release ZIP contents as code before checksum + manifest + gates validation.
- Never overwrites `app/config.php`, uploads, env, or unknown dest files.
- Concurrent cron is locked; second instance exits cleanly.
- `STAGING_CRON_PULL_READY` is only claimed after real host cron + published SHA verification — not from local ZIP build alone.
