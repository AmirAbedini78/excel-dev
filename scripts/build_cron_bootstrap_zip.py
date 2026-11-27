#!/usr/bin/env python3
"""Build the one-time private bootstrap ZIP for /home3/zzflgmfd/erpsmart-deploy/."""
from __future__ import annotations

import argparse
import json
import shutil
import time
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

SCRIPTS = [
    "staging_cron_deploy.py",
    "agentic_controlled_cpanel_sync.py",
    "staging_health_check.py",
    "staging_worker_install.py",
    "staging_migrate_preflight.py",
    "staging_package_build.py",
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
    for name in SCRIPTS:
        shutil.copy2(ROOT / "scripts" / name, lib / name)
    # Entry point also in bin for clarity
    shutil.copy2(ROOT / "scripts" / "staging_cron_deploy.py", bin_dir / "staging_cron_deploy.py")

    for d in ("state", "locks", "backups", "work", "logs"):
        (stage / d).mkdir()
        (stage / d / ".gitkeep").write_text("", encoding="utf-8")

    meta = {
        "purpose": "one_time_private_bootstrap_outside_docroot",
        "install_path": "/home3/zzflgmfd/erpsmart-deploy/",
        "built_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "staging_only": "excel2.bcsrp",
        "never_production": "excel.bcsrp",
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
