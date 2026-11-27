# Staging Cron Pull Deploy — excel2.bcsrp.ir only

**Scope:** `https://excel2.bcsrp.ir/` → `/home3/zzflgmfd/excel2.bcsrp/`  
**Bootstrap (outside docroot):** `/home3/zzflgmfd/erpsmart-deploy/`  
**Forbidden:** production `excel.bcsrp.ir` / `/home3/zzflgmfd/excel.bcsrp/`  
**CLI:** `scripts/staging_cron_deploy.py` (NOT a public web endpoint)  
**Package publisher:** `.github/workflows/staging-deploy.yml` → GitHub Release Assets

## Status model

| Label | Meaning |
|---|---|
| `STAGING_CRON_PULL_READY` | One-time cron installed on host; Release published; dry-run+apply verified; published SHA matches Release |
| `STAGING_CRON_PULL_PARTIAL` | Automation in repo + Release publish live; host cron / identity marker / first apply still owner action |
| `STAGING_CRON_PULL_BLOCKED` | Cannot safely automate |

**Truthfulness:** Never claim `STAGING_DEPLOY_VERIFIED` / `STAGING_CRON_PULL_READY` from ZIP build or local unit tests alone.

## Pipeline

```text
GitHub main
  → CI Gates (release-gate + rc1-mysql-php on exact SHA)
  → Verified Release Package (GitHub Release Assets)
  → cPanel Cron (every 5 min)
  → Controlled Local Deploy (agentic_controlled_cpanel_sync)
  → Health Check (+ rollback)
```

Reuses existing: `staging_package_build.py`, `agentic_controlled_cpanel_sync.py`, `staging_health_check.py`, `staging_worker_install.py`, `staging_migrate_preflight.py`, SSH path in `staging_remote_apply.py` (optional).

## Owner one-time setup

1. After a green main run, download **`erpsmart-cron-bootstrap.zip`** from the GitHub Release `staging-excel2-<sha>` (Release Assets — not Actions artifacts).
2. Extract under **`/home3/zzflgmfd/erpsmart-deploy/`** (private; outside docroot).
3. Copy `config.example.json` → `config.json` (`enabled: false` initially).
4. On docroot create marker `.erpsmart_deploy_identity` with exact bytes:

```text
excel2.bcsrp:/home3/zzflgmfd/excel2.bcsrp/
```

5. Dry-run:

```bash
cd /home3/zzflgmfd/erpsmart-deploy
/usr/bin/python3 bin/staging_cron_deploy.py \
  --bootstrap /home3/zzflgmfd/erpsmart-deploy \
  --config config.json --dry-run
```

6. Set `"enabled": true`, then add cPanel Cron every 5 minutes:

```cron
*/5 * * * * cd /home3/zzflgmfd/erpsmart-deploy && /usr/bin/python3 /home3/zzflgmfd/erpsmart-deploy/bin/staging_cron_deploy.py --bootstrap /home3/zzflgmfd/erpsmart-deploy --config /home3/zzflgmfd/erpsmart-deploy/config.json --apply --i-understand-staging-risk >> /home3/zzflgmfd/erpsmart-deploy/logs/cron_deploy.log 2>&1
```

7. Confirm `state/state.json` → `published_sha` and `https://excel2.bcsrp.ir/ERPSMART_STAGING_RELEASE.json`.

### Paths cheat-sheet

| Item | Path |
|---|---|
| Bootstrap | `/home3/zzflgmfd/erpsmart-deploy/` |
| Config | `/home3/zzflgmfd/erpsmart-deploy/config.json` |
| Log | `/home3/zzflgmfd/erpsmart-deploy/logs/cron_deploy.log` |
| State / published SHA | `/home3/zzflgmfd/erpsmart-deploy/state/state.json` |
| Backups | `/home3/zzflgmfd/erpsmart-deploy/backups/_erpsmart_staging_code_bak_*` |
| Docroot | `/home3/zzflgmfd/excel2.bcsrp/` |

## Safety guarantees

- Poll latest valid Release only; reject failed gates, wrong host, non-main tag/SHA, stale releases
- HTTPS download + SHA256 sidecar + file manifest verification
- ZIP Slip / symlink / absolute path refusal
- Concurrent cron lock
- Valid backup before any dest mutation; backup fail → change NOTHING
- Preserve `app/config.php`, storage uploads/logs/cache, env, unknown dest files
- Health + published SHA verify; rollback previous code on fail
- No Docker/Redis/Node/systemd required
- Migrations separate (`staging_migrate_preflight.py`); Worker update PARTIAL if infra missing

## Gemini / Grok rebuild + smoke (keys private env only)

```bash
# Gemini — keys ONLY in private engine/.env.gemini (never commit / never in Release ZIP)
cd engine
cp -n config.gemini.example.json config.gemini.json
python gemini_check.py
python list_provider_models.py --provider gemini

# Grok / xAI — keys ONLY in private engine/.env.xai
python -c "import provider_gateway as g; print('gateway_ok')"
python list_provider_models.py --provider xai
```

Worker cron (optional, version-matched SHA):

```text
*/5 * * * * cd <worker_dest> && /usr/bin/python3 worker.py --once --callback https://excel2.bcsrp.ir/ai_live.php >> data/cron_worker.log 2>&1
```

## Adversarial coverage

`tests/test_staging_cron_deploy_adversarial.py` + `staging_cron_deploy.py --selftest`:

no new release · CI failed gates · bad checksum/manifest · wrong identity · concurrent cron · interrupted download · backup fail · partial deploy / secret preservation · health fail+rollback · stale release reject

## Relation to SSH auto-deploy

`19-STAGING-AUTO-DEPLOY-EXCEL2.md` remains valid for optional SSH push-apply. Cron pull is the **primary** path when the owner has no SSH.
