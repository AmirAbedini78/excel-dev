#!/usr/bin/env python3
"""Build an exact-commit staging package for excel2.bcsrp.ir only.

Reuses allowlists from agentic_controlled_cpanel_sync (no engine/, no config secrets).
Default is offline package materialization + dry-run plan — never mutates a host.
"""
from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

from agentic_controlled_cpanel_sync import (  # noqa: E402
    ALLOWED_DEST_MARKERS,
    CANONICAL_DEST_HINT,
    DIR_ALLOWLIST,
    PRESERVE_EXACT,
    ROOT_FILE_ALLOWLIST,
    ROOT_FILE_DENYLIST,
    STORAGE_SCAFFOLD_ALLOWLIST,
    build_plan,
    sha256_file,
)

STAGING_HOST = "excel2.bcsrp.ir"
STAGING_URL = f"https://{STAGING_HOST}/"
FORBIDDEN_HOST_HINTS = (
    "excel.bcsrp.ir",  # production domain — never package-target
    "www.excel.bcsrp.ir",
)


def git_sha(cwd: Path) -> str:
    try:
        out = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=cwd, text=True)
        return out.strip()
    except (subprocess.CalledProcessError, FileNotFoundError):
        return "UNKNOWN"


def materialize_package(src: Path, out_dir: Path) -> list[str]:
    if out_dir.exists():
        shutil.rmtree(out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    copied: list[str] = []

    for rel in ROOT_FILE_ALLOWLIST:
        if rel in ROOT_FILE_DENYLIST:
            continue
        src_f = src / rel
        if not src_f.is_file():
            continue
        dst = out_dir / rel
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(src_f, dst)
        copied.append(rel)

    for d in DIR_ALLOWLIST:
        base = src / d
        if not base.is_dir():
            continue
        for p in sorted(base.rglob("*")):
            if not p.is_file():
                continue
            rel = p.relative_to(src).as_posix()
            if rel.replace("\\", "/") in PRESERVE_EXACT:
                continue
            low = rel.lower()
            if any(x in low for x in (".env", "config.php", ".pem", ".key")) and "sample" not in low:
                if Path(rel).name == "config.php" and rel.startswith("app/"):
                    continue
            dst = out_dir / rel
            dst.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(p, dst)
            copied.append(rel)

    for rel in STORAGE_SCAFFOLD_ALLOWLIST:
        src_f = src / rel
        if not src_f.is_file():
            continue
        dst = out_dir / rel
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(src_f, dst)
        copied.append(rel)

    # Identity marker content for operators (not auto-applied over existing host marker).
    marker = out_dir / ".erpsmart_deploy_identity.example"
    marker.write_text(ALLOWED_DEST_MARKERS[".erpsmart_deploy_identity"] + "\n", encoding="utf-8")
    copied.append(marker.name)
    return copied


def write_release_meta(out_dir: Path, sha: str, files: list[str]) -> Path:
    meta = {
        "target_host": STAGING_HOST,
        "target_url": STAGING_URL,
        "canonical_dest": CANONICAL_DEST_HINT,
        "identity_marker_expected": ALLOWED_DEST_MARKERS[".erpsmart_deploy_identity"],
        "git_sha": sha,
        "built_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "migrations": "NOT_INCLUDED_NOT_AUTO",
        "worker_engine": "OUT_OF_SCOPE",
        "preserve_exact": sorted(PRESERVE_EXACT),
        "file_count": len(files),
        "files_sha256": {rel: sha256_file(out_dir / rel) for rel in files if (out_dir / rel).is_file()},
        "forbidden_hosts": list(FORBIDDEN_HOST_HINTS),
        "rollback": "redeploy_prior_code_package_only_no_auto_db_rollback",
    }
    path = out_dir / "ERPSMART_STAGING_RELEASE.json"
    path.write_text(json.dumps(meta, indent=2) + "\n", encoding="utf-8")
    return path


def main() -> int:
    ap = argparse.ArgumentParser(description="Build staging-only allowlisted package (offline)")
    ap.add_argument("--src", type=Path, default=ROOT)
    ap.add_argument("--out", type=Path, default=ROOT / "storage" / "cache" / "staging_pkg")
    ap.add_argument("--dry-run-plan", action="store_true", help="Also emit controlled-sync dry-run plan vs empty fixture dest")
    ap.add_argument("--manifest-out", type=Path, default=None)
    args = ap.parse_args()

    sha = git_sha(args.src)
    files = materialize_package(args.src, args.out)
    meta_path = write_release_meta(args.out, sha, files)

    report: dict = {
        "ok": True,
        "git_sha": sha,
        "package_dir": str(args.out.resolve()),
        "release_meta": str(meta_path.resolve()),
        "file_count": len(files),
        "target": STAGING_URL,
        "engine_included": False,
        "config_php_included": (args.out / "app" / "config.php").is_file(),
    }
    # Safety: package must never ship runtime config.php
    if report["config_php_included"]:
        report["ok"] = False
        report["error"] = "config_php_must_not_be_packaged"

    if args.dry_run_plan:
        fixture = args.out.parent / "staging_pkg_dryrun_dest"
        if fixture.exists():
            shutil.rmtree(fixture)
        fixture.mkdir(parents=True)
        (fixture / ".erpsmart_deploy_identity").write_text(
            ALLOWED_DEST_MARKERS[".erpsmart_deploy_identity"] + "\n", encoding="utf-8"
        )
        (fixture / "app").mkdir(parents=True)
        (fixture / "app" / "config.php").write_text("RUNTIME_SECRET\n", encoding="utf-8")
        ops = build_plan(args.out, fixture)
        report["dry_run"] = {
            "copy": sum(1 for o in ops if o.op == "copy"),
            "preserve": sum(1 for o in ops if o.op == "skip_preserve"),
            "scaffold": sum(1 for o in ops if o.op == "scaffold_only"),
        }

    out = args.manifest_out or (args.out / "build_manifest.json")
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"ok": report["ok"], "manifest": str(out), "sha": sha, "files": len(files)}, indent=2))
    return 0 if report["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
