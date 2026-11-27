# Staging Cron Pull Deploy — excel2.bcsrp.ir only (PHP host runtime)

**Scope:** `https://excel2.bcsrp.ir/` → `/home3/zzflgmfd/excel2.bcsrp/`  
**Bootstrap (outside docroot):** `/home3/zzflgmfd/erpsmart-deploy/`  
**Forbidden:** production `excel.bcsrp.ir` / `/home3/zzflgmfd/excel.bcsrp/`  
**CLI:** `scripts/staging_cron_deploy.php` (NOT a public web endpoint)  
**Host runtime:** PHP CLI ≥ 8.1 (cPanel jailshell — Python3 unavailable)  
**Package publisher:** `.github/workflows/staging-deploy.yml` → GitHub Release Assets (CI may still use Python)

> **Supersedes Python-host path:** `staging_cron_deploy.py` remains in-repo for parity/tests, but **cPanel cron must use PHP**.

## Status model

| Label | Meaning |
|---|---|
| `STAGING_PHP_CRON_READY` | One-time PHP cron installed on host; Release published; dry-run+apply verified; published SHA matches Release |
| `STAGING_PHP_CRON_PARTIAL` | Automation in repo + Release publish live; host cron / identity marker / first apply still owner action |
| `STAGING_PHP_CRON_BLOCKED` | Cannot safely automate |
| Legacy `STAGING_CRON_PULL_*` | Superseded by `STAGING_PHP_CRON_*` for this host |

**Truthfulness:** Never claim `STAGING_DEPLOY_VERIFIED` / `STAGING_PHP_CRON_READY` from ZIP build or local unit tests alone.

## Pipeline

```text
GitHub main
  → CI Gates (release-gate + rc1-mysql-php on exact SHA)
  → Verified Release Package (GitHub Release Assets)
  → cPanel Cron (PHP CLI every 5 min)
  → Controlled Sync (erpsmart_controlled_sync.php)
  → Health Check (+ rollback)
```

Reuses contracts from: `agentic_controlled_cpanel_sync.py` (ported to PHP lib), `staging_package_build.py` (CI), `staging_health_check.py` (ported), `staging_worker_install.py` (Windows/Python Worker), `staging_migrate_preflight.py` (manual; auto-migration OFF).

## Owner one-time setup

1. After a green main run, download **`erpsmart-cron-bootstrap.zip`** from the GitHub Release `staging-excel2-<sha>` (Release Assets — not Actions artifacts).
2. Extract under **`/home3/zzflgmfd/erpsmart-deploy/`** (private; outside docroot) via File Manager or SSH.
3. Copy `config.example.json` → `config.json` (`enabled: false` initially).
4. On docroot create marker `.erpsmart_deploy_identity` with exact bytes:

```text
excel2.bcsrp:/home3/zzflgmfd/excel2.bcsrp/
```

5. Dry-run:

```bash
cd /home3/zzflgmfd/erpsmart-deploy
/usr/bin/php bin/staging_cron_deploy.php \
  --bootstrap /home3/zzflgmfd/erpsmart-deploy \
  --config config.json --dry-run
```

6. Status check:

```bash
/usr/bin/php bin/staging_cron_deploy.php --bootstrap /home3/zzflgmfd/erpsmart-deploy --config config.json --status
```

7. Set `"enabled": true`, then add cPanel Cron every 5 minutes:

```cron
*/5 * * * * cd /home3/zzflgmfd/erpsmart-deploy && /usr/bin/php /home3/zzflgmfd/erpsmart-deploy/bin/staging_cron_deploy.php --bootstrap /home3/zzflgmfd/erpsmart-deploy --config /home3/zzflgmfd/erpsmart-deploy/config.json --apply --i-understand-staging-risk >> /home3/zzflgmfd/erpsmart-deploy/logs/cron_deploy.log 2>&1
```

8. Confirm `state/state.json` → `published_sha` and `https://excel2.bcsrp.ir/ERPSMART_STAGING_RELEASE.json`.

### Paths cheat-sheet

| Item | Path |
|---|---|
| Bootstrap | `/home3/zzflgmfd/erpsmart-deploy/` |
| Config | `/home3/zzflgmfd/erpsmart-deploy/config.json` |
| Log | `/home3/zzflgmfd/erpsmart-deploy/logs/cron_deploy.log` |
| State / published SHA | `/home3/zzflgmfd/erpsmart-deploy/state/state.json` |
| Backups | `/home3/zzflgmfd/erpsmart-deploy/backups/_erpsmart_staging_code_bak_*` |
| Docroot | `/home3/zzflgmfd/excel2.bcsrp/` |
| Entry | `bin/staging_cron_deploy.php` |

## Safety guarantees

- Poll latest valid Release only; reject failed gates, wrong host, non-main tag/SHA, stale releases
- HTTPS download + SHA256 sidecar + file manifest verification
- ZIP Slip / symlink / absolute path refusal
- Concurrent cron lock (`flock`)
- Valid backup before any dest mutation; backup fail → change NOTHING
- Preserve `app/config.php`, storage uploads/logs/cache, env, unknown dest files
- Health + published SHA verify; rollback previous code on fail
- Log rotation, download timeout, disk space floor, keep last N backups
- No Docker/Redis/Node/systemd required on cPanel
- Migrations separate (auto OFF); Worker update PARTIAL on cPanel — version-matched on Windows

## Worker (Windows owner path)

Python Worker is **not** runnable from cPanel jailshell cron. Keep architecture:

| Component | Where | Runtime |
|---|---|---|
| PHP web package | `excel2.bcsrp` docroot | PHP (host) |
| Deployment Manager | `erpsmart-deploy/` | PHP CLI 8.1+ |
| AI Worker (`engine/worker.py`) | Owner Windows (or Python host) | Python3 |

Version-matched Worker update after a published SHA:

```bash
# keys ONLY in private engine/.env.gemini / engine/.env.xai (never commit / never in Release ZIP)
cd engine
git fetch origin && git checkout <published_sha>
# preserve local config.json and .env.*
python gemini_check.py
python list_provider_models.py --provider gemini
python -c "import provider_gateway as g; print('gateway_ok')"
python list_provider_models.py --provider xai
python worker.py --config config.json --once --callback https://excel2.bcsrp.ir/ai_live.php
```

Optional Windows Task Scheduler / cron equivalent every 5 minutes with `--once`.

## Auto-migration OFF

Cron deploy never runs DB migrations. Before any manual migrate: confirm staging DB identity + take DB backup (`staging_migrate_preflight.py` / RC1 migrate docs).

## Adversarial coverage

`tests/php_staging_cron_deploy_adversarial_test.php` + `staging_cron_deploy.php --selftest` (PHP 8.1/8.2/8.3 in CI):

no new release · CI failed gates · bad checksum/manifest · wrong identity · concurrent cron · interrupted download · backup fail · partial deploy / secret preservation · health fail+rollback · stale release reject · ZIP Slip

Python parity suite remains: `tests/test_staging_cron_deploy_adversarial.py`.

## Relation to SSH auto-deploy

`19-STAGING-AUTO-DEPLOY-EXCEL2.md` remains valid for optional SSH push-apply. PHP cron pull is the **primary** path when the owner has no SSH and no Python on cron.
