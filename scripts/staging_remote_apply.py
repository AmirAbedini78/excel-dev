#!/usr/bin/env python3
"""Apply staging package to excel2.bcsrp.ir via OpenSSH (no StrictHostKeyChecking=no).

Requires env (names only — values never printed):
  STAGING_SSH_HOST
  STAGING_SSH_USER
  STAGING_SSH_PRIVATE_KEY_PATH   (file path; CI writes from secret)
  STAGING_SSH_KNOWN_HOSTS_PATH  (pinned host key file)
  STAGING_SSH_PORT              (optional, default 22)
  STAGING_REMOTE_DEST           (optional; must match canonical staging web root)

Hard rules:
  - Staging host/path only (excel2.bcsrp + /home3/zzflgmfd/excel2.bcsrp/)
  - Never production domain/path
  - Never delete remote files; never rsync --delete
  - Never overwrite app/config.php or storage user trees
  - Never run migrations or deploy engine/
  - Default mode is dry-run (plan only)
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

from agentic_controlled_cpanel_sync import (  # noqa: E402
    ALLOWED_DEST_MARKERS,
    CANONICAL_DEST_HINT,
    build_plan,
    refuse_non_staging_dest,
)

STAGING_HOST_ALLOW = {"excel2.bcsrp.ir"}
CANONICAL_DEST = CANONICAL_DEST_HINT.rstrip("/")


def env(name: str) -> str | None:
    v = os.environ.get(name)
    if v is None:
        return None
    v = v.strip()
    return v or None


def secret_names_present() -> dict:
    names = [
        "STAGING_SSH_HOST",
        "STAGING_SSH_USER",
        "STAGING_SSH_PRIVATE_KEY_PATH",
        "STAGING_SSH_KNOWN_HOSTS_PATH",
    ]
    return {n: bool(env(n)) for n in names}


def ssh_base() -> list[str]:
    host = env("STAGING_SSH_HOST")
    user = env("STAGING_SSH_USER")
    key = env("STAGING_SSH_PRIVATE_KEY_PATH")
    known = env("STAGING_SSH_KNOWN_HOSTS_PATH")
    port = env("STAGING_SSH_PORT") or "22"
    if not all([host, user, key, known]):
        raise RuntimeError("missing_ssh_env")
    # SSH bounce host may be the cPanel server hostname/IP (pinned via known_hosts).
    # Hard-refuse only obvious production web hostnames as SSH targets.
    if host in {"excel.bcsrp.ir", "www.excel.bcsrp.ir"}:
        raise RuntimeError("refused_production_ssh_host")
    return [
        "ssh",
        "-i",
        key,
        "-p",
        port,
        "-o",
        "IdentitiesOnly=yes",
        "-o",
        "BatchMode=yes",
        "-o",
        f"UserKnownHostsFile={known}",
        "-o",
        "StrictHostKeyChecking=yes",
        f"{user}@{host}",
    ]


def scp_base() -> list[str]:
    key = env("STAGING_SSH_PRIVATE_KEY_PATH")
    known = env("STAGING_SSH_KNOWN_HOSTS_PATH")
    port = env("STAGING_SSH_PORT") or "22"
    return [
        "scp",
        "-i",
        key,
        "-P",
        port,
        "-o",
        "IdentitiesOnly=yes",
        "-o",
        "BatchMode=yes",
        "-o",
        f"UserKnownHostsFile={known}",
        "-o",
        "StrictHostKeyChecking=yes",
    ]


def remote_dest() -> str:
    dest = (env("STAGING_REMOTE_DEST") or CANONICAL_DEST).rstrip("/")
    refuse = refuse_non_staging_dest(dest)
    if refuse:
        raise RuntimeError(refuse)
    return dest


def run_ssh(cmd: str, timeout: int = 120) -> subprocess.CompletedProcess[str]:
    base = ssh_base()
    return subprocess.run(base + [cmd], text=True, capture_output=True, timeout=timeout)


def verify_identity(dest: str) -> dict:
    marker = f"{dest}/.erpsmart_deploy_identity"
    cp = run_ssh(f"test -f {marker} && cat {marker} || echo MISSING")
    body = (cp.stdout or "").strip()
    expected = ALLOWED_DEST_MARKERS[".erpsmart_deploy_identity"]
    ok = body == expected
    return {
        "ok": ok,
        "marker": marker,
        "match": ok,
        # never echo unexpected full bodies that might include secrets; marker is non-secret identity
        "got_len": len(body),
        "expected": expected,
        "ssh_rc": cp.returncode,
    }


def apply_files(package: Path, dest: str, ops_copy: list[str], dry_run: bool) -> dict:
    user = env("STAGING_SSH_USER")
    host = env("STAGING_SSH_HOST")
    written = 0
    planned = len(ops_copy)
    if dry_run:
        return {"mode": "dry_run", "planned_copies": planned, "written": 0}

    # Remote backup of files that will be overwritten (code rollback aid; no DB).
    stamp = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
    parent = dest.rsplit("/", 1)[0] if "/" in dest else "."
    bak = f"{parent}/_erpsmart_staging_code_bak_{stamp}"
    run_ssh(f"mkdir -p {bak}")

    for rel in ops_copy:
        src = package / rel
        if not src.is_file():
            continue
        remote_path = f"{dest}/{rel}"
        remote_dir = str(Path(remote_path).parent).replace("\\", "/")
        # Backup existing if present
        run_ssh(f"if test -f {remote_path}; then mkdir -p {bak}/$(dirname {rel}) && cp -p {remote_path} {bak}/{rel}; fi")
        run_ssh(f"mkdir -p {remote_dir}")
        scp = scp_base() + [str(src), f"{user}@{host}:{remote_path}"]
        cp = subprocess.run(scp, text=True, capture_output=True, timeout=120)
        if cp.returncode != 0:
            return {
                "mode": "apply",
                "ok": False,
                "written": written,
                "failed_rel": rel,
                "scp_rc": cp.returncode,
                "backup_dir": bak,
                # stderr may contain paths; truncate
                "scp_err": (cp.stderr or "")[:300],
            }
        written += 1

    return {"mode": "apply", "ok": True, "written": written, "planned_copies": planned, "backup_dir": bak}


def main() -> int:
    ap = argparse.ArgumentParser(description="Staging remote apply via pinned-SSH (dry-run default)")
    ap.add_argument("--package", type=Path, default=None)
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--i-understand-staging-risk", action="store_true")
    ap.add_argument("--manifest-out", type=Path, default=None)
    ap.add_argument("--check-secrets-only", action="store_true")
    args = ap.parse_args()

    present = secret_names_present()
    if args.check_secrets_only:
        ready = all(present.values())
        report = {
            "ssh_secrets_configured": ready,
            "present": present,
            "status": "STAGING_SSH_READY" if ready else "STAGING_SSH_MISSING",
            "note": "Secret values are never printed",
        }
        print(json.dumps(report, indent=2))
        return 0 if ready else 2

    if not all(present.values()):
        report = {
            "ok": False,
            "status": "STAGING_AUTO_DEPLOY_PARTIAL",
            "reason": "ssh_secrets_missing",
            "present": present,
            "migrations": "NOT_RUN",
            "worker_engine": "OUT_OF_SCOPE",
        }
        print(json.dumps(report, indent=2))
        if args.manifest_out:
            args.manifest_out.parent.mkdir(parents=True, exist_ok=True)
            args.manifest_out.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
        return 2

    if args.package is None or not args.package.is_dir():
        print(json.dumps({"ok": False, "error": "package_dir_required"}, indent=2))
        return 2

    dest = remote_dest()
    identity = verify_identity(dest)
    # Local plan against package with a fake dest tree for op classification
    with tempfile.TemporaryDirectory() as td:
        fake = Path(td)
        (fake / ".erpsmart_deploy_identity").write_text(
            ALLOWED_DEST_MARKERS[".erpsmart_deploy_identity"] + "\n", encoding="utf-8"
        )
        (fake / "app").mkdir()
        (fake / "app" / "config.php").write_text("RUNTIME\n", encoding="utf-8")
        ops = build_plan(args.package, fake)
    copy_rels = [o.rel for o in ops if o.op in {"copy", "scaffold_only"}]

    report: dict = {
        "target_dest": dest,
        "canonical_dest": CANONICAL_DEST,
        "identity": identity,
        "planned_copies": len(copy_rels),
        "migrations": "NOT_RUN",
        "worker_engine": "OUT_OF_SCOPE",
        "delete_policy": "never_delete",
        "strict_host_key": True,
    }

    if args.apply:
        if not args.i_understand_staging_risk:
            report.update({"ok": False, "error": "apply_requires_ack_flag"})
            print(json.dumps(report, indent=2))
            return 3
        if not identity.get("ok"):
            report.update({"ok": False, "error": "identity_marker_required_before_apply"})
            print(json.dumps(report, indent=2))
            return 3
        applied = apply_files(args.package, dest, copy_rels, dry_run=False)
        report["apply"] = applied
        report["ok"] = bool(applied.get("ok"))
        report["status"] = "STAGING_CODE_DEPLOYED" if report["ok"] else "STAGING_APPLY_FAILED"
    else:
        report["ok"] = bool(identity.get("ok"))
        report["status"] = "STAGING_DRY_RUN"
        report["note"] = "dry_run_no_mutation"

    print(json.dumps(report, indent=2))
    if args.manifest_out:
        args.manifest_out.parent.mkdir(parents=True, exist_ok=True)
        args.manifest_out.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    return 0 if report.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
