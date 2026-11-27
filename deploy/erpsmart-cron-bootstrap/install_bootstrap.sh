#!/bin/bash
# One-time bootstrap installer for staging cron pull.
# Run from an extracted bootstrap tree OR with REPO_ROOT pointing at a git checkout.
# Does NOT mutate the web docroot except optional identity-marker reminder.
set -euo pipefail

BOOTSTRAP="${ERPSMART_DEPLOY_BOOTSTRAP:-/home3/zzflgmfd/erpsmart-deploy}"
REPO_ROOT="${REPO_ROOT:-}"

if [[ -z "$REPO_ROOT" ]]; then
  # Prefer sibling copy shipped inside bootstrap zip under ./src
  if [[ -d "$(dirname "$0")/src/scripts" ]]; then
    REPO_ROOT="$(cd "$(dirname "$0")/src" && pwd)"
  else
    echo "Set REPO_ROOT to the excel-dev checkout path" >&2
    exit 2
  fi
fi

mkdir -p "$BOOTSTRAP"/{bin,lib,state,locks,backups,work,logs}

cp -f "$REPO_ROOT/scripts/staging_cron_deploy.py" "$BOOTSTRAP/bin/"
cp -f "$REPO_ROOT/scripts/agentic_controlled_cpanel_sync.py" "$BOOTSTRAP/lib/"
cp -f "$REPO_ROOT/scripts/staging_health_check.py" "$BOOTSTRAP/lib/"
cp -f "$REPO_ROOT/scripts/staging_worker_install.py" "$BOOTSTRAP/lib/"
cp -f "$REPO_ROOT/scripts/staging_migrate_preflight.py" "$BOOTSTRAP/lib/"

# Make imports work: bin script expects scripts/ on sys.path relative to repo;
# rewrite a thin wrapper that puts lib/ on path.
cat > "$BOOTSTRAP/bin/run_cron_deploy.py" <<'PY'
#!/usr/bin/env python3
import sys
from pathlib import Path
boot = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(boot / "lib"))
sys.path.insert(0, str(boot / "bin"))
# staging_cron_deploy imports from scripts next to repo; point ROOT at bootstrap lib parent fake
import staging_cron_deploy as m
# Override ROOT used for selftest defaults only; deploy uses --bootstrap/--config
raise SystemExit(m.main())
PY

if [[ ! -f "$BOOTSTRAP/config.json" ]]; then
  cp "$(dirname "$0")/config.example.json" "$BOOTSTRAP/config.json"
fi

chmod 755 "$BOOTSTRAP/bin/"*.py || true
echo "Bootstrap installed at $BOOTSTRAP"
echo "Next: dry-run, then set enabled=true, then add cPanel cron every 5 min."
echo "Identity marker must exist on /home3/zzflgmfd/excel2.bcsrp/.erpsmart_deploy_identity"
