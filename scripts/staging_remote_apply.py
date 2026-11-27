#!/usr/bin/env python3
"""Apply staging package to excel2.bcsrp.ir via OpenSSH (no StrictHostKeyChecking=no).

Requires env (names only — values never printed):
  STAGING_SSH_HOST
  STAGING_SSH_USER
  STAGING_SSH_PRIVATE_KEY_PATH   (file path; CI writes from secret)
  STAGING_SSH_KNOWN_HOSTS_PATH  (pinned host key file)
  STAGING_SSH_PORT              (optional, default 22)
  STAGING_REMOTE_DEST           (optional; must match canonical staging web root)
  STAGING_GIT_REF               (optional; real apply requires refs/heads/main)
  STAGING_AWAIT_CI_RESULT       (optional; real apply requires success)

Hard rules:
  - Staging host/path only (excel2.bcsrp + /home3/zzflgmfd/excel2.bcsrp/)
  - Never production domain/path
  - Never delete remote files; never rsync --delete
  - Never overwrite app/config.php or storage user trees
  - Never run migrations or deploy engine/
  - Default mode is dry-run (plan only)
  - Real apply only on main + CI success
  - Backup must succeed before any destination mutation
  - Health / SHA / checksum failure → restore previous code
"""
from __future__ import annotations

import argparse
import json
import os
import shlex
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
    PRESERVE_EXACT,
    STORAGE_SCAFFOLD_ALLOWLIST,
    build_plan,
    refuse_non_staging_dest,
    sha256_file,
)
from staging_apply_decision import allow_real_apply  # noqa: E402

STAGING_HOST_ALLOW = {"excel2.bcsrp.ir"}
CANONICAL_DEST = CANONICAL_DEST_HINT.rstrip("/")
RELEASE_META_NAME = "ERPSMART_STAGING_RELEASE.json"
LOCK_NAME = ".erpsmart_staging_deploy.lock"
MAINT_NAME = "storage/maintenance/.erpsmart_deploy_maintenance"


class RemoteError(RuntimeError):
    def __init__(self, code: str, detail: str = ""):
        super().__init__(code)
        self.code = code
        self.detail = detail


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


def run_ssh_checked(cmd: str, timeout: int = 120, label: str = "ssh") -> str:
    cp = run_ssh(cmd, timeout=timeout)
    if cp.returncode != 0:
        raise RemoteError(
            f"{label}_failed",
            detail=(cp.stderr or cp.stdout or "")[:300],
        )
    return cp.stdout or ""


def run_scp(local: Path, remote_path: str, timeout: int = 120) -> None:
    user = env("STAGING_SSH_USER")
    host = env("STAGING_SSH_HOST")
    cp = subprocess.run(
        scp_base() + [str(local), f"{user}@{host}:{remote_path}"],
        text=True,
        capture_output=True,
        timeout=timeout,
    )
    if cp.returncode != 0:
        raise RemoteError("scp_failed", detail=(cp.stderr or "")[:300])


def verify_identity(dest: str) -> dict:
    marker = f"{dest}/.erpsmart_deploy_identity"
    cp = run_ssh(f"test -f {shlex.quote(marker)} && cat {shlex.quote(marker)} || echo MISSING")
    if cp.returncode not in (0, 1):
        return {
            "ok": False,
            "marker": marker,
            "match": False,
            "got_len": 0,
            "expected": ALLOWED_DEST_MARKERS[".erpsmart_deploy_identity"],
            "ssh_rc": cp.returncode,
            "error": "identity_ssh_failed",
        }
    body = (cp.stdout or "").strip()
    expected = ALLOWED_DEST_MARKERS[".erpsmart_deploy_identity"]
    ok = body == expected
    return {
        "ok": ok,
        "marker": marker,
        "match": ok,
        "got_len": len(body),
        "expected": expected,
        "ssh_rc": cp.returncode,
    }


def remote_file_exists(dest: str, rel: str) -> bool:
    path = f"{dest}/{rel}"
    cp = run_ssh(f"test -f {shlex.quote(path)}")
    if cp.returncode == 0:
        return True
    if cp.returncode == 1:
        return False
    raise RemoteError("remote_stat_failed", detail=rel)


def scaffold_real_dest_mirror(dest: str, work: Path) -> Path:
    """Build a local mirror that reflects REAL destination existence for plan ops.

    Only presence of preserve/scaffold paths matters for overwrite decisions —
    never invent a fake dest that would force scaffold_only over existing files.
    """
    mirror = work / "real_dest_mirror"
    mirror.mkdir(parents=True, exist_ok=True)
    (mirror / ".erpsmart_deploy_identity").write_text(
        ALLOWED_DEST_MARKERS[".erpsmart_deploy_identity"] + "\n", encoding="utf-8"
    )
    # Always mark config as present if remote has it (preserve).
    if remote_file_exists(dest, "app/config.php"):
        cfg = mirror / "app" / "config.php"
        cfg.parent.mkdir(parents=True, exist_ok=True)
        cfg.write_text("RUNTIME_PRESENT\n", encoding="utf-8")
    for rel in STORAGE_SCAFFOLD_ALLOWLIST:
        if remote_file_exists(dest, rel):
            p = mirror / rel
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text("EXISTING\n", encoding="utf-8")
    # Presence of common root files helps dry-run reporting; not required for safety.
    for rel in ("index.php", RELEASE_META_NAME):
        if remote_file_exists(dest, rel):
            p = mirror / rel
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text("EXISTING\n", encoding="utf-8")
    return mirror


def acquire_lock(dest: str, stamp: str) -> str:
    lock = f"{dest}/{LOCK_NAME}"
    # mkdir is atomic on POSIX; fail if lock dir already exists.
    script = (
        f"if mkdir {shlex.quote(lock)} 2>/dev/null; then "
        f"printf '%s\\n' {shlex.quote(stamp)} > {shlex.quote(lock + '/owner')}; echo LOCKED; "
        f"else echo BUSY; exit 42; fi"
    )
    cp = run_ssh(script)
    if cp.returncode == 42 or "BUSY" in (cp.stdout or ""):
        raise RemoteError("concurrent_deploy_lock")
    if cp.returncode != 0 or "LOCKED" not in (cp.stdout or ""):
        raise RemoteError("lock_acquire_failed", detail=(cp.stderr or "")[:200])
    return lock


def release_lock(lock: str) -> None:
    run_ssh(f"rm -rf {shlex.quote(lock)}", timeout=60)


def set_maintenance(dest: str, enabled: bool) -> None:
    path = f"{dest}/{MAINT_NAME}"
    if enabled:
        run_ssh_checked(
            f"mkdir -p {shlex.quote(str(Path(path).parent).replace(chr(92), '/'))} && "
            f"printf '1\\n' > {shlex.quote(path)}",
            label="maintenance_on",
        )
    else:
        run_ssh(f"rm -f {shlex.quote(path)}")


def supports_atomic_publish(dest: str) -> bool:
    """Probe whether same-FS rename of a release tree is available.

    Shared hosting often allows mkdir+mv within the account home. We only claim
    atomic when a probe rename succeeds in a sibling temp dir.
    """
    parent = dest.rsplit("/", 1)[0] if "/" in dest else "."
    probe_a = f"{parent}/_erpsmart_atomic_probe_a_{os.getpid()}"
    probe_b = f"{parent}/_erpsmart_atomic_probe_b_{os.getpid()}"
    cp = run_ssh(
        f"mkdir -p {shlex.quote(probe_a)} && mv {shlex.quote(probe_a)} {shlex.quote(probe_b)} && "
        f"rmdir {shlex.quote(probe_b)} && echo ATOMIC_OK"
    )
    return cp.returncode == 0 and "ATOMIC_OK" in (cp.stdout or "")


def backup_files(dest: str, bak: str, rels: list[str]) -> dict:
    """Backup every existing destination file that will be overwritten.

    If any backup step fails, raise before destination mutation.
    """
    run_ssh_checked(f"mkdir -p {shlex.quote(bak)}", label="backup_mkdir")
    backed = 0
    for rel in rels:
        remote_path = f"{dest}/{rel}"
        # Skip if not present — nothing to backup.
        exists = remote_file_exists(dest, rel)
        if not exists:
            continue
        remote_bak = f"{bak}/{rel}"
        remote_bak_dir = str(Path(remote_bak).parent).replace("\\", "/")
        run_ssh_checked(
            f"mkdir -p {shlex.quote(remote_bak_dir)} && cp -p {shlex.quote(remote_path)} {shlex.quote(remote_bak)}",
            label="backup_copy",
        )
        # Verify backup bytes exist.
        cp = run_ssh(f"test -f {shlex.quote(remote_bak)} && cmp -s {shlex.quote(remote_path)} {shlex.quote(remote_bak)}")
        if cp.returncode != 0:
            raise RemoteError("backup_verify_failed", detail=rel)
        backed += 1
    return {"backup_dir": bak, "files": backed, "ok": True}


def restore_from_backup(dest: str, bak: str, rels: list[str]) -> dict:
    restored = 0
    for rel in rels:
        remote_bak = f"{bak}/{rel}"
        remote_path = f"{dest}/{rel}"
        cp = run_ssh(f"test -f {shlex.quote(remote_bak)}")
        if cp.returncode != 0:
            continue
        remote_dir = str(Path(remote_path).parent).replace("\\", "/")
        run_ssh_checked(
            f"mkdir -p {shlex.quote(remote_dir)} && cp -p {shlex.quote(remote_bak)} {shlex.quote(remote_path)}",
            label="rollback_copy",
        )
        restored += 1
    return {"restored": restored, "backup_dir": bak}


def remote_sha256(dest: str, rel: str) -> str | None:
    path = f"{dest}/{rel}"
    # Prefer sha256sum; fall back to openssl.
    cp = run_ssh(
        f"if test -f {shlex.quote(path)}; then "
        f"(sha256sum {shlex.quote(path)} 2>/dev/null || openssl dgst -sha256 {shlex.quote(path)}) "
        f"| awk '{{print $NF}}'; else echo MISSING; fi"
    )
    if cp.returncode != 0:
        raise RemoteError("remote_hash_failed", detail=rel)
    body = (cp.stdout or "").strip().splitlines()
    if not body or body[-1] == "MISSING":
        return None
    return body[-1].strip().lower()


def verify_published(package: Path, dest: str, rels: list[str], expected_sha: str) -> dict:
    mismatches: list[dict] = []
    checked = 0
    for rel in rels:
        src = package / rel
        if not src.is_file():
            continue
        want = sha256_file(src).lower()
        got = remote_sha256(dest, rel)
        checked += 1
        if got != want:
            mismatches.append({"rel": rel, "expected": want, "got": got})
    release_got = remote_sha256(dest, RELEASE_META_NAME)
    release_want = sha256_file(package / RELEASE_META_NAME).lower() if (package / RELEASE_META_NAME).is_file() else None
    release_meta_ok = release_want is not None and release_got == release_want
    # Also confirm embedded git_sha if present.
    sha_ok = False
    if (package / RELEASE_META_NAME).is_file():
        meta = json.loads((package / RELEASE_META_NAME).read_text(encoding="utf-8"))
        remote_body = run_ssh(f"cat {shlex.quote(dest + '/' + RELEASE_META_NAME)}")
        if remote_body.returncode == 0:
            try:
                remote_meta = json.loads(remote_body.stdout or "{}")
                sha_ok = str(remote_meta.get("git_sha") or "") == expected_sha and str(meta.get("git_sha") or "") == expected_sha
            except json.JSONDecodeError:
                sha_ok = False
    ok = not mismatches and release_meta_ok and sha_ok
    return {
        "ok": ok,
        "checked_files": checked,
        "mismatches": mismatches[:20],
        "release_meta_checksum_ok": release_meta_ok,
        "git_sha_ok": sha_ok,
        "expected_git_sha": expected_sha,
    }


def stage_release_tree(package: Path, dest: str, rels: list[str], stamp: str) -> str:
    parent = dest.rsplit("/", 1)[0] if "/" in dest else "."
    stage = f"{parent}/_erpsmart_staging_release_{stamp}"
    run_ssh_checked(f"mkdir -p {shlex.quote(stage)}", label="stage_mkdir")
    for rel in rels:
        src = package / rel
        if not src.is_file():
            continue
        remote_path = f"{stage}/{rel}"
        remote_dir = str(Path(remote_path).parent).replace("\\", "/")
        run_ssh_checked(f"mkdir -p {shlex.quote(remote_dir)}", label="stage_subdir")
        run_scp(src, remote_path)
    return stage


def publish_from_stage(dest: str, stage: str, rels: list[str], atomic: bool) -> dict:
    """Copy staged files into destination. Atomic mode still copies file-by-file
    into the live tree (shared hosting cannot swap whole web root while
    preserving config/storage), but uses a pre-staged tree so publish is
    all-or-nothing relative to a verified stage.
    """
    written = 0
    for rel in rels:
        staged = f"{stage}/{rel}"
        remote_path = f"{dest}/{rel}"
        remote_dir = str(Path(remote_path).parent).replace("\\", "/")
        # Never touch preserve paths even if somehow listed.
        if rel.replace("\\", "/") in PRESERVE_EXACT:
            continue
        run_ssh_checked(f"mkdir -p {shlex.quote(remote_dir)}", label="dest_mkdir")
        if atomic:
            # Same-FS mv of a single file is closer to atomic than scp mid-write.
            run_ssh_checked(
                f"cp -p {shlex.quote(staged)} {shlex.quote(staged + '.pub')} && "
                f"mv -f {shlex.quote(staged + '.pub')} {shlex.quote(remote_path)}",
                label="atomic_publish",
            )
        else:
            run_ssh_checked(
                f"cp -p {shlex.quote(staged)} {shlex.quote(remote_path)}",
                label="publish_copy",
            )
        written += 1
    return {"written": written, "atomic": atomic}


def apply_files(package: Path, dest: str, ops_copy: list[str], dry_run: bool, expected_sha: str) -> dict:
    planned = len(ops_copy)
    if dry_run:
        return {"mode": "dry_run", "planned_copies": planned, "written": 0, "ok": True}

    # Ensure release meta is always published when present.
    rels = list(ops_copy)
    if (package / RELEASE_META_NAME).is_file() and RELEASE_META_NAME not in rels:
        rels.append(RELEASE_META_NAME)

    stamp = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
    parent = dest.rsplit("/", 1)[0] if "/" in dest else "."
    bak = f"{parent}/_erpsmart_staging_code_bak_{stamp}"
    lock = None
    maintenance = False
    published_rels: list[str] = []
    try:
        lock = acquire_lock(dest, stamp)
        atomic = supports_atomic_publish(dest)
        # 1) Stage entire payload off-destination first (no live mutation yet).
        stage = stage_release_tree(package, dest, rels, stamp)
        # 2) Backup existing destination files — failure aborts with ZERO dest changes.
        backup_info = backup_files(dest, bak, rels)
        # 3) Maintenance window + publish.
        set_maintenance(dest, True)
        maintenance = True
        pub = publish_from_stage(dest, stage, rels, atomic=atomic)
        published_rels = list(rels)
        # 4) Verify SHA + checksums on server (login page alone is insufficient).
        verify = verify_published(package, dest, rels, expected_sha)
        if not verify.get("ok"):
            restore_from_backup(dest, bak, published_rels)
            raise RemoteError("post_publish_verify_failed", detail=json.dumps(verify)[:400])
        set_maintenance(dest, False)
        maintenance = False
        # Cleanup stage (best-effort).
        run_ssh(f"rm -rf {shlex.quote(stage)}")
        return {
            "mode": "apply",
            "ok": True,
            "written": pub["written"],
            "planned_copies": planned,
            "backup_dir": bak,
            "atomic_publish": atomic,
            "verify": verify,
            "lock": lock,
        }
    except RemoteError as e:
        # If we mutated dest, restore. If backup itself failed, dest was untouched.
        if published_rels and e.code != "backup_verify_failed":
            try:
                restore_from_backup(dest, bak, published_rels)
            except Exception as restore_exc:  # noqa: BLE001
                return {
                    "mode": "apply",
                    "ok": False,
                    "error": e.code,
                    "detail": e.detail,
                    "rollback": "failed",
                    "rollback_error": type(restore_exc).__name__,
                    "backup_dir": bak,
                }
        return {
            "mode": "apply",
            "ok": False,
            "error": e.code,
            "detail": e.detail,
            "backup_dir": bak,
            "dest_mutated": bool(published_rels),
            "rollback": "restored" if published_rels else "not_needed_pre_publish",
        }
    finally:
        if maintenance:
            try:
                set_maintenance(dest, False)
            except Exception:  # noqa: BLE001
                pass
        if lock:
            try:
                release_lock(lock)
            except Exception:  # noqa: BLE001
                pass


def package_git_sha(package: Path) -> str:
    meta = package / RELEASE_META_NAME
    if not meta.is_file():
        return "UNKNOWN"
    try:
        return str(json.loads(meta.read_text(encoding="utf-8")).get("git_sha") or "UNKNOWN")
    except json.JSONDecodeError:
        return "UNKNOWN"


def main() -> int:
    ap = argparse.ArgumentParser(description="Staging remote apply via pinned-SSH (dry-run default)")
    ap.add_argument("--package", type=Path, default=None)
    ap.add_argument("--apply", action="store_true")
    ap.add_argument("--i-understand-staging-risk", action="store_true")
    ap.add_argument("--manifest-out", type=Path, default=None)
    ap.add_argument("--check-secrets-only", action="store_true")
    ap.add_argument("--expected-sha", default=None, help="Expected git SHA (defaults to package release meta)")
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
    expected_sha = args.expected_sha or package_git_sha(args.package)

    git_ref = env("STAGING_GIT_REF") or ""
    await_ci = env("STAGING_AWAIT_CI_RESULT") or "success"
    ssh_ready = all(present.values())

    # Plan against REAL destination existence (scaffold/preserve decisions).
    with tempfile.TemporaryDirectory() as td:
        work = Path(td)
        try:
            mirror = scaffold_real_dest_mirror(dest, work)
        except RemoteError as e:
            report = {
                "ok": False,
                "status": "STAGING_DEST_PROBE_FAILED",
                "error": e.code,
                "detail": e.detail,
                "target_dest": dest,
                "identity": identity,
            }
            print(json.dumps(report, indent=2))
            if args.manifest_out:
                args.manifest_out.parent.mkdir(parents=True, exist_ok=True)
                args.manifest_out.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
            return 1
        ops = build_plan(args.package, mirror)

    copy_rels = [o.rel for o in ops if o.op in {"copy", "scaffold_only"}]
    # Never include preserve paths.
    copy_rels = [r for r in copy_rels if r.replace("\\", "/") not in PRESERVE_EXACT]

    report: dict = {
        "target_dest": dest,
        "canonical_dest": CANONICAL_DEST,
        "identity": identity,
        "planned_copies": len(copy_rels),
        "expected_git_sha": expected_sha,
        "git_ref": git_ref or None,
        "await_ci_result": await_ci,
        "migrations": "NOT_RUN",
        "worker_engine": "OUT_OF_SCOPE",
        "delete_policy": "never_delete",
        "strict_host_key": True,
        "real_dest_scaffold": True,
        "preserve_exact": sorted(PRESERVE_EXACT),
    }

    if args.apply:
        if not args.i_understand_staging_risk:
            report.update({"ok": False, "error": "apply_requires_ack_flag"})
            print(json.dumps(report, indent=2))
            return 3
        allowed, reason = allow_real_apply(
            apply=True,
            git_ref=git_ref or "refs/heads/main",  # local CLI defaults to main when unset
            ssh_ready=ssh_ready,
            identity_ok=bool(identity.get("ok")),
            await_ci_result=await_ci,
        )
        # When STAGING_GIT_REF is explicitly set to non-main, refuse.
        if git_ref and git_ref != "refs/heads/main":
            allowed, reason = False, "ref_not_main"
        if not allowed:
            report.update({"ok": False, "error": reason, "status": "STAGING_APPLY_REFUSED"})
            print(json.dumps(report, indent=2))
            if args.manifest_out:
                args.manifest_out.parent.mkdir(parents=True, exist_ok=True)
                args.manifest_out.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
            return 3
        applied = apply_files(args.package, dest, copy_rels, dry_run=False, expected_sha=expected_sha)
        report["apply"] = applied
        report["ok"] = bool(applied.get("ok"))
        report["status"] = "STAGING_CODE_DEPLOYED" if report["ok"] else "STAGING_APPLY_FAILED"
    else:
        report["ok"] = bool(identity.get("ok"))
        report["status"] = "STAGING_DRY_RUN"
        report["note"] = "dry_run_no_mutation"
        allowed, reason = allow_real_apply(
            apply=False,
            git_ref=git_ref or "refs/heads/main",
            ssh_ready=ssh_ready,
            identity_ok=bool(identity.get("ok")),
            await_ci_result=await_ci,
        )
        report["would_apply"] = {"allowed": allowed, "reason": reason}

    print(json.dumps(report, indent=2))
    if args.manifest_out:
        args.manifest_out.parent.mkdir(parents=True, exist_ok=True)
        args.manifest_out.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    return 0 if report.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
