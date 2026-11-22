#!/usr/bin/env python3
"""Controlled PHP sync for Agentic AI direct-production (NO live mutate by default).

Hard rules:
  - Default mode is non-mutating dry-run.
  - Never overwrite app/config.php or storage user data (uploads/logs/cache contents).
  - Never delete unknown destination files.
  - Never run DB migrations.
  - Worker (engine/) is out of scope — separate package.
  - Destination path must match the reviewed allowlist identity.
  - --apply requires explicit --i-understand-production-risk and a destination marker check.

This script does NOT connect to production. Operators copy artifacts offline or to a
verified local mirror / disposable fixture first.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import sys
import time
from dataclasses import dataclass, asdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

# Reviewed production web root identity (do not silently retarget).
ALLOWED_DEST_MARKERS = {
    # Relative marker file written by operator on real host OR fixture.
    ".erpsmart_deploy_identity": "excel2.bcsrp:/home3/zzflgmfd/excel2.bcsrp/",
}
CANONICAL_DEST_HINT = "/home3/zzflgmfd/excel2.bcsrp/"

# Top-level PHP/entry files allowed for sync (explicit allowlist).
ROOT_FILE_ALLOWLIST = [
    ".htaccess",
    "index.php",
    "openapi-v2.json",
    "openapi.json",
    "API_V2_SAAS_FA.md",
    "api_tokens.php",
    "api_v2.php",
    "v5_api.php",
    "accounting_api.php",
    "file_download.php",
    "v4_files.php",
    "api_docs.php",
    "api_token_admin.php",
    "api_v1.php",
    "io.php",
    "v3_2_api.php",
    "cron.php",
    "api.php",
    "ai_api.php",
    "ai_live.php",
    "copilot_api.php",
    "mcp_api.php",
    "migrate_v2.php",
    "README_FA.md",
    "INSTALL_CPanel_FA.md",
    "DEPLOY_GIT_CPanel_FA.md",
]

# Never ship as public overwrite from this release sync.
ROOT_FILE_DENYLIST = {
    "install.php",  # no public installer overwrite
}

# Directory trees to sync recursively (files only; secrets filtered).
DIR_ALLOWLIST = [
    "app",
    "assets",
    "sample_import",
]

# Paths that must NEVER be overwritten from package.
PRESERVE_EXACT = {
    "app/config.php",
}

# Storage: only scaffold/security files; never user uploads/logs/cache bytes.
STORAGE_SCAFFOLD_ALLOWLIST = [
    "storage/.htaccess",
    "storage/cache/.htaccess",
    "storage/uploads/.htaccess",
    "storage/exports/.gitkeep",
    "storage/imports/.gitkeep",
    "storage/logs/.gitkeep",
    "storage/maintenance/.gitkeep",
    "storage/maintenance/.gitignore",
]

SKIP_NAME_PARTS = {".git", "__pycache__", ".venv", "node_modules"}
SECRET_NAME_HINTS = (
    "config.php",
    ".env",
    "config.json",
    "config.cloud.json",
    "config.gemini.json",
    ".pem",
    ".key",
    ".pfx",
    ".sql",
)


@dataclass
class PlannedOp:
    op: str  # copy|skip_preserve|skip_missing_src|scaffold_only
    rel: str
    src_sha256: str | None = None
    dst_sha256: str | None = None
    reason: str = ""


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def posix_rel(path: Path, root: Path) -> str:
    return path.relative_to(root).as_posix()


def is_secretish(rel: str) -> bool:
    low = rel.replace("\\", "/").lower()
    if low in {p.lower() for p in PRESERVE_EXACT}:
        return True
    base = Path(low).name
    if base == "config.php" and low.startswith("app/"):
        return True
    for hint in SECRET_NAME_HINTS:
        if hint in low and not low.endswith(".htaccess"):
            if hint in (".sql", ".pem", ".key", ".pfx", ".env"):
                return True
            if hint.startswith("config.") and "engine/" in low:
                return True
    return False


def iter_dir_files(src_root: Path, rel_dir: str) -> list[Path]:
    base = src_root / rel_dir
    if not base.is_dir():
        return []
    out: list[Path] = []
    for p in sorted(base.rglob("*")):
        if not p.is_file():
            continue
        rel = posix_rel(p, src_root)
        if any(part in SKIP_NAME_PARTS for part in Path(rel).parts):
            continue
        if is_secretish(rel):
            continue
        if rel.replace("\\", "/") in PRESERVE_EXACT:
            continue
        out.append(p)
    return out


def verify_destination_identity(dest: Path, require_marker: bool) -> dict:
    """Refuse unverified destinations. Marker content must match allowlist value."""
    marker = dest / ".erpsmart_deploy_identity"
    info = {
        "dest": str(dest.resolve()),
        "canonical_hint": CANONICAL_DEST_HINT,
        "marker_path": str(marker),
        "marker_present": marker.is_file(),
        "ok": False,
        "reason": "",
    }
    if not dest.exists():
        info["reason"] = "dest_missing"
        return info
    if not dest.is_dir():
        info["reason"] = "dest_not_directory"
        return info
    if require_marker:
        if not marker.is_file():
            info["reason"] = "missing_deploy_identity_marker"
            return info
        body = marker.read_text(encoding="utf-8").strip()
        expected = ALLOWED_DEST_MARKERS[".erpsmart_deploy_identity"]
        if body != expected:
            info["reason"] = f"identity_mismatch:got={body!r}"
            return info
        info["ok"] = True
        info["reason"] = "identity_verified"
        return info
    # Dry-run without marker: still warn; allow fixture planning only.
    info["ok"] = True
    info["reason"] = "dry_run_identity_not_enforced"
    return info


def build_plan(src_root: Path, dest: Path) -> list[PlannedOp]:
    ops: list[PlannedOp] = []

    # Preserve exact secrets if present on dest.
    for rel in sorted(PRESERVE_EXACT):
        src = src_root / rel
        dst = dest / rel
        ops.append(
            PlannedOp(
                op="skip_preserve",
                rel=rel,
                src_sha256=sha256_file(src) if src.is_file() else None,
                dst_sha256=sha256_file(dst) if dst.is_file() else None,
                reason="never_overwrite_runtime_secret",
            )
        )

    for rel in ROOT_FILE_ALLOWLIST:
        if rel in ROOT_FILE_DENYLIST:
            continue
        src = src_root / rel
        dst = dest / rel
        if not src.is_file():
            ops.append(PlannedOp(op="skip_missing_src", rel=rel, reason="source_absent"))
            continue
        ops.append(
            PlannedOp(
                op="copy",
                rel=rel,
                src_sha256=sha256_file(src),
                dst_sha256=sha256_file(dst) if dst.is_file() else None,
                reason="root_allowlist",
            )
        )

    for d in DIR_ALLOWLIST:
        for src in iter_dir_files(src_root, d):
            rel = posix_rel(src, src_root)
            dst = dest / rel
            ops.append(
                PlannedOp(
                    op="copy",
                    rel=rel,
                    src_sha256=sha256_file(src),
                    dst_sha256=sha256_file(dst) if dst.is_file() else None,
                    reason=f"dir_allowlist:{d}",
                )
            )

    for rel in STORAGE_SCAFFOLD_ALLOWLIST:
        src = src_root / rel
        dst = dest / rel
        if not src.is_file():
            ops.append(PlannedOp(op="skip_missing_src", rel=rel, reason="scaffold_src_absent"))
            continue
        # Only copy scaffold if destination file missing — never clobber existing.
        if dst.is_file():
            ops.append(
                PlannedOp(
                    op="skip_preserve",
                    rel=rel,
                    src_sha256=sha256_file(src),
                    dst_sha256=sha256_file(dst),
                    reason="storage_scaffold_exists_preserve",
                )
            )
        else:
            ops.append(
                PlannedOp(
                    op="scaffold_only",
                    rel=rel,
                    src_sha256=sha256_file(src),
                    dst_sha256=None,
                    reason="storage_scaffold_missing",
                )
            )

    # Explicitly record that storage user trees are out of scope (no delete / no copy).
    for tree in ("storage/uploads", "storage/logs", "storage/cache", "storage/exports", "storage/imports"):
        ops.append(
            PlannedOp(
                op="skip_preserve",
                rel=tree,
                reason="storage_user_data_byte_for_byte_out_of_scope",
            )
        )

    return ops


def backup_existing(dest: Path, backup_root: Path, rels: list[str]) -> dict:
    stamp = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
    bak = backup_root / f"controlled_sync_backup_{stamp}"
    bak.mkdir(parents=True, exist_ok=True)
    copied = 0
    for rel in rels:
        src = dest / rel
        if not src.is_file():
            continue
        target = bak / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(src, target)
        copied += 1
    return {"backup_dir": str(bak), "files": copied}


def apply_plan(src_root: Path, dest: Path, ops: list[PlannedOp], backup_root: Path | None) -> dict:
    to_write = [o for o in ops if o.op in {"copy", "scaffold_only"}]
    backup_info = None
    if backup_root is not None:
        backup_info = backup_existing(dest, backup_root, [o.rel for o in to_write])
    written = 0
    for o in to_write:
        src = src_root / o.rel
        dst = dest / o.rel
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(src, dst)
        written += 1
    return {"written": written, "backup": backup_info, "deleted": 0}


def manifest_compare_storage(dest: Path, before: dict[str, str]) -> dict:
    """Compare sha256 of all files under storage/ against a pre-sync snapshot.

    Existing files must remain byte-for-byte identical. Additive missing scaffold
    (.htaccess / .gitkeep) is allowed; deletes and content mutations are not.
    """
    after: dict[str, str] = {}
    storage = dest / "storage"
    if storage.is_dir():
        for p in sorted(storage.rglob("*")):
            if p.is_file():
                after[posix_rel(p, dest)] = sha256_file(p)
    mutated = sorted(k for k in before if before.get(k) != after.get(k))
    deleted = sorted(k for k in before if k not in after)
    added = sorted(k for k in after if k not in before)
    scaffold_ok = all(
        k.endswith("/.htaccess") or k.endswith("/.gitkeep") or k.endswith(".gitignore") for k in added
    )
    existing_preserved = mutated == [] and deleted == []
    return {
        "before_count": len(before),
        "after_count": len(after),
        "mutated": mutated,
        "deleted": deleted,
        "added": added,
        "existing_byte_for_byte_preserved": existing_preserved,
        "additive_scaffold_only": scaffold_ok,
        "byte_for_byte_preserved": existing_preserved,  # alias: existing user data
        "ok": existing_preserved and scaffold_ok,
    }


def snapshot_storage(dest: Path) -> dict[str, str]:
    out: dict[str, str] = {}
    storage = dest / "storage"
    if not storage.is_dir():
        return out
    for p in sorted(storage.rglob("*")):
        if p.is_file():
            out[posix_rel(p, dest)] = sha256_file(p)
    return out


def snapshot_config(dest: Path) -> str | None:
    cfg = dest / "app" / "config.php"
    return sha256_file(cfg) if cfg.is_file() else None


def run_selftest(work: Path) -> dict:
    """Disposable FS fixture proving storage + config byte-for-byte preservation."""
    fixture = work / "fixture_dest"
    package = work / "fixture_pkg"
    if fixture.exists():
        shutil.rmtree(fixture)
    if package.exists():
        shutil.rmtree(package)

    # Build fake package (source).
    for rel in (
        "index.php",
        "ai_api.php",
        "ai_live.php",
        "copilot_api.php",
        "mcp_api.php",
        "app/Core/AiSchema.php",
        "app/config.php",
        "assets/business-copilot.js",
        "storage/.htaccess",
        "storage/uploads/.htaccess",
        "storage/cache/.htaccess",
    ):
        p = package / rel
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(f"PKG:{rel}\n", encoding="utf-8")

    # Existing "production" fixture with unique user bytes.
    for rel, body in (
        ("index.php", "OLD_INDEX\n"),
        ("app/config.php", "SECRET_RUNTIME_CONFIG_DO_NOT_TOUCH\n"),
        ("app/Core/Old.php", "LEGACY_UNKNOWN_FILE\n"),
        ("storage/uploads/customer_invoice_001.pdf", "UPLOAD_BYTES_UNIQUE_9f3a\n"),
        ("storage/logs/app.log", "LOG_LINE_KEEP\n"),
        ("storage/cache/runtime.cache", "CACHE_BLOB\n"),
        ("storage/.htaccess", "OLD_HTACCESS\n"),
        (".erpsmart_deploy_identity", ALLOWED_DEST_MARKERS[".erpsmart_deploy_identity"] + "\n"),
    ):
        p = fixture / rel
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(body, encoding="utf-8")

    before_storage = snapshot_storage(fixture)
    before_cfg = snapshot_config(fixture)
    unknown_before = (fixture / "app/Core/Old.php").read_text(encoding="utf-8")

    identity = verify_destination_identity(fixture, require_marker=True)
    assert identity["ok"], identity
    ops = build_plan(package, fixture)
    apply_info = apply_plan(package, fixture, ops, backup_root=work / "backups")
    after_storage = manifest_compare_storage(fixture, before_storage)
    after_cfg = snapshot_config(fixture)
    unknown_after = (fixture / "app/Core/Old.php").read_text(encoding="utf-8")

    # Assertions as return payload (caller checks ok).
    upload_rel = "storage/uploads/customer_invoice_001.pdf"
    after_snap = snapshot_storage(fixture)
    checks = {
        "identity_ok": identity["ok"],
        "storage_existing_preserved": after_storage["existing_byte_for_byte_preserved"],
        "storage_additive_scaffold_only": after_storage["additive_scaffold_only"],
        "config_preserved": before_cfg == after_cfg
        and (fixture / "app/config.php").read_text(encoding="utf-8") == "SECRET_RUNTIME_CONFIG_DO_NOT_TOUCH\n",
        "unknown_file_not_deleted": unknown_before == unknown_after == "LEGACY_UNKNOWN_FILE\n",
        "copilot_synced": (fixture / "copilot_api.php").is_file()
        and (fixture / "copilot_api.php").read_text(encoding="utf-8").startswith("PKG:"),
        "mcp_synced": (fixture / "mcp_api.php").is_file(),
        "index_updated": (fixture / "index.php").read_text(encoding="utf-8").startswith("PKG:"),
        "no_deletes": apply_info["deleted"] == 0,
        "storage_upload_sha_unchanged": before_storage.get(upload_rel) == after_snap.get(upload_rel),
    }

    ok = all(checks.values()) and after_storage.get("ok", False)
    return {
        "ok": ok,
        "checks": checks,
        "storage_compare": after_storage,
        "apply": apply_info,
        "ops_summary": {
            "copy": sum(1 for o in ops if o.op == "copy"),
            "preserve": sum(1 for o in ops if o.op == "skip_preserve"),
            "scaffold": sum(1 for o in ops if o.op == "scaffold_only"),
        },
        "config_sha_before": before_cfg,
        "config_sha_after": after_cfg,
    }


def main() -> int:
    ap = argparse.ArgumentParser(description="Controlled Agentic AI PHP sync (dry-run default)")
    ap.add_argument("--src", type=Path, default=ROOT, help="Package / repo root source")
    ap.add_argument("--dest", type=Path, required=False, help="Destination web root (fixture or verified mirror)")
    ap.add_argument("--apply", action="store_true", help="Mutate destination (requires acknowledgements)")
    ap.add_argument(
        "--i-understand-production-risk",
        action="store_true",
        help="Required with --apply",
    )
    ap.add_argument("--require-identity-marker", action="store_true", default=False)
    ap.add_argument("--backup-dir", type=Path, default=None)
    ap.add_argument("--manifest-out", type=Path, default=None)
    ap.add_argument("--selftest", action="store_true", help="Run disposable FS fixture proof")
    ap.add_argument("--selftest-work", type=Path, default=ROOT / "storage" / "cache" / "agentic_controlled_sync_selftest")
    args = ap.parse_args()

    if args.selftest:
        result = run_selftest(args.selftest_work)
        out = args.manifest_out or (args.selftest_work / "selftest_result.json")
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
        print(json.dumps({"selftest_ok": result["ok"], "evidence": str(out)}, indent=2))
        return 0 if result["ok"] else 1

    if args.dest is None:
        print("REFUSED: --dest required unless --selftest", file=sys.stderr)
        return 2

    dest = args.dest
    src = args.src
    require_marker = bool(args.apply) or bool(args.require_identity_marker)
    identity = verify_destination_identity(dest, require_marker=require_marker)
    if args.apply and not args.i_understand_production_risk:
        print("REFUSED: --apply requires --i-understand-production-risk", file=sys.stderr)
        return 3
    if args.apply and not identity["ok"]:
        print(f"REFUSED: destination identity failed: {identity}", file=sys.stderr)
        return 3

    # Refuse if dest path string silently differs from reviewed hint when applying
    # to a path that looks like production but marker missing/wrong.
    dest_s = str(dest).replace("\\", "/")
    if args.apply and "zzflgmfd" in dest_s and CANONICAL_DEST_HINT.rstrip("/") not in dest_s:
        print("REFUSED: destination path does not match reviewed production identity", file=sys.stderr)
        return 3

    before_storage = snapshot_storage(dest)
    before_cfg = snapshot_config(dest)
    ops = build_plan(src, dest)

    report = {
        "mode": "apply" if args.apply else "dry_run",
        "src": str(src.resolve()),
        "dest": str(dest.resolve()),
        "identity": identity,
        "canonical_dest_hint": CANONICAL_DEST_HINT,
        "migrations": "NOT_RUN",
        "worker_engine": "OUT_OF_SCOPE",
        "mcp_default": "disabled",
        "delete_policy": "never_delete_unknown_dest_files",
        "ops": [asdict(o) for o in ops],
        "counts": {
            "copy": sum(1 for o in ops if o.op == "copy"),
            "scaffold_only": sum(1 for o in ops if o.op == "scaffold_only"),
            "skip_preserve": sum(1 for o in ops if o.op == "skip_preserve"),
            "skip_missing_src": sum(1 for o in ops if o.op == "skip_missing_src"),
        },
        "config_sha_before": before_cfg,
        "storage_files_before": len(before_storage),
    }

    if args.apply:
        apply_info = apply_plan(src, dest, ops, backup_root=args.backup_dir or (dest / "_erpsmart_sync_backups"))
        report["apply"] = apply_info
        report["config_sha_after"] = snapshot_config(dest)
        report["storage_compare"] = manifest_compare_storage(dest, before_storage)
        report["config_preserved"] = report["config_sha_before"] == report["config_sha_after"]
        report["ok"] = (
            report["config_preserved"]
            and report["storage_compare"].get("ok", False)
            and apply_info["deleted"] == 0
        )
    else:
        report["ok"] = identity.get("ok", False) or not require_marker
        report["note"] = "dry_run_no_mutation"

    if args.manifest_out:
        args.manifest_out.parent.mkdir(parents=True, exist_ok=True)
        args.manifest_out.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
        print(json.dumps({"ok": report["ok"], "manifest": str(args.manifest_out), "mode": report["mode"]}, indent=2))
    else:
        print(json.dumps(report, indent=2))
    return 0 if report.get("ok") else 1


if __name__ == "__main__":
    # Allow local fixture apply without importing unused os in hot path.
    _ = os.environ
    raise SystemExit(main())
