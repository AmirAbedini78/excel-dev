#!/usr/bin/env python3
"""Commercial MVP release gate; only standard runtimes are required."""
from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path


TEXT_SUFFIXES = {".py", ".php", ".js", ".json", ".md", ".yml", ".yaml", ".ps1"}
SKIP_PARTS = {".git", "__pycache__", "sample_import", "upload"}
RUNTIME_SECRET_SCAN_EXCLUDES = {Path("engine/config.json"), Path("engine/config.cloud.json")}
SECRET_PATTERNS = {
    "worker_token": re.compile(r"aiw_[A-Fa-f0-9]{24,}"),
    "private_key": re.compile(r"BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY"),
}


def files(root: Path, suffixes: set[str]):
    for path in root.rglob("*"):
        if path.is_file() and path.suffix.lower() in suffixes and not any(x in SKIP_PARTS for x in path.parts):
            yield path


def run(cmd: list[str], cwd: Path, env: dict[str, str] | None = None) -> None:
    print("+", " ".join(cmd), flush=True)
    completed = subprocess.run(cmd, cwd=cwd, env=env, text=True)
    if completed.returncode:
        raise RuntimeError(f"command_failed:{completed.returncode}:{cmd[0]}")


def python_syntax(root: Path) -> int:
    count = 0
    for path in files(root, {".py"}):
        compile(path.read_text(encoding="utf-8"), str(path), "exec")
        count += 1
    return count


def json_syntax(root: Path) -> int:
    count = 0
    for path in files(root, {".json"}):
        json.loads(path.read_text(encoding="utf-8"))
        count += 1
    return count


def secret_scan(root: Path) -> int:
    count = 0
    for path in files(root, TEXT_SUFFIXES):
        if path.relative_to(root) in RUNTIME_SECRET_SCAN_EXCLUDES:
            continue
        text = path.read_text(encoding="utf-8", errors="ignore")
        for name, pattern in SECRET_PATTERNS.items():
            if pattern.search(text):
                raise RuntimeError(f"secret_pattern:{name}:{path.relative_to(root)}")
        count += 1
    return count


def php_lint(root: Path, required: bool) -> tuple[int, str]:
    php = shutil.which("php")
    if not php:
        if required:
            raise RuntimeError("php_cli_required_but_missing")
        return 0, "SKIPPED (php CLI unavailable; CI/cPanel lint is still mandatory)"
    count = 0
    for path in files(root, {".php"}):
        run([php, "-l", str(path)], root)
        count += 1
    behavior_test = root / "tests/php_live_observability_test.php"
    if behavior_test.is_file():
        run([php, "-n", str(behavior_test)], root)
    return count, "PASS"


def javascript_syntax(root: Path, required: bool) -> tuple[int, str]:
    node = shutil.which("node")
    if not node:
        if required:
            raise RuntimeError("node_runtime_required_but_missing")
        return 0, "SKIPPED (Node unavailable; CI/release JavaScript syntax check is still mandatory)"
    count = 0
    for path in files(root, {".js"}):
        run([node, "--check", str(path)], root)
        count += 1
    return count, "PASS"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", default=str(Path(__file__).resolve().parents[1]))
    parser.add_argument("--require-php", action="store_true")
    parser.add_argument("--require-node", action="store_true")
    args = parser.parse_args()
    root = Path(args.root).resolve()
    if not (root / "engine/worker.py").is_file():
        raise SystemExit("release root is invalid")

    print("ERPSMART AI v9.3 — Commercial MVP Closeout Gate")
    py_count = python_syntax(root)
    print(f"[1/6] Python syntax: PASS ({py_count} files)")
    json_count = json_syntax(root)
    print(f"[2/6] JSON syntax: PASS ({json_count} files)")
    scanned = secret_scan(root)
    print(f"[3/6] Secret scan: PASS ({scanned} text files)")

    env = dict(os.environ)
    env["PYTHONDONTWRITEBYTECODE"] = "1"
    run([sys.executable, "-m", "unittest", "discover", "-s", "tests", "-p", "test_*.py", "-v"], root, env)
    print("[4/6] Regression + contract suite: PASS")

    php_count, php_state = php_lint(root, args.require_php)
    print(f"[5/6] PHP lint: {php_state}" + (f" ({php_count} files)" if php_count else ""))
    js_count, js_state = javascript_syntax(root, args.require_node)
    print(f"[6/6] JavaScript syntax: {js_state}" + (f" ({js_count} files)" if js_count else ""))
    print("ALL V9.3 COMMERCIAL MVP CLOSEOUT GATES PASSED.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
