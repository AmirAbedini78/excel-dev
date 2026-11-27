#!/usr/bin/env python3
"""Cron-based pull deploy for STAGING excel2.bcsrp.ir only (no public web endpoint).

Architecture:
  GitHub main → CI gates → GitHub Release Assets → this CLI (cPanel cron) →
  controlled sync → health check → rollback on failure.

Hard rules:
  - Staging only: excel2.bcsrp → /home3/zzflgmfd/excel2.bcsrp/
  - Refuse production excel.bcsrp and any other site
  - Never execute downloaded code before Release validation
  - Never overwrite app/config.php, storage user data, env, API keys
  - Never delete unknown destination files
  - Backup must succeed before any dest mutation; else change NOTHING
  - Lock against concurrent cron
  - Shared-hosting compatible (no Docker/Redis/Node/systemd required)
  - Migrations NOT run here; Worker update is optional/PARTIAL if infra missing

Bootstrap + state live OUTSIDE docroot (default /home3/zzflgmfd/erpsmart-deploy/).
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import ssl
import sys
import tempfile
import time
import urllib.error
import urllib.request
import zipfile
from pathlib import Path

# Repo checkout: <repo>/scripts/this.py → parents[1]=repo, scripts next door.
# Bootstrap host: <bootstrap>/bin/this.py with helpers in <bootstrap>/lib/.
_HERE = Path(__file__).resolve().parent
_CANDIDATES = [
    _HERE,  # same dir (tests / flat install)
    _HERE.parent / "scripts",  # repo layout when executed from scripts/
    _HERE.parent / "lib",  # host bootstrap layout
    Path(os.environ["ERPSMART_DEPLOY_BOOTSTRAP"]) / "lib"
    if os.environ.get("ERPSMART_DEPLOY_BOOTSTRAP")
    else None,
]
for _p in _CANDIDATES:
    if _p and _p.is_dir() and (_p / "agentic_controlled_cpanel_sync.py").is_file():
        sys.path.insert(0, str(_p))
        break
else:
    # Fallback: assume classic repo scripts/ execution
    sys.path.insert(0, str(_HERE.parent / "scripts") if _HERE.name == "scripts" else str(_HERE))

ROOT = _HERE.parent if _HERE.name in {"scripts", "bin"} else _HERE

from agentic_controlled_cpanel_sync import (  # noqa: E402
    ALLOWED_DEST_MARKERS,
    CANONICAL_DEST_HINT,
    apply_plan,
    build_plan,
    refuse_non_staging_dest,
    sha256_file,
    snapshot_config,
    snapshot_storage,
    verify_destination_identity,
    manifest_compare_storage,
)
from staging_health_check import probe, probe_release  # noqa: E402

STAGING_HOST = "excel2.bcsrp.ir"
STAGING_URL = f"https://{STAGING_HOST}/"
CANONICAL_DEST = CANONICAL_DEST_HINT.rstrip("/")
DEFAULT_BOOTSTRAP = "/home3/zzflgmfd/erpsmart-deploy"
DEFAULT_REPO = "AmirAbedini78/excel-dev"
RELEASE_TAG_PREFIX = "staging-excel2-"
LOCK_NAME = "deploy.lock"
STATE_NAME = "state.json"
LOG_NAME = "cron_deploy.log"
IDENTITY_MARKER = ".erpsmart_deploy_identity"
EXPECTED_MARKER = ALLOWED_DEST_MARKERS[IDENTITY_MARKER]
UA = "ErpSmart-StagingCronDeploy/1.0"


class DeployError(RuntimeError):
    def __init__(self, code: str, detail: str = ""):
        super().__init__(code)
        self.code = code
        self.detail = detail


def _utc() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def log(bootstrap: Path, msg: str) -> None:
    line = f"{_utc()} {msg}"
    print(line, flush=True)
    try:
        log_path = bootstrap / "logs" / LOG_NAME
        log_path.parent.mkdir(parents=True, exist_ok=True)
        with log_path.open("a", encoding="utf-8") as fh:
            fh.write(line + "\n")
    except OSError:
        pass


def load_config(path: Path) -> dict:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise DeployError("config_invalid", "not_object")
    return data


def load_state(bootstrap: Path) -> dict:
    p = bootstrap / "state" / STATE_NAME
    if not p.is_file():
        return {"published_sha": None, "last_release_tag": None, "history": []}
    return json.loads(p.read_text(encoding="utf-8"))


def save_state(bootstrap: Path, state: dict) -> None:
    p = bootstrap / "state" / STATE_NAME
    p.parent.mkdir(parents=True, exist_ok=True)
    tmp = p.with_suffix(".tmp")
    tmp.write_text(json.dumps(state, indent=2) + "\n", encoding="utf-8")
    tmp.replace(p)


class DeployLock:
    def __init__(self, bootstrap: Path):
        self.path = bootstrap / "locks" / LOCK_NAME
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self._fh = None

    def acquire(self) -> None:
        if self.path.exists():
            # Stale lock: older than 45 minutes → steal with warning recorded in content.
            try:
                age = time.time() - self.path.stat().st_mtime
            except OSError:
                age = 0
            if age < 2700:
                raise DeployError("concurrent_lock", str(self.path))
        self._fh = self.path.open("w", encoding="utf-8")
        self._fh.write(json.dumps({"pid": os.getpid(), "at": _utc()}) + "\n")
        self._fh.flush()
        try:
            if os.name == "nt":
                import msvcrt

                msvcrt.locking(self._fh.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl

                fcntl.flock(self._fh.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError as e:
            self.release()
            raise DeployError("concurrent_lock", str(e)) from e

    def release(self) -> None:
        if self._fh is not None:
            try:
                if os.name == "nt":
                    import msvcrt

                    try:
                        msvcrt.locking(self._fh.fileno(), msvcrt.LK_UNLCK, 1)
                    except OSError:
                        pass
                else:
                    import fcntl

                    fcntl.flock(self._fh.fileno(), fcntl.LOCK_UN)
            finally:
                self._fh.close()
                self._fh = None
        try:
            if self.path.exists():
                self.path.unlink()
        except OSError:
            pass

    def __enter__(self):
        self.acquire()
        return self

    def __exit__(self, *exc):
        self.release()
        return False


def https_json(url: str, timeout: float = 60.0) -> dict | list:
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "application/vnd.github+json"})
    ctx = ssl.create_default_context()
    with urllib.request.urlopen(req, timeout=timeout, context=ctx) as resp:
        raw = resp.read()
    return json.loads(raw.decode("utf-8"))


def https_download(url: str, dest: Path, timeout: float = 300.0, max_bytes: int = 200_000_000) -> int:
    dest.parent.mkdir(parents=True, exist_ok=True)
    part = dest.with_suffix(dest.suffix + ".part")
    if part.exists():
        part.unlink()
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "application/octet-stream"})
    ctx = ssl.create_default_context()
    written = 0
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as resp, part.open("wb") as out:
            while True:
                chunk = resp.read(1024 * 256)
                if not chunk:
                    break
                written += len(chunk)
                if written > max_bytes:
                    raise DeployError("download_too_large", str(written))
                out.write(chunk)
        part.replace(dest)
    except Exception:
        if part.exists():
            try:
                part.unlink()
            except OSError:
                pass
        raise
    return written


def verify_dest_identity(dest: Path) -> None:
    refuse = refuse_non_staging_dest(dest)
    if refuse:
        raise DeployError(refuse, str(dest))
    dest_s = str(dest.resolve()).replace("\\", "/").rstrip("/")
    # On real host require exact canonical path; fixtures may use a local mirror
    # only when ERPSMART_CRON_ALLOW_FIXTURE=1 (unit tests).
    if dest_s != CANONICAL_DEST and os.environ.get("ERPSMART_CRON_ALLOW_FIXTURE") != "1":
        # Allow trailing identity if resolved path ends with excel2.bcsrp
        if not dest_s.endswith("/excel2.bcsrp") and "excel2.bcsrp" not in dest_s:
            raise DeployError("refused_non_canonical_dest", dest_s)
        if "excel.bcsrp" in dest_s and "excel2.bcsrp" not in dest_s:
            raise DeployError("refused_production_dest", dest_s)
    identity = verify_destination_identity(dest, require_marker=True)
    if not identity.get("ok"):
        raise DeployError("identity_marker_failed", identity.get("reason", ""))


def parse_sha256_sidecar(text: str, expected_name: str) -> str:
    # Formats: "<hex>  name" or "<hex>"
    line = text.strip().splitlines()[0].strip()
    parts = line.split()
    if not parts:
        raise DeployError("checksum_sidecar_empty")
    digest = parts[0].lower()
    if len(digest) != 64 or any(c not in "0123456789abcdef" for c in digest):
        raise DeployError("checksum_sidecar_invalid", digest[:20])
    if len(parts) >= 2 and Path(parts[-1]).name != expected_name:
        raise DeployError("checksum_name_mismatch", parts[-1])
    return digest


def safe_extract_zip(zip_path: Path, dest_dir: Path) -> list[str]:
    """Extract ZIP with ZIP Slip / symlink / absolute-path refusal."""
    if dest_dir.exists():
        shutil.rmtree(dest_dir)
    dest_dir.mkdir(parents=True, exist_ok=True)
    extracted: list[str] = []
    with zipfile.ZipFile(zip_path, "r") as zf:
        for info in zf.infolist():
            name = info.filename.replace("\\", "/")
            if not name or name.endswith("/"):
                continue
            if name.startswith("/") or name.startswith("../") or "/../" in f"/{name}/":
                raise DeployError("zip_slip_refused", name)
            if Path(name).is_absolute() or (len(name) > 1 and name[1] == ":"):
                raise DeployError("zip_absolute_path_refused", name)
            # Symlink members: ZipInfo flag / external attr — refuse create_system Unix symlinks.
            # Python zipfile: if create_system==3 and external_attr high bits indicate symlink.
            is_symlink = False
            if info.create_system == 3:  # Unix
                mode = (info.external_attr >> 16) & 0o170000
                if mode == 0o120000:
                    is_symlink = True
            if is_symlink or name.endswith(".lnk"):
                raise DeployError("zip_symlink_refused", name)
            target = (dest_dir / name).resolve()
            if not str(target).startswith(str(dest_dir.resolve())):
                raise DeployError("zip_slip_escape", name)
            target.parent.mkdir(parents=True, exist_ok=True)
            with zf.open(info, "r") as src, target.open("wb") as out:
                shutil.copyfileobj(src, out)
            extracted.append(name)
    return extracted


def verify_extracted_manifest(pkg_dir: Path, public_meta: dict) -> None:
    manifest = public_meta.get("file_manifest") or []
    files_sha = public_meta.get("files_sha256") or {}
    # Prefer in-zip release meta for per-file hashes when present.
    in_zip_meta = pkg_dir / "ERPSMART_STAGING_RELEASE.json"
    if in_zip_meta.is_file():
        inner = json.loads(in_zip_meta.read_text(encoding="utf-8"))
        if inner.get("git_sha") and public_meta.get("git_sha") and inner["git_sha"] != public_meta["git_sha"]:
            raise DeployError("inner_meta_sha_mismatch")
        files_sha = inner.get("files_sha256") or files_sha
        manifest = inner.get("file_manifest") or inner.get("files_sha256", {}).keys() or manifest
    if not manifest and files_sha:
        manifest = sorted(files_sha.keys())
    if not manifest:
        raise DeployError("manifest_empty")
    missing = []
    mismatch = []
    for rel in manifest:
        if rel.endswith(".zip") or rel.endswith(".sha256"):
            continue
        # Release meta embeds its own hash map — skip self-hash chicken/egg on the meta file.
        if Path(rel).name in {
            "ERPSMART_STAGING_RELEASE.json",
            "ERPSMART_STAGING_RELEASE_PUBLIC.json",
        }:
            p = pkg_dir / rel
            if not p.is_file() and Path(rel).name == "ERPSMART_STAGING_RELEASE.json":
                missing.append(rel)
            continue
        p = pkg_dir / rel
        if not p.is_file():
            missing.append(rel)
            continue
        if rel in files_sha:
            got = sha256_file(p)
            if got != files_sha[rel]:
                mismatch.append(rel)
    if missing:
        raise DeployError("manifest_missing_files", ",".join(missing[:10]))
    if mismatch:
        raise DeployError("manifest_checksum_mismatch", ",".join(mismatch[:10]))
    # Refuse secrets in extracted tree
    for p in pkg_dir.rglob("*"):
        if not p.is_file():
            continue
        rel = p.relative_to(pkg_dir).as_posix().lower()
        name = p.name.lower()
        if name == "config.php" and rel.startswith("app/"):
            raise DeployError("package_contains_config_php")
        if name.startswith(".env") and not name.endswith(".example"):
            raise DeployError("package_contains_env", rel)


def gates_ok(meta: dict) -> bool:
    gates = meta.get("gates") or {}
    return (
        (gates.get("release-gate") or "").lower() == "success"
        and (gates.get("rc1-mysql-php") or "").lower() == "success"
    )


def release_belongs_to_main(meta: dict, tag: str, sha: str) -> bool:
    if meta.get("branch_required") and meta.get("branch_required") != "main":
        return False
    if not sha or len(sha) < 12:
        return False
    # Tag convention: staging-excel2-<fullsha or short>
    if tag.startswith(RELEASE_TAG_PREFIX):
        tag_sha = tag[len(RELEASE_TAG_PREFIX) :]
        if not (sha.startswith(tag_sha) or tag_sha.startswith(sha[:12])):
            return False
    return True


def list_releases(repo: str) -> list[dict]:
    url = f"https://api.github.com/repos/{repo}/releases?per_page=20"
    data = https_json(url)
    if not isinstance(data, list):
        raise DeployError("releases_api_invalid")
    return data


def pick_latest_valid_release(releases: list[dict], state: dict) -> dict | None:
    """Return newest release that looks like a staging-excel2 package, or None."""
    candidates = []
    for rel in releases:
        if rel.get("draft") or rel.get("prerelease"):
            continue
        tag = rel.get("tag_name") or ""
        if not tag.startswith(RELEASE_TAG_PREFIX):
            continue
        assets = {a.get("name"): a for a in (rel.get("assets") or []) if a.get("name")}
        if not any(n.endswith(".zip") for n in assets):
            continue
        if "ERPSMART_STAGING_RELEASE_PUBLIC.json" not in assets and "ERPSMART_STAGING_RELEASE.json" not in assets:
            continue
        candidates.append(rel)
    if not candidates:
        return None
    # GitHub returns newest first.
    return candidates[0]


def asset_url(assets: dict, name: str) -> str:
    a = assets.get(name)
    if not a:
        raise DeployError("asset_missing", name)
    url = a.get("browser_download_url") or a.get("url")
    if not url:
        raise DeployError("asset_url_missing", name)
    return url


def find_zip_asset(assets: dict) -> tuple[str, dict]:
    for name, a in assets.items():
        if name.endswith(".zip") and name.startswith("erpsmart-staging-php-"):
            return name, a
    raise DeployError("zip_asset_missing")


def backup_dest(dest: Path, backup_root: Path, rels: list[str]) -> Path:
    stamp = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
    bak = backup_root / f"_erpsmart_staging_code_bak_{stamp}"
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
    marker = bak / "BACKUP_OK.json"
    marker.write_text(
        json.dumps({"ok": True, "files": copied, "at": _utc(), "dest": str(dest)}, indent=2) + "\n",
        encoding="utf-8",
    )
    if copied == 0 and not any((dest / r).is_file() for r in rels):
        # Fresh dest with nothing to backup is OK (first deploy).
        return bak
    if not marker.is_file():
        raise DeployError("backup_marker_missing")
    return bak


def restore_backup(bak: Path, dest: Path) -> int:
    restored = 0
    for p in sorted(bak.rglob("*")):
        if not p.is_file() or p.name == "BACKUP_OK.json":
            continue
        rel = p.relative_to(bak).as_posix()
        target = dest / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(p, target)
        restored += 1
    return restored


def local_verify_published_sha(dest: Path, expected_sha: str) -> bool:
    meta = dest / "ERPSMART_STAGING_RELEASE.json"
    if not meta.is_file():
        return False
    data = json.loads(meta.read_text(encoding="utf-8"))
    return data.get("git_sha") == expected_sha


def optional_worker_update(bootstrap: Path, sha: str, dry_run: bool) -> dict:
    """Version-matched worker sync when staging worker dest exists; else PARTIAL."""
    worker_dest = Path(
        os.environ.get("ERPSMART_STAGING_WORKER_DEST", str(Path(DEFAULT_BOOTSTRAP) / "worker"))
    )
    # Worker source is NOT in PHP package; cron bootstrap may keep a mirrored engine tree.
    src = bootstrap / "worker_src"
    if not src.is_dir() or not worker_dest.exists():
        return {
            "status": "PARTIAL",
            "reason": "worker_infra_missing",
            "note": "PHP package deployed; worker update skipped until staging worker path prepared",
            "git_sha": sha,
        }
    sys.path.insert(0, str(ROOT / "scripts"))
    from staging_worker_install import sync_tree, refuse_dest  # noqa: WPS433

    refuse = refuse_dest(worker_dest)
    if refuse:
        return {"status": "PARTIAL", "reason": refuse, "git_sha": sha}
    sync = sync_tree(src, worker_dest, dry_run=dry_run)
    return {"status": "OK" if not dry_run else "DRY_RUN", "sync": sync, "git_sha": sha}


def run_cycle(cfg: dict, *, apply: bool, dry_run: bool, bootstrap: Path) -> dict:
    repo = cfg.get("github_repo") or DEFAULT_REPO
    dest = Path(cfg.get("dest") or CANONICAL_DEST)
    health_url = cfg.get("health_url") or STAGING_URL
    verify_dest_identity(dest)

    state = load_state(bootstrap)
    releases = list_releases(repo)
    rel = pick_latest_valid_release(releases, state)
    if rel is None:
        return {"ok": True, "status": "NO_RELEASE", "action": "noop", "published_sha": state.get("published_sha")}

    tag = rel.get("tag_name") or ""
    assets = {a.get("name"): a for a in (rel.get("assets") or []) if a.get("name")}
    work = bootstrap / "work"
    work.mkdir(parents=True, exist_ok=True)

    # Download public meta first (small) — never execute anything from it.
    meta_name = (
        "ERPSMART_STAGING_RELEASE_PUBLIC.json"
        if "ERPSMART_STAGING_RELEASE_PUBLIC.json" in assets
        else "ERPSMART_STAGING_RELEASE.json"
    )
    meta_path = work / meta_name
    https_download(asset_url(assets, meta_name), meta_path)
    public_meta = json.loads(meta_path.read_text(encoding="utf-8"))
    sha = public_meta.get("git_sha") or ""
    if not sha:
        raise DeployError("release_meta_missing_sha")
    if not gates_ok(public_meta):
        raise DeployError("gates_not_success", json.dumps(public_meta.get("gates")))
    if not release_belongs_to_main(public_meta, tag, sha):
        raise DeployError("release_not_on_main", tag)
    if public_meta.get("target_host") not in {None, STAGING_HOST}:
        raise DeployError("release_wrong_host", str(public_meta.get("target_host")))

    published = state.get("published_sha")
    if published and published == sha:
        return {
            "ok": True,
            "status": "UP_TO_DATE",
            "action": "noop",
            "published_sha": published,
            "release_tag": tag,
        }
    # Reject older / wrong SHA relative to already published tip when we can compare.
    if published and len(published) >= 12 and len(sha) >= 12:
        # Without git on host, reject if release created_at is older than last success stamp
        # and SHAs differ — use release published_at vs state.
        last_at = state.get("last_success_at")
        rel_at = rel.get("published_at") or rel.get("created_at")
        if last_at and rel_at and rel_at < last_at and sha != published:
            raise DeployError("stale_release_rejected", f"{sha}@{rel_at}<{last_at}")

    zip_name, _zip_asset = find_zip_asset(assets)
    expected_zip_sha = (public_meta.get("package_zip_sha256") or "").lower()
    sha_name = zip_name + ".sha256"
    if sha_name in assets:
        sha_path = work / sha_name
        https_download(asset_url(assets, sha_name), sha_path)
        expected_zip_sha = parse_sha256_sidecar(sha_path.read_text(encoding="utf-8"), zip_name)

    if not expected_zip_sha:
        raise DeployError("missing_zip_checksum")

    zip_path = work / zip_name
    https_download(asset_url(assets, zip_name), zip_path)
    got = sha256_file(zip_path)
    if got != expected_zip_sha:
        raise DeployError("zip_checksum_mismatch", f"got={got} expected={expected_zip_sha}")

    extract_dir = work / f"extract_{sha[:12]}"
    extracted = safe_extract_zip(zip_path, extract_dir)
    verify_extracted_manifest(extract_dir, public_meta)

    ops = build_plan(extract_dir, dest)
    to_write = [o.rel for o in ops if o.op in {"copy", "scaffold_only"}]
    report: dict = {
        "ok": True,
        "status": "PLANNED",
        "git_sha": sha,
        "release_tag": tag,
        "extracted_files": len(extracted),
        "ops_copy": sum(1 for o in ops if o.op == "copy"),
        "dry_run": dry_run or not apply,
        "dest": str(dest),
    }

    if dry_run or not apply:
        report["status"] = "DRY_RUN"
        report["worker"] = optional_worker_update(bootstrap, sha, dry_run=True)
        return report

    # --- APPLY PATH ---
    before_cfg = snapshot_config(dest)
    before_storage = snapshot_storage(dest)
    backup_root = Path(cfg.get("backup_root") or (bootstrap / "backups"))
    backup_root.mkdir(parents=True, exist_ok=True)
    try:
        bak = backup_dest(dest, backup_root, to_write)
    except Exception as e:  # noqa: BLE001
        raise DeployError("backup_failed_no_changes", str(e)[:200]) from e

    if not (bak / "BACKUP_OK.json").is_file():
        raise DeployError("backup_failed_no_changes", "marker_missing")

    report["backup_dir"] = str(bak)
    apply_info = apply_plan(extract_dir, dest, ops, backup_root=None)  # backup already done
    report["apply"] = apply_info

    # Preserve checks
    after_cfg = snapshot_config(dest)
    if before_cfg and before_cfg != after_cfg:
        restore_backup(bak, dest)
        raise DeployError("config_mutated_rolled_back")
    storage_cmp = manifest_compare_storage(dest, before_storage)
    if not storage_cmp.get("ok"):
        restore_backup(bak, dest)
        raise DeployError("storage_mutated_rolled_back", json.dumps(storage_cmp)[:300])

    if not local_verify_published_sha(dest, sha):
        restore_backup(bak, dest)
        raise DeployError("published_sha_mismatch_rolled_back")

    # HTTPS health with release identity
    page = probe(health_url)
    rel_probe = probe_release(health_url, expected_sha=sha)
    health_ok = bool(page.get("ok")) and bool(rel_probe.get("ok"))
    report["health"] = {"page": {k: page.get(k) for k in ("ok", "status", "host")}, "release": rel_probe}
    if not health_ok:
        restore_backup(bak, dest)
        raise DeployError("health_failed_rolled_back", json.dumps(report["health"])[:300])

    state["published_sha"] = sha
    state["last_release_tag"] = tag
    state["last_success_at"] = rel.get("published_at") or _utc()
    state.setdefault("history", [])
    state["history"] = ([{"sha": sha, "tag": tag, "at": _utc()}] + state["history"])[:20]
    save_state(bootstrap, state)

    report["status"] = "APPLIED"
    report["published_sha"] = sha
    report["worker"] = optional_worker_update(bootstrap, sha, dry_run=False)
    report["migrations"] = "NOT_RUN"
    return report


def selftest(work: Path) -> dict:
    """Adversarial / unit selftest on disposable fixtures (no network, no real host)."""
    checks: dict[str, bool] = {}
    if work.exists():
        shutil.rmtree(work)
    work.mkdir(parents=True)
    bootstrap = work / "bootstrap"
    dest = work / "dest"
    (bootstrap / "logs").mkdir(parents=True)
    (bootstrap / "state").mkdir(parents=True)
    (bootstrap / "locks").mkdir(parents=True)
    (bootstrap / "backups").mkdir(parents=True)
    (bootstrap / "work").mkdir(parents=True)

    # Identity refuse production
    prod = work / "excel.bcsrp"
    prod.mkdir(parents=True)
    (prod / IDENTITY_MARKER).write_text("excel.bcsrp:/home3/zzflgmfd/excel.bcsrp/\n", encoding="utf-8")
    try:
        os.environ["ERPSMART_CRON_ALLOW_FIXTURE"] = "1"
        verify_dest_identity(prod)
        checks["refuse_prod_identity"] = False
    except DeployError as e:
        checks["refuse_prod_identity"] = e.code in {
            "refused_production_web_root",
            "refused_production_dest",
            "refused_production_path_fragment",
            "identity_marker_failed",
            "refused_non_canonical_dest",
        }

    # Good staging fixture dest
    dest.mkdir(parents=True)
    (dest / IDENTITY_MARKER).write_text(EXPECTED_MARKER + "\n", encoding="utf-8")
    (dest / "app").mkdir(parents=True)
    (dest / "app" / "config.php").write_text("SECRET_KEEP\n", encoding="utf-8")
    (dest / "storage" / "uploads").mkdir(parents=True)
    (dest / "storage" / "uploads" / "u.bin").write_text("UPLOAD\n", encoding="utf-8")
    (dest / "index.php").write_text("OLD\n", encoding="utf-8")

    # ZIP slip refusal
    bad_zip = work / "bad.zip"
    with zipfile.ZipFile(bad_zip, "w") as zf:
        zf.writestr("../escape.php", "x")
    try:
        safe_extract_zip(bad_zip, work / "extract_bad")
        checks["zip_slip"] = False
    except DeployError as e:
        checks["zip_slip"] = e.code == "zip_slip_refused"

    # Good package zip + apply preserves secrets
    pkg = work / "pkg"
    pkg.mkdir()
    (pkg / "index.php").write_text("NEW\n", encoding="utf-8")
    meta = {
        "git_sha": "abc123deadbeefcafebabe0123456789abcdef01",
        "file_manifest": ["index.php", "ERPSMART_STAGING_RELEASE.json"],
        "files_sha256": {"index.php": sha256_file(pkg / "index.php")},
    }
    (pkg / "ERPSMART_STAGING_RELEASE.json").write_text(json.dumps(meta, indent=2) + "\n", encoding="utf-8")

    good_zip = work / "good.zip"
    with zipfile.ZipFile(good_zip, "w") as zf:
        for p in pkg.rglob("*"):
            if p.is_file():
                zf.write(p, arcname=p.relative_to(pkg).as_posix())
    extract = work / "extract_good"
    safe_extract_zip(good_zip, extract)
    verify_extracted_manifest(extract, {"git_sha": meta["git_sha"]})

    ops = build_plan(extract, dest)
    bak = backup_dest(dest, bootstrap / "backups", [o.rel for o in ops if o.op in {"copy", "scaffold_only"}])
    apply_plan(extract, dest, ops, backup_root=None)
    checks["config_preserved"] = (dest / "app" / "config.php").read_text(encoding="utf-8") == "SECRET_KEEP\n"
    checks["upload_preserved"] = (dest / "storage" / "uploads" / "u.bin").read_text(encoding="utf-8") == "UPLOAD\n"
    checks["index_updated"] = (dest / "index.php").read_text(encoding="utf-8") == "NEW\n"
    checks["backup_ok"] = (bak / "BACKUP_OK.json").is_file()

    # Concurrent lock
    lock1 = DeployLock(bootstrap)
    lock1.acquire()
    try:
        DeployLock(bootstrap).acquire()
        checks["concurrent_lock"] = False
    except DeployError as e:
        checks["concurrent_lock"] = e.code == "concurrent_lock"
    finally:
        lock1.release()

    # Checksum mismatch detection
    try:
        parse_sha256_sidecar("deadbeef  good.zip\n", "good.zip")
        checks["bad_checksum_format"] = False
    except DeployError:
        checks["bad_checksum_format"] = True
    good_digest = sha256_file(good_zip)
    parsed = parse_sha256_sidecar(f"{good_digest}  good.zip\n", "good.zip")
    checks["checksum_parse"] = parsed == good_digest

    # Stale / gates helpers
    checks["gates_reject"] = not gates_ok({"gates": {"release-gate": "failure", "rc1-mysql-php": "success"}})
    checks["gates_accept"] = gates_ok({"gates": {"release-gate": "success", "rc1-mysql-php": "success"}})
    checks["main_tag_ok"] = release_belongs_to_main(
        {"branch_required": "main"}, f"{RELEASE_TAG_PREFIX}{meta['git_sha']}", meta["git_sha"]
    )

    # Rollback path
    (dest / "index.php").write_text("BROKEN\n", encoding="utf-8")
    restore_backup(bak, dest)
    checks["rollback_restores"] = (dest / "index.php").read_text(encoding="utf-8") == "OLD\n" or (
        dest / "index.php"
    ).read_text(encoding="utf-8") in {"NEW\n", "OLD\n"}
    # After apply, backup had NEW? Actually backup was taken before apply from OLD.
    # restore should bring OLD back if bak was pre-apply — we backed up before apply so index was OLD.
    # But we already applied NEW, then wrote BROKEN, then restore → OLD.
    checks["rollback_restores"] = (dest / "index.php").read_text(encoding="utf-8") == "OLD\n"

    ok = all(checks.values())
    return {"ok": ok, "checks": checks}


def main() -> int:
    ap = argparse.ArgumentParser(description="Staging cron pull deploy (excel2 only, CLI only)")
    ap.add_argument("--bootstrap", type=Path, default=Path(os.environ.get("ERPSMART_DEPLOY_BOOTSTRAP", DEFAULT_BOOTSTRAP)))
    ap.add_argument("--config", type=Path, default=None, help="Defaults to <bootstrap>/config.json")
    ap.add_argument("--apply", action="store_true", help="Mutate staging dest after validation")
    ap.add_argument("--dry-run", action="store_true", help="Plan only (default when --apply omitted)")
    ap.add_argument("--selftest", action="store_true")
    ap.add_argument("--selftest-work", type=Path, default=ROOT / "storage" / "cache" / "staging_cron_selftest")
    ap.add_argument("--print-cron-line", action="store_true")
    ap.add_argument("--i-understand-staging-risk", action="store_true")
    args = ap.parse_args()

    if args.selftest:
        result = selftest(args.selftest_work)
        print(json.dumps(result, indent=2))
        return 0 if result["ok"] else 1

    if args.print_cron_line:
        boot = args.bootstrap
        # cPanel jailshell: Python3 unavailable — primary host runtime is PHP CLI.
        php = "/usr/bin/php"
        line = (
            f"*/5 * * * * cd {boot} && {php} {boot}/bin/staging_cron_deploy.php "
            f"--bootstrap {boot} --config {boot}/config.json --apply "
            f"--i-understand-staging-risk >> {boot}/logs/{LOG_NAME} 2>&1"
        )
        print(line)
        print(
            "# Note: staging_cron_deploy.py is parity/CI only; "
            "host cron must use staging_cron_deploy.php",
            file=sys.stderr,
        )
        return 0

    bootstrap = args.bootstrap
    config_path = args.config or (bootstrap / "config.json")
    if not config_path.is_file():
        print(json.dumps({"ok": False, "error": "config_missing", "path": str(config_path)}, indent=2))
        return 2

    cfg = load_config(config_path)
    if not cfg.get("enabled", False) and args.apply:
        print(json.dumps({"ok": True, "status": "DISABLED", "note": "set enabled=true in config.json"}, indent=2))
        return 0

    apply = bool(args.apply) and not args.dry_run
    if apply and not args.i_understand_staging_risk:
        print(json.dumps({"ok": False, "error": "apply_requires_ack"}, indent=2))
        return 3

    try:
        with DeployLock(bootstrap):
            report = run_cycle(cfg, apply=apply, dry_run=args.dry_run or not apply, bootstrap=bootstrap)
    except DeployError as e:
        report = {"ok": False, "status": "REFUSED", "error": e.code, "detail": e.detail}
        log(bootstrap, f"ERROR {e.code} {e.detail}")
        print(json.dumps(report, indent=2))
        # Clean exits for expected no-op class errors
        if e.code in {"concurrent_lock"}:
            return 0
        return 1
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as e:
        report = {"ok": False, "status": "NETWORK_OR_PARSE", "error": type(e).__name__, "detail": str(e)[:200]}
        log(bootstrap, f"ERROR {report}")
        print(json.dumps(report, indent=2))
        return 1

    log(bootstrap, f"OK {report.get('status')} sha={report.get('git_sha') or report.get('published_sha')}")
    print(json.dumps(report, indent=2))
    return 0 if report.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
