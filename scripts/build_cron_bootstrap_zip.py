#!/usr/bin/env python3
"""Build the one-time private bootstrap ZIP for /home3/zzflgmfd/erpsmart-deploy/.

Primary host runtime is PHP CLI (cPanel jailshell has no Python3).
Python helpers remain in lib/ for optional offline/operator use only.
"""
from __future__ import annotations

import argparse
import json
import shutil
import time
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

PHP_BIN = [
    "staging_cron_deploy.php",
]

PHP_LIB = [
    "erpsmart_controlled_sync.php",
    "erpsmart_staging_health.php",
]

# Optional Python mirrors (not required on host cron).
PY_LIB = [
    "staging_cron_deploy.py",
    "agentic_controlled_cpanel_sync.py",
    "staging_health_check.py",
    "staging_worker_install.py",
    "staging_migrate_preflight.py",
]


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument(
        "--out",
        type=Path,
        default=ROOT / "storage" / "cache" / "erpsmart-cron-bootstrap.zip",
    )
    args = ap.parse_args()
    stage = args.out.parent / "erpsmart_cron_bootstrap_stage"
    if stage.exists():
        shutil.rmtree(stage)
    stage.mkdir(parents=True)

    boot_src = ROOT / "deploy" / "erpsmart-cron-bootstrap"
    shutil.copy2(boot_src / "README.md", stage / "README.md")
    shutil.copy2(boot_src / "config.example.json", stage / "config.example.json")
    shutil.copy2(boot_src / "install_bootstrap.sh", stage / "install_bootstrap.sh")

    lib = stage / "lib"
    bin_dir = stage / "bin"
    lib.mkdir()
    bin_dir.mkdir()

    for name in PHP_LIB:
        shutil.copy2(ROOT / "scripts" / "lib" / name, lib / name)
    for name in PHP_BIN:
        shutil.copy2(ROOT / "scripts" / name, bin_dir / name)
        # Also place deploy entry next to lib discovery paths
        shutil.copy2(ROOT / "scripts" / name, lib / name)

    for name in PY_LIB:
        src = ROOT / "scripts" / name
        if src.is_file():
            shutil.copy2(src, lib / name)

    for d in ("state", "locks", "backups", "work", "logs"):
        (stage / d).mkdir()
        (stage / d / ".gitkeep").write_text("", encoding="utf-8")

    meta = {
        "purpose": "one_time_private_bootstrap_outside_docroot",
        "install_path": "/home3/zzflgmfd/erpsmart-deploy/",
        "host_runtime": "php_cli",
        "php_min": "8.1",
        "entry": "bin/staging_cron_deploy.php",
        "built_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "staging_only": "excel2.bcsrp",
        "never_production": "excel.bcsrp",
        "python_host": "superseded_on_cpanel_jailshell",
    }
    (stage / "BOOTSTRAP_META.json").write_text(json.dumps(meta, indent=2) + "\n", encoding="utf-8")

    args.out.parent.mkdir(parents=True, exist_ok=True)
    if args.out.exists():
        args.out.unlink()
    with zipfile.ZipFile(args.out, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for p in sorted(stage.rglob("*")):
            if p.is_file():
                zf.write(p, arcname=p.relative_to(stage).as_posix())
    print(json.dumps({"ok": True, "zip": str(args.out.resolve()), "meta": meta}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
