"""Disposable-only tests for scripts/rc1_staging_schema_migrate.php safety gates.

Never targets remote staging or production. Requires local disposable MySQL.
Run: RC1_MYSQL_CONTAINER_ID_PREFIX=<live> python -m unittest tests.rc1.test_rc1_staging_migrate_executor -v
"""
from __future__ import annotations

import hashlib
import json
import os
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "tests" / "harness"))

from rc1_common import (  # noqa: E402
    EXPECTED_CONTAINER_ID_PREFIX,
    PASSWORD,
    PHP_IMAGE,
    PORT,
    USER,
    _php_db_host,
    _runner_db_host,
    docker_image_ok,
    verify_sandbox,
    write_json,
)

SCRIPT = ROOT / "scripts" / "rc1_staging_schema_migrate.php"
DB_NAME = "erpsmart_rc1_staging_executor_test"


def _php_available() -> str | None:
    if docker_image_ok(PHP_IMAGE):
        return "docker"
    try:
        r = subprocess.run(["php", "-v"], capture_output=True, text=True, timeout=10)
        if r.returncode == 0:
            return "host"
    except Exception:
        return None
    return None


def _run_php(env: dict[str, str], extra_args: list[str] | None = None) -> subprocess.CompletedProcess[str]:
    mode = _php_available()
    if mode is None:
        raise unittest.SkipTest("php_unavailable")
    args = extra_args or []
    if mode == "docker":
        docker_env: list[str] = []
        for k, v in env.items():
            docker_env.extend(["-e", f"{k}={v}"])
        cmd = [
            "docker",
            "run",
            "--rm",
            *docker_env,
            "-v",
            f"{str(ROOT).replace(chr(92), '/')}:/app",
            "-w",
            "/app",
            "--add-host=host.docker.internal:host-gateway",
            PHP_IMAGE,
            "php",
            "scripts/rc1_staging_schema_migrate.php",
            *args,
        ]
        return subprocess.run(cmd, capture_output=True, text=True, timeout=300)
    cmd = ["php", str(SCRIPT), *args]
    merged = {**os.environ, **env}
    return subprocess.run(cmd, capture_output=True, text=True, cwd=str(ROOT), env=merged, timeout=300)


def _base_env(*, dry_run: bool = False) -> dict[str, str]:
    mode = _php_available()
    php_host = _php_db_host() if mode == "docker" else _runner_db_host()
    bak_dir = ROOT / "storage" / "cache" / "rc1_staging_executor_test"
    bak_dir.mkdir(parents=True, exist_ok=True)
    bak_path = bak_dir / "pre_migrate_fixture.sql"
    payload = b"-- disposable staging executor pre-migrate backup fixture\nSELECT 1;\n"
    bak_path.write_bytes(payload)
    sha = hashlib.sha256(payload).hexdigest()
    bak_for_php = (
        "/app/storage/cache/rc1_staging_executor_test/pre_migrate_fixture.sql"
        if mode == "docker"
        else str(bak_path)
    )
    cache_for_php = (
        "/app/storage/cache/rc1_staging_executor_test"
        if mode == "docker"
        else str(bak_dir)
    )
    env = {
        "RC1_STAGING_EXECUTOR_TEST": "1",
        "RC1_STAGING_TARGET_MODE": "disposable_sim",
        "RC1_STAGING_OPERATOR_AUTH": "test-operator-token",
        "RC1_STAGING_AUTH_EXPECTED": "test-operator-token",
        "RC1_STAGING_ENV_IDENTITY": "disposable-staging-sim-lab",
        "RC1_STAGING_PREFLIGHT_REVIEWED": "1",
        "RC1_STAGING_DB_HOST": php_host,
        "RC1_STAGING_DB_PORT": str(PORT),
        "RC1_STAGING_DB_USER": USER,
        "RC1_STAGING_DB_PASS": PASSWORD,
        "RC1_STAGING_DB_NAME": DB_NAME,
        "RC1_PROD_DB_NAME": "zzflgmfd_excel2_PRODUCTION_DECLARED",
        "RC1_PROD_DB_HOST": "prod.example.invalid",
        "RC1_PRE_MIGRATE_BACKUP_PATH": bak_for_php,
        "RC1_PRE_MIGRATE_BACKUP_SHA256": sha,
        "RC1_STAGING_CACHE_DIR": cache_for_php,
    }
    if dry_run:
        env["RC1_STAGING_DRY_RUN"] = "1"
    return env


def _parse_json(stdout: str) -> dict:
    idx = stdout.find("{")
    if idx < 0:
        raise AssertionError(f"no_json_in_stdout:{stdout[-2000:]}")
    return json.loads(stdout[idx:])


class Rc1StagingMigrateExecutor(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        if _php_available() is None:
            raise unittest.SkipTest("php_unavailable")
        os.environ.setdefault("RC1_MYSQL_CONTAINER_ID_PREFIX", EXPECTED_CONTAINER_ID_PREFIX)
        # Point sandbox verify at disposable executor DB (p2p may lack CREATE on new names).
        os.environ["O2C_DB_NAME"] = DB_NAME
        try:
            cls.sandbox = verify_sandbox(required=False)
        except Exception:
            try:
                r = subprocess.run(
                    ["docker", "inspect", "erpsmart-p2p-mysql-test", "--format", "{{.Id}}"],
                    capture_output=True,
                    text=True,
                    timeout=20,
                )
                if r.returncode != 0 or not r.stdout.strip():
                    raise unittest.SkipTest("sandbox_unavailable")
                os.environ["RC1_MYSQL_CONTAINER_ID_PREFIX"] = r.stdout.strip()
                cls.sandbox = verify_sandbox(required=False)
            except unittest.SkipTest:
                raise
            except Exception as exc:  # noqa: BLE001
                raise unittest.SkipTest(f"sandbox_unavailable:{exc}") from exc

        import pymysql

        conn = pymysql.connect(
            host=_runner_db_host(),
            port=int(PORT),
            user=USER,
            password=PASSWORD,
            charset="utf8mb4",
            connect_timeout=5,
        )
        try:
            with conn.cursor() as cur:
                cur.execute(
                    f"CREATE DATABASE IF NOT EXISTS `{DB_NAME}` "
                    "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                )
            conn.commit()
        finally:
            conn.close()

    def test_01_refuse_missing_auth(self) -> None:
        env = _base_env(dry_run=True)
        del env["RC1_STAGING_OPERATOR_AUTH"]
        r = _run_php(env, ["--dry-run"])
        self.assertEqual(r.returncode, 3)
        blob = (r.stderr + r.stdout).lower()
        self.assertTrue("authorization" in blob or "missing" in blob or "identity" in blob)

    def test_02_refuse_prod_like_db(self) -> None:
        env = _base_env(dry_run=True)
        env["RC1_STAGING_DB_NAME"] = "zzflgmfd_excel2"
        r = _run_php(env, ["--dry-run"])
        self.assertEqual(r.returncode, 3)

    def test_03_refuse_staging_equals_prod_identity(self) -> None:
        env = _base_env(dry_run=True)
        env["RC1_PROD_DB_NAME"] = DB_NAME
        r = _run_php(env, ["--dry-run"])
        self.assertEqual(r.returncode, 3)

    def test_04_dry_run_preflight_ok(self) -> None:
        env = _base_env(dry_run=True)
        r = _run_php(env, ["--dry-run"])
        data = _parse_json(r.stdout)
        write_json("staging_executor_dry_run.json", data)
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertTrue(data.get("ok"))
        self.assertTrue(data.get("dry_run"))
        lock = ROOT / "storage" / "maintenance" / "rc1_offline_migrate.lock"
        self.assertTrue(lock.is_file())
        rel = _run_php(env, ["--release-maintenance"])
        self.assertEqual(rel.returncode, 0, rel.stderr)
        self.assertFalse(lock.is_file())

    def test_05_migrate_x2_disposable(self) -> None:
        env = _base_env(dry_run=False)
        r = _run_php(env)
        try:
            data = _parse_json(r.stdout)
        except AssertionError:
            write_json(
                "staging_executor_migrate_raw.json",
                {"stdout": r.stdout[-4000:], "stderr": r.stderr[-4000:], "code": r.returncode},
            )
            raise
        write_json("staging_executor_migrate.json", data)
        self.assertEqual(r.returncode, 0, r.stderr[-2000:])
        self.assertTrue(data.get("ok"))
        self.assertTrue(data.get("idempotent"))
        self.assertTrue(data.get("schema_complete"))
        self.assertFalse(data.get("install_php_used"))
        self.assertFalse(data.get("docker_required"))
        self.assertFalse(data.get("prod_cache_cleared"))
        lock = ROOT / "storage" / "maintenance" / "rc1_offline_migrate.lock"
        self.assertFalse(lock.is_file())


if __name__ == "__main__":
    unittest.main()
