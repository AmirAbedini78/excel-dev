#!/usr/bin/env python3
"""Hash a staging package directory or ZIP for RC1 release auditing.

No network. Skips secret-like paths and extensions per RC1-STAGING-PACKAGE-INTEGRITY.md.
"""
from __future__ import annotations

import argparse
import hashlib
import re
import sys
import zipfile
from pathlib import Path

SKIP_DIR_NAMES = {
    ".git",
    "__pycache__",
    ".venv",
    "node_modules",
    "runtime_cache",
    "rc1_legacy_rehearsal",
}

SKIP_FILE_PATTERNS = [
    re.compile(r"^app/config\.php$"),
    re.compile(r"\.env(\..+)?$"),
    re.compile(r"engine/\.env\."),
    re.compile(r"engine/config\.(json|cloud\.json|gemini\.json)$"),
    re.compile(r"\.sql(\.gz)?$"),
    re.compile(r"\.(pem|key|pfx)$"),
    re.compile(r"_tmp_.*\.(html|txt)$"),
    re.compile(r"_cookies\d*\.txt$"),
    re.compile(r"\.pyc$"),
    re.compile(r"\.cache(\.lock)?$"),
]


def rel_posix(path: Path, root: Path) -> str:
    return path.relative_to(root).as_posix()


def should_skip(rel: str) -> bool:
    parts = Path(rel).parts
    if any(part in SKIP_DIR_NAMES for part in parts):
        return True
    for pat in SKIP_FILE_PATTERNS:
        if pat.search(rel.replace("\\", "/")):
            return True
    return False


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def manifest_lines(root: Path) -> list[str]:
    files = []
    for path in sorted(root.rglob("*")):
        if not path.is_file():
            continue
        rel = rel_posix(path, root)
        if should_skip(rel):
            continue
        files.append(f"{rel}\t{sha256_file(path)}")
    return files


def hash_directory(root: Path) -> dict:
    root = root.resolve()
    if not root.is_dir():
        raise SystemExit(f"not_a_directory:{root}")
    lines = manifest_lines(root)
    body = "\n".join(lines) + ("\n" if lines else "")
    manifest_digest = hashlib.sha256(body.encode("utf-8")).hexdigest()
    total_bytes = sum(
        p.stat().st_size
        for p in root.rglob("*")
        if p.is_file() and not should_skip(rel_posix(p, root))
    )
    return {
        "kind": "directory",
        "package_root": str(root),
        "file_count": len(lines),
        "total_bytes": total_bytes,
        "manifest_sha256": manifest_digest,
        "archive_sha256": None,
    }


def hash_zip(archive: Path) -> dict:
    archive = archive.resolve()
    if not archive.is_file():
        raise SystemExit(f"not_a_file:{archive}")
    archive_digest = sha256_file(archive)
    extract_root = archive.parent / f".{archive.stem}_hash_extract"
    if extract_root.exists():
        raise SystemExit(f"refusing_existing_extract_dir:{extract_root}")
    try:
        extract_root.mkdir()
        with zipfile.ZipFile(archive) as zf:
            zf.extractall(extract_root)
        inner = hash_directory(extract_root)
        inner["kind"] = "zip"
        inner["archive_path"] = str(archive)
        inner["archive_sha256"] = archive_digest
        inner["extracted_to"] = str(extract_root)
        return inner
    finally:
        import shutil

        shutil.rmtree(extract_root, ignore_errors=True)


def main() -> int:
    parser = argparse.ArgumentParser(description="RC1 staging package hash (offline)")
    parser.add_argument(
        "path",
        nargs="?",
        help="ZIP archive or directory to hash",
    )
    parser.add_argument(
        "--root",
        type=Path,
        help="Directory root (alternative to positional path)",
    )
    args = parser.parse_args()
    target = args.root or (Path(args.path) if args.path else None)
    if target is None:
        parser.error("provide path or --root")

    target = target.resolve()
    if target.suffix.lower() == ".zip" and target.is_file():
        result = hash_zip(target)
    else:
        result = hash_directory(target)

    import json

    print(json.dumps(result, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())