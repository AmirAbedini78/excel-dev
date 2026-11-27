#!/usr/bin/env python3
"""Cron-compatible Worker (engine/) install/update helper for staging — no forced daemon.

Scope:
  - Staging host only (excel2 / operator-provided staging worker dir)
  - Never touches production paths
  - Never copies engine/.env* or config.json with secrets from git
  - Idempotent: sync code tree, preserve host config/data, print cron line
  - Does NOT start a long-running daemon (shared hosting may only allow cron)

Provider rebuild (Gemini / Grok) is documented; keys stay in private env files only.
"""
from __future__ import annotations

import argparse
import json
import os
import shutil
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

FORBIDDEN_DEST_FRAGMENTS = (
    "/excel.bcsrp/",
    "/home3/zzflgmfd/excel.bcsrp",
)

PRESERVE_NAMES = {
    "config.json",
    "config.cloud.json",
    "config.gemini.json",
    ".env",
    ".env.local",
    ".env.cursor",
    ".env.gemini",
    ".env.xai",
    ".env.grok",
}

SKIP_DIR_NAMES = {".git", "__pycache__", ".venv", "node_modules", "data"}


def refuse_dest(dest: Path) -> str | None:
    s = str(dest.resolve()).replace("\\", "/")
    for frag in FORBIDDEN_DEST_FRAGMENTS:
        if frag.rstrip("/") in s and "excel2" not in s:
            return "refused_production_worker_dest"
    return None


def sync_tree(src: Path, dest: Path, dry_run: bool) -> dict:
    copied = 0
    skipped_preserve = 0
    for path in sorted(src.rglob("*")):
        if not path.is_file():
            continue
        rel = path.relative_to(src).as_posix()
        if any(part in SKIP_DIR_NAMES for part in Path(rel).parts):
            continue
        name = Path(rel).name
        if name in PRESERVE_NAMES or name.startswith(".env"):
            # Never overwrite host secrets from package/repo.
            dest_f = dest / rel
            if dest_f.is_file():
                skipped_preserve += 1
                continue
            # Only seed *.example → never live env.
            if name.startswith(".env") and not name.endswith(".example"):
                skipped_preserve += 1
                continue
        dest_f = dest / rel
        if dry_run:
            copied += 1
            continue
        dest_f.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, dest_f)
        copied += 1
    return {"copied": copied, "skipped_preserve": skipped_preserve, "dry_run": dry_run}


def cron_line(dest: Path, php_callback: str | None) -> str:
    worker = dest / "worker.py"
    # Heartbeat every 5 minutes — operator installs via cPanel Cron UI.
    cb = php_callback or "https://excel2.bcsrp.ir/ai_live.php"
    return (
        f"*/5 * * * * cd {dest} && /usr/bin/python3 {worker} --once "
        f"--callback {cb} >> {dest / 'data' / 'cron_worker.log'} 2>&1"
    )


def provider_rebuild_commands() -> dict:
    return {
        "gemini": [
            "# keys only in private engine/.env.gemini (never commit)",
            "cd engine",
            "cp -n config.gemini.example.json config.gemini.json  # if missing",
            "python gemini_check.py",
            "python list_provider_models.py --provider gemini",
        ],
        "grok_xai": [
            "# keys only in private engine/.env.xai (never commit)",
            "cd engine",
            "python -c \"import provider_gateway as g; print('gateway_ok')\"",
            "python list_provider_models.py --provider xai",
        ],
        "notes": [
            "Never place API keys in git, chat, or staging package ZIPs.",
            "Worker remains cron-compatible; do not require systemd/supervisor on shared hosting.",
            "PHP web package and Worker package are versioned at the same git SHA.",
        ],
    }


def main() -> int:
    ap = argparse.ArgumentParser(description="Staging Worker install/update (cron-compatible)")
    ap.add_argument("--src", type=Path, default=ROOT / "engine")
    ap.add_argument("--dest", type=Path, required=False, help="Host worker directory (staging only)")
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--i-understand-staging-risk", action="store_true")
    ap.add_argument("--callback-url", default="https://excel2.bcsrp.ir/ai_live.php")
    ap.add_argument("--manifest-out", type=Path, default=None)
    ap.add_argument("--print-provider-rebuild", action="store_true")
    args = ap.parse_args()

    if args.print_provider_rebuild:
        print(json.dumps(provider_rebuild_commands(), indent=2, ensure_ascii=False))
        return 0

    if args.dest is None:
        print(json.dumps({"ok": False, "error": "dest_required"}, indent=2))
        return 2

    refuse = refuse_dest(args.dest)
    if refuse:
        print(json.dumps({"ok": False, "error": refuse}, indent=2))
        return 3

    if args.apply and not args.i_understand_staging_risk:
        print(json.dumps({"ok": False, "error": "apply_requires_ack"}, indent=2))
        return 3

    if not args.src.is_dir():
        print(json.dumps({"ok": False, "error": "engine_src_missing"}, indent=2))
        return 2

    dry = not args.apply
    if not dry:
        args.dest.mkdir(parents=True, exist_ok=True)
        (args.dest / "data").mkdir(parents=True, exist_ok=True)

    sync = sync_tree(args.src, args.dest, dry_run=dry)
    report = {
        "ok": True,
        "mode": "apply" if args.apply else "dry_run",
        "src": str(args.src.resolve()),
        "dest": str(args.dest.resolve()),
        "sync": sync,
        "cron_suggested": cron_line(args.dest, args.callback_url),
        "daemon": "NOT_REQUIRED_USE_CRON",
        "provider_rebuild": provider_rebuild_commands(),
        "built_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "status": "STAGING_WORKER_PLAN" if dry else "STAGING_WORKER_SYNCED",
    }
    print(json.dumps(report, indent=2, ensure_ascii=False))
    if args.manifest_out:
        args.manifest_out.parent.mkdir(parents=True, exist_ok=True)
        args.manifest_out.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    return 0


if __name__ == "__main__":
    # Keep unused os import intentional for env inspection extensions.
    _ = os.environ
    raise SystemExit(main())
