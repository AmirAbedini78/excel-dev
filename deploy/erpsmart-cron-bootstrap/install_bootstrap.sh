#!/bin/bash
# One-time bootstrap installer for staging cron pull (PHP CLI host runtime).
# Run from an extracted bootstrap tree OR with REPO_ROOT pointing at a git checkout.
# Does NOT mutate the web docroot except optional identity-marker reminder.
set -euo pipefail

BOOTSTRAP="${ERPSMART_DEPLOY_BOOTSTRAP:-/home3/zzflgmfd/erpsmart-deploy}"
REPO_ROOT="${REPO_ROOT:-}"
PHP_BIN="${ERPSMART_PHP_BIN:-/usr/bin/php}"

if [[ -z "$REPO_ROOT" ]]; then
  if [[ -d "$(dirname "$0")/src/scripts" ]]; then
    REPO_ROOT="$(cd "$(dirname "$0")/src" && pwd)"
  elif [[ -f "$(dirname "$0")/bin/staging_cron_deploy.php" ]]; then
    # Already extracted bootstrap — just ensure dirs + config
    REPO_ROOT=""
  else
    echo "Set REPO_ROOT to the excel-dev checkout path, or extract the bootstrap ZIP first" >&2
    exit 2
  fi
fi

mkdir -p "$BOOTSTRAP"/{bin,lib,state,locks,backups,work,logs}

if [[ -n "$REPO_ROOT" ]]; then
  cp -f "$REPO_ROOT/scripts/staging_cron_deploy.php" "$BOOTSTRAP/bin/"
  cp -f "$REPO_ROOT/scripts/lib/erpsmart_controlled_sync.php" "$BOOTSTRAP/lib/"
  cp -f "$REPO_ROOT/scripts/lib/erpsmart_staging_health.php" "$BOOTSTRAP/lib/"
  # Optional Python mirrors (not used by cPanel cron)
  for f in staging_cron_deploy.py agentic_controlled_cpanel_sync.py staging_health_check.py \
           staging_worker_install.py staging_migrate_preflight.py; do
    if [[ -f "$REPO_ROOT/scripts/$f" ]]; then
      cp -f "$REPO_ROOT/scripts/$f" "$BOOTSTRAP/lib/" || true
    fi
  done
elif [[ -f "$(dirname "$0")/bin/staging_cron_deploy.php" ]]; then
  SRC="$(cd "$(dirname "$0")" && pwd)"
  cp -f "$SRC/bin/staging_cron_deploy.php" "$BOOTSTRAP/bin/"
  cp -f "$SRC/lib/"*.php "$BOOTSTRAP/lib/" 2>/dev/null || true
fi

if [[ ! -f "$BOOTSTRAP/config.json" ]]; then
  EXAMPLE="$(dirname "$0")/config.example.json"
  if [[ -f "$EXAMPLE" ]]; then
    cp "$EXAMPLE" "$BOOTSTRAP/config.json"
  fi
fi

chmod 755 "$BOOTSTRAP/bin/staging_cron_deploy.php" || true

echo "Bootstrap installed at $BOOTSTRAP (PHP host runtime)"
echo "PHP binary: $PHP_BIN (override with ERPSMART_PHP_BIN)"
echo "Dry-run:"
echo "  $PHP_BIN $BOOTSTRAP/bin/staging_cron_deploy.php --bootstrap $BOOTSTRAP --config $BOOTSTRAP/config.json --dry-run"
echo "Cron:"
echo "  */5 * * * * cd $BOOTSTRAP && $PHP_BIN $BOOTSTRAP/bin/staging_cron_deploy.php --bootstrap $BOOTSTRAP --config $BOOTSTRAP/config.json --apply --i-understand-staging-risk >> $BOOTSTRAP/logs/cron_deploy.log 2>&1"
echo "Identity marker must exist on /home3/zzflgmfd/excel2.bcsrp/.erpsmart_deploy_identity"
