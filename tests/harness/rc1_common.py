"""RC1 acceptance shared helpers — sandbox identity + Docker PHP runners.

T7-owned. Never mutates business domains. SKIP under RC1_REQUIRED is a gate failure.
"""
from __future__ import annotations

import json
import os
import pathlib
import subprocess
import time
from typing import Any

ROOT = pathlib.Path(__file__).resolve().parents[2]
EVIDENCE_DIR = ROOT / "docs" / "ai" / "rc1" / "evidence"

# Verified disposable sandbox (G0 / shared contracts §9)
EXPECTED_CONTAINER_NAME = "erpsmart-p2p-mysql-test"
EXPECTED_CONTAINER_ID_PREFIX = "02eb215a87f6a726cf4efb1546f4b09865c9eb6eec9de1e9dc0e6b3163672db6"
PHP_IMAGE = "erpsmart-o2c-php:8.2"
HOST = os.environ.get("O2C_DB_HOST", "host.docker.internal")
PORT = os.environ.get("O2C_DB_PORT", "3307")
USER = os.environ.get("O2C_DB_USER", "p2p")
PASSWORD = os.environ.get("O2C_DB_PASS", "p2p_test")
# Prefer isolated RC1 schema to avoid cross-lane races on shared erpsmart_p2p_test.
# Same disposable container; schema must still contain "test"/"e2e".
DATABASE = os.environ.get("O2C_DB_NAME", "erpsmart_rc1_t7_test")


class Rc1Blocked(Exception):
    """Feature/provenance missing — not a synthetic PASS."""


class Rc1DependencyMissing(Exception):
    """Mandatory PHP/MySQL/Docker dependency unavailable under RC1_REQUIRED."""


def rc1_required() -> bool:
    return os.environ.get("RC1_REQUIRED", "").strip() in ("1", "true", "TRUE", "yes")


def evidence_path(name: str) -> pathlib.Path:
    EVIDENCE_DIR.mkdir(parents=True, exist_ok=True)
    return EVIDENCE_DIR / name


def write_json(name: str, payload: dict[str, Any]) -> pathlib.Path:
    path = evidence_path(name)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    return path


def docker_image_ok(image: str = PHP_IMAGE) -> bool:
    try:
        r = subprocess.run(
            ["docker", "image", "inspect", image],
            capture_output=True,
            text=True,
            timeout=20,
        )
        return r.returncode == 0
    except Exception:
        return False


def sandbox_relax() -> bool:
    """CI/service MySQL may not match the G0 named container; never relax for prod names."""
    return os.environ.get("RC1_SANDBOX_RELAX", "").strip() in ("1", "true", "TRUE", "yes")


def verify_sandbox(*, required: bool | None = None) -> dict[str, Any]:
    """Confirm disposable MySQL container identity before any destructive work."""
    _ = rc1_required() if required is None else required
    relax = sandbox_relax()
    info: dict[str, Any] = {
        "expected_name": EXPECTED_CONTAINER_NAME,
        "expected_id_prefix": EXPECTED_CONTAINER_ID_PREFIX,
        "database": DATABASE,
        "port": PORT,
        "php_image": PHP_IMAGE,
        "relax": relax,
        "verified_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }

    if "test" not in DATABASE.lower() and "e2e" not in DATABASE.lower():
        raise Rc1DependencyMissing(f"refusing_non_test_database:{DATABASE}")

    if not relax:
        try:
            r = subprocess.run(
                [
                    "docker",
                    "inspect",
                    EXPECTED_CONTAINER_NAME,
                    "--format",
                    "{{.Id}} {{.State.Status}} {{.Config.Image}}",
                ],
                capture_output=True,
                text=True,
                timeout=20,
            )
        except Exception as exc:  # noqa: BLE001
            raise Rc1DependencyMissing(f"docker_inspect_failed:{exc}") from exc

        if r.returncode != 0:
            raise Rc1DependencyMissing(f"container_missing:{r.stderr.strip()}")

        parts = (r.stdout or "").strip().split()
        if len(parts) < 2:
            raise Rc1DependencyMissing(f"unexpected_inspect:{r.stdout!r}")
        cid, status = parts[0], parts[1]
        image = parts[2] if len(parts) > 2 else ""
        info["container_id"] = cid
        info["status"] = status
        info["image"] = image

        if cid[:12] != EXPECTED_CONTAINER_ID_PREFIX[:12]:
            raise Rc1DependencyMissing(
                f"container_id_mismatch got={cid[:12]} expected_prefix={EXPECTED_CONTAINER_ID_PREFIX[:12]}"
            )
        if status != "running":
            raise Rc1DependencyMissing(f"container_not_running:{status}")
    else:
        info["container_id"] = "RELAXED_CI_SERVICE"
        info["status"] = "assumed_service"
        info["note"] = (
            "RC1_SANDBOX_RELAX=1: container ID check skipped; "
            "operator-verified G0 sandbox remains erpsmart-p2p-mysql-test / 02eb215a87f6…"
        )

    if not docker_image_ok():
        raise Rc1DependencyMissing(f"php_image_missing:{PHP_IMAGE}")

    try:
        import pymysql  # type: ignore
    except ImportError as exc:
        raise Rc1DependencyMissing("pymysql_missing") from exc

    try:
        pymysql.connect(
            host="127.0.0.1",
            port=int(PORT),
            user=USER,
            password=PASSWORD,
            database=DATABASE,
            charset="utf8mb4",
            connect_timeout=5,
        ).close()
    except Exception as exc:  # noqa: BLE001
        raise Rc1DependencyMissing(f"mysql_connect_failed:{exc}") from exc

    info["ok"] = True
    info["DATABASE_TESTED"] = True
    return info


def php_runner(runner_rel: str, scenario: str = "run", args: dict | None = None, timeout: int = 300) -> dict[str, Any]:
    if not docker_image_ok():
        raise Rc1DependencyMissing(f"php_image_missing:{PHP_IMAGE}")
    payload = json.dumps(args or {}, ensure_ascii=False)
    # Docker Desktop on Windows needs forward-slash bind mounts
    mount = str(ROOT).replace("\\", "/")
    cmd = [
        "docker",
        "run",
        "--rm",
        "--add-host=host.docker.internal:host-gateway",
        "-e",
        f"O2C_DB_HOST={HOST}",
        "-e",
        f"O2C_DB_PORT={PORT}",
        "-e",
        f"O2C_DB_USER={USER}",
        "-e",
        f"O2C_DB_PASS={PASSWORD}",
        "-e",
        f"O2C_DB_NAME={DATABASE}",
        "-v",
        f"{mount}:/app",
        "-w",
        "/app",
        PHP_IMAGE,
        "php",
        runner_rel,
        scenario,
        payload,
    ]
    completed = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    out = (completed.stdout or "").strip().splitlines()
    if not out:
        raise AssertionError(
            f"no_stdout runner={runner_rel} scenario={scenario} rc={completed.returncode} stderr={completed.stderr}"
        )
    try:
        data = json.loads(out[-1])
    except json.JSONDecodeError as exc:
        raise AssertionError(f"bad_json:{out[-1]!r} stderr={completed.stderr}") from exc
    if completed.returncode not in (0, None) and not data.get("ok"):
        # Prefer domain JSON error over opaque docker rc
        return data
    return data


def source_method_exists(php_class_file: str, method: str) -> bool:
    path = ROOT / php_class_file
    if not path.is_file():
        return False
    text = path.read_text(encoding="utf-8")
    return f"function {method}(" in text
