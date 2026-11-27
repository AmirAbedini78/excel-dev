#!/usr/bin/env python3
"""Build an exact-commit staging package for excel2.bcsrp.ir only.

Reuses allowlists from agentic_controlled_cpanel_sync (no engine/, no config secrets).
Default is offline package materialization + dry-run plan — never mutates a host.

Also emits a downloadable ZIP + SHA256 sidecar + public release metadata suitable
for GitHub Release Assets (cron pull on shared hosting — no PAT required for public repo).
"""
from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import subprocess
import sys
import time
import zipfile
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
PHP_COMPAT = {"min": "8.2", "tested": "8.2"}
WORKER_COMPAT = {
    "python_min": "3.10",
    "mode": "cron_once",
    "daemon_required": False,
    "matched_to_php_sha": True,
}
# Never pack these even if somehow present under allowlisted trees.
PACKAGE_SECRET_DENY = (
    ".env",
    "config.php",
    ".pem",
    ".key",
    ".pfx",
    ".sql",
    "credentials",
    "secret",
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


def write_release_meta(out_dir: Path, sha: str, files: list[str], *, zip_name: str | None = None, zip_sha256: str | None = None, gates: dict | None = None) -> Path:
    meta = {
        "schema": "erpsmart.staging_release.v1",
        "target_host": STAGING_HOST,
        "target_url": STAGING_URL,
        "canonical_dest": CANONICAL_DEST_HINT,
        "identity_marker_expected": ALLOWED_DEST_MARKERS[".erpsmart_deploy_identity"],
        "git_sha": sha,
        "branch_required": "main",
        "built_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "migrations": "NOT_INCLUDED_NOT_AUTO",
        "worker_engine": "OUT_OF_SCOPE_SEPARATE_VERSION_MATCHED",
        "php_compat": PHP_COMPAT,
        "worker_compat": WORKER_COMPAT,
        "preserve_exact": sorted(PRESERVE_EXACT),
        "file_count": len(files),
        "file_manifest": sorted(files),
        "files_sha256": {rel: sha256_file(out_dir / rel) for rel in files if (out_dir / rel).is_file()},
        "forbidden_hosts": list(FORBIDDEN_HOST_HINTS),
        "rollback": "redeploy_prior_code_package_only_no_auto_db_rollback",
        "gates": gates
        or {
            "release-gate": "pending_ci",
            "rc1-mysql-php": "pending_ci",
            "note": "Release publish job stamps success only after await-ci",
        },
        "package_zip": zip_name,
        "package_zip_sha256": zip_sha256,
        "secrets_policy": "no_env_no_config_php_no_db_dumps_no_uploads_no_logs",
    }
    path = out_dir / "ERPSMART_STAGING_RELEASE.json"
    path.write_text(json.dumps(meta, indent=2) + "\n", encoding="utf-8")
    return path


def assert_no_secrets_in_tree(out_dir: Path) -> list[str]:
    bad: list[str] = []
    for p in out_dir.rglob("*"):
        if not p.is_file():
            continue
        rel = p.relative_to(out_dir).as_posix().lower()
        name = p.name.lower()
        if name == "config.php" and rel.startswith("app/"):
            bad.append(rel)
            continue
        if name.startswith(".env") and not name.endswith(".example"):
            bad.append(rel)
            continue
        for hint in PACKAGE_SECRET_DENY:
            if hint in rel and "sample" not in rel and not rel.endswith(".htaccess"):
                if hint in {".sql", ".pem", ".key", ".pfx"} or hint in {"credentials", "secret"}:
                    bad.append(rel)
                    break
    return sorted(set(bad))


def build_zip(pkg_dir: Path, zip_path: Path) -> str:
    if zip_path.exists():
        zip_path.unlink()
    zip_path.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for p in sorted(pkg_dir.rglob("*")):
            if not p.is_file():
                continue
            # Do not nest the zip inside itself if rebuild is in same tree.
            if p.resolve() == zip_path.resolve():
                continue
            if p.name.endswith(".zip") or p.name.endswith(".sha256"):
                continue
            if p.name == "build_manifest.json":
                continue
            rel = p.relative_to(pkg_dir).as_posix()
            # ZIP members must be relative with forward slashes; no absolute / drive paths.
            if rel.startswith("/") or ".." in Path(rel).parts or (len(rel) > 1 and rel[1] == ":"):
                raise RuntimeError(f"unsafe_zip_member:{rel}")
            zf.write(p, arcname=rel)
    digest = sha256_file(zip_path)
    (zip_path.parent / (zip_path.name + ".sha256")).write_text(
        f"{digest}  {zip_path.name}\n", encoding="utf-8"
    )
    return digest


def stamp_gates_success(meta_path: Path, sha: str) -> dict:
    """CI calls this after release-gate + rc1-mysql-php succeed on exact SHA."""
    data = json.loads(meta_path.read_text(encoding="utf-8"))
    if data.get("git_sha") != sha:
        raise RuntimeError(f"gate_stamp_sha_mismatch:{data.get('git_sha')}!={sha}")
    data["gates"] = {
        "release-gate": "success",
        "rc1-mysql-php": "success",
        "verified_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "verified_on_sha": sha,
    }
    meta_path.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")
    return data


def main() -> int:
    ap = argparse.ArgumentParser(description="Build staging-only allowlisted package (offline)")
    ap.add_argument("--src", type=Path, default=ROOT)
    ap.add_argument("--out", type=Path, default=ROOT / "storage" / "cache" / "staging_pkg")
    ap.add_argument("--dry-run-plan", action="store_true", help="Also emit controlled-sync dry-run plan vs empty fixture dest")
    ap.add_argument("--manifest-out", type=Path, default=None)
    ap.add_argument("--zip", action="store_true", help="Also emit downloadable ZIP + .sha256 sidecar")
    ap.add_argument("--stamp-gates-success", action="store_true", help="Stamp release meta gates after CI await")
    ap.add_argument("--expected-sha", default=None, help="Required with --stamp-gates-success")
    args = ap.parse_args()

    if args.stamp_gates_success:
        sha = args.expected_sha or git_sha(args.src)
        meta = args.out / "ERPSMART_STAGING_RELEASE.json"
        if not meta.is_file():
            print(json.dumps({"ok": False, "error": "release_meta_missing"}), indent=2)
            return 2
        stamped = stamp_gates_success(meta, sha)
        print(json.dumps({"ok": True, "gates": stamped["gates"], "sha": sha}, indent=2))
        return 0

    sha = git_sha(args.src)
    files = materialize_package(args.src, args.out)
    # Release meta first (embedded in ZIP); zip fields filled after archive if requested.
    meta_path = write_release_meta(args.out, sha, files)
    # Re-include release meta in file list for checksum map consistency.
    if "ERPSMART_STAGING_RELEASE.json" not in files:
        files.append("ERPSMART_STAGING_RELEASE.json")

    report: dict = {
        "ok": True,
        "git_sha": sha,
        "package_dir": str(args.out.resolve()),
        "release_meta": str(meta_path.resolve()),
        "file_count": len(files),
        "target": STAGING_URL,
        "engine_included": False,
        "config_php_included": (args.out / "app" / "config.php").is_file(),
        "php_compat": PHP_COMPAT,
        "worker_compat": WORKER_COMPAT,
    }
    # Safety: package must never ship runtime config.php
    if report["config_php_included"]:
        report["ok"] = False
        report["error"] = "config_php_must_not_be_packaged"

    secret_hits = assert_no_secrets_in_tree(args.out)
    if secret_hits:
        report["ok"] = False
        report["error"] = "secrets_detected_in_package"
        report["secret_hits"] = secret_hits[:50]

    zip_path = None
    zip_sha = None
    if args.zip and report["ok"]:
        short = sha[:12] if sha != "UNKNOWN" else "unknown"
        zip_path = args.out / f"erpsmart-staging-php-{short}.zip"
        zip_sha = build_zip(args.out, zip_path)
        write_release_meta(
            args.out,
            sha,
            [f for f in files if not f.endswith(".zip")],
            zip_name=zip_path.name,
            zip_sha256=zip_sha,
        )
        # Refresh ZIP so meta inside archive includes zip_sha256 (meta written before zip).
        # Second pass: rebuild zip with stamped meta; sidecar matches final bytes.
        zip_sha = build_zip(args.out, zip_path)
        write_release_meta(
            args.out,
            sha,
            [f for f in files if not f.endswith(".zip")],
            zip_name=zip_path.name,
            zip_sha256=zip_sha,
        )
        # Final zip includes correct meta with matching sha256 of THIS zip content —
        # chicken/egg: sha of zip includes meta which includes sha. Use external sidecar
        # as authoritative; meta package_zip_sha256 records the bytes AFTER last meta write
        # would diverge. Convention: sidecar .sha256 is authoritative; meta records
        # intended name + sidecar digest of the published asset (rebuild once more).
        zip_sha = build_zip(args.out, zip_path)
        # After last build_zip, meta inside zip is one generation behind on package_zip_sha256.
        # Rewrite sidecar-only public meta next to zip for release assets.
        public_meta = {
            "schema": "erpsmart.staging_release.v1",
            "git_sha": sha,
            "branch_required": "main",
            "target_host": STAGING_HOST,
            "target_url": STAGING_URL,
            "canonical_dest": CANONICAL_DEST_HINT,
            "identity_marker_expected": ALLOWED_DEST_MARKERS[".erpsmart_deploy_identity"],
            "package_zip": zip_path.name,
            "package_zip_sha256": zip_sha,
            "file_count": len(files),
            "file_manifest": sorted(f for f in files if not f.endswith(".zip")),
            "php_compat": PHP_COMPAT,
            "worker_compat": WORKER_COMPAT,
            "migrations": "NOT_INCLUDED_NOT_AUTO",
            "gates": {
                "release-gate": "pending_ci",
                "rc1-mysql-php": "pending_ci",
            },
            "built_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        }
        public_meta_path = args.out / "ERPSMART_STAGING_RELEASE_PUBLIC.json"
        public_meta_path.write_text(json.dumps(public_meta, indent=2) + "\n", encoding="utf-8")
        report["zip"] = str(zip_path)
        report["zip_sha256"] = zip_sha
        report["public_meta"] = str(public_meta_path)

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
    print(
        json.dumps(
            {
                "ok": report["ok"],
                "manifest": str(out),
                "sha": sha,
                "files": len(files),
                "zip": report.get("zip"),
                "zip_sha256": report.get("zip_sha256"),
            },
            indent=2,
        )
    )
    return 0 if report["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
