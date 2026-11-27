#!/usr/bin/env python3
"""Pre-migrate gates for staging DB — never auto-migrate; never touch prod DB.

Before any future auto-migrate is considered, this script must prove:
  1) Staging DB identity (name allowlist / refuse production names)
  2) Valid restorable backup artifact (file exists + sha256 recorded)
  3) Explicit operator acknowledgement string

Additive idempotent migrations may be prepared; execution remains fail-closed
until these gates pass. This script does NOT run SQL migrations.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

PROD_NAME_HINTS = (
    "prod",
    "production",
    "excel_bcsrp",
    "excel.bcsrp",
    "live_erp",
)

ACK = "MIGRATE_STAGING_EXCEL2"


def refuse_db_name(name: str) -> str | None:
    n = (name or "").strip().lower()
    if not n:
        return "db_name_empty"
    if len(n) < 3:
        return "db_name_too_short"
    for hint in PROD_NAME_HINTS:
        if hint in n:
            return f"refused_production_like_db:{hint}"
    if not re.fullmatch(r"[a-zA-Z0-9_]+", name.strip()):
        return "db_name_charset"
    return None


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def verify_backup(path: Path, min_bytes: int = 64) -> dict:
    info = {"path": str(path), "ok": False}
    if not path.is_file():
        info["error"] = "backup_missing"
        return info
    size = path.stat().st_size
    info["size"] = size
    if size < min_bytes:
        info["error"] = "backup_too_small"
        return info
    info["sha256"] = sha256_file(path)
    info["ok"] = True
    return info


def main() -> int:
    ap = argparse.ArgumentParser(description="Staging migrate preflight (no SQL execution)")
    ap.add_argument("--db-name", default=None, help="Staging DB name from secure config (value echoed only as length)")
    ap.add_argument("--backup", type=Path, default=None, help="Restorable mysqldump / sql.gz path")
    ap.add_argument("--ack", default=None, help=f"Must equal {ACK}")
    ap.add_argument("--manifest-out", type=Path, default=None)
    ap.add_argument("--selftest", action="store_true")
    args = ap.parse_args()

    if args.selftest:
        checks = {
            "prod_refused": refuse_db_name("excel_bcsrp_prod") is not None,
            "staging_ok": refuse_db_name("erpsmart_excel2_staging") is None,
            "ack_constant": ACK == "MIGRATE_STAGING_EXCEL2",
        }
        print(json.dumps({"ok": all(checks.values()), "checks": checks}, indent=2))
        return 0 if all(checks.values()) else 1

    if not args.db_name or not args.backup or not args.ack:
        print(json.dumps({"ok": False, "error": "db_name_backup_ack_required"}, indent=2))
        return 2

    refuse = refuse_db_name(args.db_name)
    backup = verify_backup(args.backup)
    ack_ok = (args.ack or "").strip() == ACK
    report = {
        "ok": refuse is None and backup.get("ok") and ack_ok,
        "db_name_len": len(args.db_name.strip()),
        "db_refuse": refuse,
        "backup": {k: v for k, v in backup.items() if k != "path"} | {"path_basename": args.backup.name},
        "ack_ok": ack_ok,
        "migrations": "NOT_RUN",
        "auto_migrate": "DISABLED_UNTIL_GATES",
        "production_db": "REFUSED",
        "status": "STAGING_MIGRATE_PREFLIGHT_OK" if (refuse is None and backup.get("ok") and ack_ok) else "STAGING_MIGRATE_PREFLIGHT_BLOCKED",
        "note": "Prepare additive IF NOT EXISTS migrations; do not execute until operator CLI with these gates.",
    }
    print(json.dumps(report, indent=2))
    if args.manifest_out:
        args.manifest_out.parent.mkdir(parents=True, exist_ok=True)
        args.manifest_out.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    return 0 if report["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
