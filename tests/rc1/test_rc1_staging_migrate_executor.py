"""Disposable-only tests for scripts/rc1_staging_schema_migrate.php safety gates.

Covers: required AUTH_EXPECTED, release-maintenance parity, web cache sync,
first-HTTP no DDL re-run, restorable backup integrity, failed-validation block,
failed-migrate diagnosable state. Never targets remote staging or production.
"""
from __future__ import annotations

import hashlib
import json
import os
import subprocess
import sys
import time
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
PROBE = ROOT / "tests" / "harness" / "rc1_staging_first_http_probe.php"
DB_NAME = "erpsmart_rc1_staging_executor_test"
BACKUP_DB = "erpsmart_rc1_staging_backup_test"
WEB_CACHE = ROOT / "storage" / "cache"
MAINT_LOCK = ROOT / "storage" / "maintenance" / "rc1_offline_migrate.lock"
FAIL_MARKER = ROOT / "storage" / "maintenance" / "rc1_offline_migrate.FAILED.json"


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


def _path_for_php(path: Path) -> str:
    mode = _php_available()
    if mode == "docker":
        rel = path.resolve().relative_to(ROOT).as_posix()
        return f"/app/{rel}"
    return str(path)


def _minimal_sql_backup(marker: str = "rc1_staging_safety") -> bytes:
    """Valid-looking SQL dump larger than placeholder threshold (>=256 bytes)."""
    body = (
        "-- MySQL dump (disposable staging safety fixture)\n"
        f"-- marker:{marker}\n"
        "SET NAMES utf8mb4;\n"
        "CREATE TABLE IF NOT EXISTS `rc1_backup_probe` (\n"
        "  `id` INT NOT NULL,\n"
        "  `label` VARCHAR(64) NOT NULL,\n"
        "  PRIMARY KEY (`id`)\n"
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
        "INSERT INTO `rc1_backup_probe` (`id`,`label`) VALUES (1,'pre_migrate_invariant');\n"
        "INSERT INTO `rc1_backup_probe` (`id`,`label`) VALUES (2,'row_level_check');\n"
    )
    # Pad to clear min-bytes gate without pretending to be a 68-byte stub.
    pad = "-- " + ("x" * max(0, 280 - len(body))) + "\n"
    return (body + pad).encode("utf-8")


def _write_backup(path: Path, payload: bytes | None = None) -> tuple[Path, str, int]:
    path.parent.mkdir(parents=True, exist_ok=True)
    data = payload if payload is not None else _minimal_sql_backup()
    path.write_bytes(data)
    return path, hashlib.sha256(data).hexdigest(), len(data)


def _relax_docker_written_perms() -> None:
    """Docker PHP often writes bind-mounted cache as root; GHA runner cannot stat it."""
    if _php_available() != "docker":
        return
    mount = str(ROOT).replace("\\", "/")
    subprocess.run(
        [
            "docker",
            "run",
            "--rm",
            "-v",
            f"{mount}:/app",
            "-w",
            "/app",
            PHP_IMAGE,
            "bash",
            "-lc",
            "chmod -R a+rwX /app/storage/cache /app/storage/maintenance 2>/dev/null || true",
        ],
        capture_output=True,
        text=True,
        timeout=60,
    )


def _run_php(
    env: dict[str, str],
    extra_args: list[str] | None = None,
    script: Path = SCRIPT,
) -> subprocess.CompletedProcess[str]:
    mode = _php_available()
    if mode is None:
        raise unittest.SkipTest("php_unavailable")
    args = extra_args or []
    rel = script.resolve().relative_to(ROOT).as_posix()
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
            rel,
            *args,
        ]
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=420)
        _relax_docker_written_perms()
        return result
    cmd = ["php", str(script), *args]
    merged = {**os.environ, **env}
    return subprocess.run(cmd, capture_output=True, text=True, cwd=str(ROOT), env=merged, timeout=420)


def _base_env(*, dry_run: bool = False, db_name: str = DB_NAME) -> dict[str, str]:
    mode = _php_available()
    php_host = _php_db_host() if mode == "docker" else _runner_db_host()
    bak_path = WEB_CACHE / "rc1_staging_executor_test" / "pre_migrate_fixture.sql"
    _, sha, _ = _write_backup(bak_path)
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
        "RC1_STAGING_DB_NAME": db_name,
        "RC1_PROD_DB_NAME": "zzflgmfd_excel2_PRODUCTION_DECLARED",
        "RC1_PROD_DB_HOST": "prod.example.invalid",
        "RC1_PRE_MIGRATE_BACKUP_PATH": _path_for_php(bak_path),
        "RC1_PRE_MIGRATE_BACKUP_SHA256": sha,
        # Same root as app/bootstrap.php RuntimeCache::boot — web gate sync.
        "RC1_STAGING_CACHE_DIR": _path_for_php(WEB_CACHE),
    }
    if dry_run:
        env["RC1_STAGING_DRY_RUN"] = "1"
    return env


def _parse_json(stdout: str) -> dict:
    idx = stdout.find("{")
    if idx < 0:
        raise AssertionError(f"no_json_in_stdout:{stdout[-2000:]}")
    return json.loads(stdout[idx:])


def _pymysql():
    import pymysql

    return pymysql.connect(
        host=_runner_db_host(),
        port=int(PORT),
        user=USER,
        password=PASSWORD,
        charset="utf8mb4",
        connect_timeout=5,
        autocommit=True,
    )


def _root_connect():
    """Optional root for CREATE/DROP DATABASE when p2p lacks global CREATE."""
    import pymysql

    root_pass = os.environ.get("O2C_DB_ROOT_PASS") or os.environ.get("MYSQL_ROOT_PASSWORD") or "p2p_test_root"
    root_user = os.environ.get("O2C_DB_ROOT_USER") or "root"
    return pymysql.connect(
        host=_runner_db_host(),
        port=int(PORT),
        user=root_user,
        password=root_pass,
        charset="utf8mb4",
        connect_timeout=5,
        autocommit=True,
    )


def _ensure_db(name: str) -> None:
    import pymysql

    # Prefer connect-only when DB already granted to p2p (no global CREATE needed).
    try:
        pymysql.connect(
            host=_runner_db_host(),
            port=int(PORT),
            user=USER,
            password=PASSWORD,
            database=name,
            charset="utf8mb4",
            connect_timeout=5,
        ).close()
        return
    except Exception:
        pass
    try:
        conn = _root_connect()
    except Exception as exc:  # noqa: BLE001
        raise unittest.SkipTest(f"cannot_create_db:{name}:{exc}") from exc
    try:
        with conn.cursor() as cur:
            cur.execute(
                f"CREATE DATABASE IF NOT EXISTS `{name}` "
                "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            )
            cur.execute(f"GRANT ALL PRIVILEGES ON `{name}`.* TO '{USER}'@'%'")
            cur.execute("FLUSH PRIVILEGES")
    finally:
        conn.close()


def _drop_db(name: str) -> None:
    try:
        conn = _root_connect()
    except Exception:
        conn = _pymysql()
    try:
        with conn.cursor() as cur:
            cur.execute(f"DROP DATABASE IF EXISTS `{name}`")
    finally:
        conn.close()


def _mysqldump(db: str, out: Path) -> bytes:
    """Dump via mysql client in docker (no host mysqldump required)."""
    out.parent.mkdir(parents=True, exist_ok=True)
    cmd = [
        "docker",
        "run",
        "--rm",
        "--network",
        "host",
        "mysql:8.0",
        "mysqldump",
        f"-h{_runner_db_host()}",
        f"-P{PORT}",
        f"-u{USER}",
        f"-p{PASSWORD}",
        "--single-transaction",
        "--routines",
        "--databases",
        db,
    ]
    # When runner host is 127.0.0.1, docker --network host reaches GHA/service MySQL;
    # on Docker Desktop Windows, host port publish is used — prefer host.docker.internal.
    host = _runner_db_host()
    if host in {"127.0.0.1", "localhost"}:
        # Local Windows: published 3307 on host; mysql container needs host-gateway.
        cmd = [
            "docker",
            "run",
            "--rm",
            "--add-host=host.docker.internal:host-gateway",
            "mysql:8.0",
            "mysqldump",
            "-hhost.docker.internal",
            f"-P{PORT}",
            f"-u{USER}",
            f"-p{PASSWORD}",
            "--single-transaction",
            "--routines",
            "--databases",
            db,
        ]
    r = subprocess.run(cmd, capture_output=True, timeout=120)
    if r.returncode != 0:
        raise AssertionError(f"mysqldump_failed:{r.stderr[-800:]!r}")
    out.write_bytes(r.stdout)
    return r.stdout


def _mysql_restore(db: str, dump: Path) -> None:
    _drop_db(db)
    _ensure_db(db)
    host = _runner_db_host()
    if host in {"127.0.0.1", "localhost"}:
        cmd = [
            "docker",
            "run",
            "--rm",
            "-i",
            "--add-host=host.docker.internal:host-gateway",
            "mysql:8.0",
            "mysql",
            "-hhost.docker.internal",
            f"-P{PORT}",
            f"-u{USER}",
            f"-p{PASSWORD}",
        ]
    else:
        cmd = [
            "docker",
            "run",
            "--rm",
            "-i",
            "--network",
            "host",
            "mysql:8.0",
            "mysql",
            f"-h{host}",
            f"-P{PORT}",
            f"-u{USER}",
            f"-p{PASSWORD}",
        ]
    r = subprocess.run(cmd, input=dump.read_bytes(), capture_output=True, timeout=180)
    if r.returncode != 0:
        raise AssertionError(f"mysql_restore_failed:{r.stderr[-800:]!r}")


class Rc1StagingMigrateExecutor(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        if _php_available() is None:
            raise unittest.SkipTest("php_unavailable")
        os.environ.setdefault("RC1_MYSQL_CONTAINER_ID_PREFIX", EXPECTED_CONTAINER_ID_PREFIX)
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
                    # CI service MySQL path
                    os.environ["RC1_ALLOW_ANY_MYSQL"] = "1"
                    cls.sandbox = verify_sandbox(required=False)
                else:
                    os.environ["RC1_MYSQL_CONTAINER_ID_PREFIX"] = r.stdout.strip()
                    cls.sandbox = verify_sandbox(required=False)
            except unittest.SkipTest:
                raise
            except Exception as exc:  # noqa: BLE001
                os.environ["RC1_ALLOW_ANY_MYSQL"] = "1"
                try:
                    cls.sandbox = verify_sandbox(required=False)
                except Exception as exc2:  # noqa: BLE001
                    raise unittest.SkipTest(f"sandbox_unavailable:{exc2}") from exc

        _ensure_db(DB_NAME)
        _ensure_db(BACKUP_DB)

    def tearDown(self) -> None:
        if MAINT_LOCK.is_file():
            MAINT_LOCK.unlink(missing_ok=True)
        if FAIL_MARKER.is_file():
            FAIL_MARKER.unlink(missing_ok=True)

    def test_01_refuse_missing_auth(self) -> None:
        env = _base_env(dry_run=True)
        del env["RC1_STAGING_OPERATOR_AUTH"]
        r = _run_php(env, ["--dry-run"])
        self.assertEqual(r.returncode, 3)
        blob = (r.stderr + r.stdout).lower()
        self.assertTrue("authorization" in blob or "missing" in blob or "identity" in blob)

    def test_01b_refuse_missing_auth_expected(self) -> None:
        env = _base_env(dry_run=True)
        del env["RC1_STAGING_AUTH_EXPECTED"]
        r = _run_php(env, ["--dry-run"])
        self.assertEqual(r.returncode, 3)
        blob = (r.stderr + r.stdout).lower()
        self.assertIn("auth_expected", blob.replace("-", "_"))
        data = _parse_json(r.stdout)
        self.assertIn("AUTH_EXPECTED", (data.get("refused") or "").upper())

    def test_01c_refuse_auth_mismatch(self) -> None:
        env = _base_env(dry_run=True)
        env["RC1_STAGING_AUTH_EXPECTED"] = "other-token"
        r = _run_php(env, ["--dry-run"])
        self.assertEqual(r.returncode, 3)
        self.assertIn("mismatch", (r.stderr + r.stdout).lower())

    def test_01d_release_maintenance_requires_expected_auth(self) -> None:
        env = _base_env(dry_run=True)
        # Engage lock via dry-run first
        r0 = _run_php(env, ["--dry-run"])
        self.assertEqual(r0.returncode, 0, r0.stderr)
        self.assertTrue(MAINT_LOCK.is_file())
        bad = dict(env)
        del bad["RC1_STAGING_AUTH_EXPECTED"]
        rel = _run_php(bad, ["--release-maintenance"])
        self.assertEqual(rel.returncode, 3)
        self.assertTrue(MAINT_LOCK.is_file(), "lock must remain when release auth fails")
        # Proper release with full auth
        ok = _run_php(env, ["--release-maintenance"])
        self.assertEqual(ok.returncode, 0, ok.stderr)
        self.assertFalse(MAINT_LOCK.is_file())

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

    def test_03b_refuse_placeholder_backup(self) -> None:
        env = _base_env(dry_run=True)
        tiny = WEB_CACHE / "rc1_staging_executor_test" / "tiny_placeholder.sql"
        payload = b"-- tiny\nSELECT 1;\n"
        tiny.write_bytes(payload)
        env["RC1_PRE_MIGRATE_BACKUP_PATH"] = _path_for_php(tiny)
        env["RC1_PRE_MIGRATE_BACKUP_SHA256"] = hashlib.sha256(payload).hexdigest()
        r = _run_php(env, ["--dry-run"])
        self.assertEqual(r.returncode, 3)
        self.assertIn("too small", (r.stderr + r.stdout).lower())

    def test_03c_refuse_checksum_mismatch(self) -> None:
        env = _base_env(dry_run=True)
        env["RC1_PRE_MIGRATE_BACKUP_SHA256"] = "0" * 64
        r = _run_php(env, ["--dry-run"])
        self.assertEqual(r.returncode, 3)
        self.assertIn("checksum", (r.stderr + r.stdout).lower())

    def test_04_dry_run_preflight_ok(self) -> None:
        env = _base_env(dry_run=True)
        r = _run_php(env, ["--dry-run"])
        data = _parse_json(r.stdout)
        write_json("staging_executor_dry_run.json", data)
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertTrue(data.get("ok"))
        self.assertTrue(data.get("dry_run"))
        self.assertTrue(data.get("auth_expected_enforced"))
        self.assertTrue(data.get("cache_matches_web_root"))
        self.assertTrue(MAINT_LOCK.is_file())
        rel = _run_php(env, ["--release-maintenance"])
        self.assertEqual(rel.returncode, 0, rel.stderr)
        self.assertFalse(MAINT_LOCK.is_file())

    def test_05_migrate_x2_cache_sync_and_first_http(self) -> None:
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
        self.assertTrue(data.get("schema_ready_after"))
        self.assertTrue(data.get("cache_matches_web_root"))
        self.assertFalse(data.get("install_php_used"))
        self.assertFalse(data.get("docker_required"))
        self.assertFalse(data.get("prod_cache_cleared"))
        self.assertFalse(MAINT_LOCK.is_file())
        self.assertFalse(FAIL_MARKER.is_file())

        fp = data.get("db_config_fingerprint")
        self.assertTrue(fp)
        marker = WEB_CACHE / "runtime_cache" / str(fp) / "schema.json"
        try:
            self.assertTrue(marker.is_file(), f"missing schema marker at {marker}")
            mtime1 = marker.stat().st_mtime
        except PermissionError:
            # Still prove via in-container probe if host cannot stat root-owned bind mount.
            mtime1 = None

        probe = _run_php(env, script=PROBE)
        pdata = _parse_json(probe.stdout)
        write_json("staging_executor_first_http_probe.json", pdata)
        self.assertEqual(probe.returncode, 0, probe.stderr)
        self.assertTrue(pdata.get("schema_ready"))
        self.assertTrue(pdata.get("schema_marker_exists"))
        self.assertTrue(pdata.get("first_http_skips_ddl"))
        self.assertFalse(pdata.get("would_rerun_cumulative_ddl"))
        self.assertEqual(pdata.get("ddl_invocations_if_http"), 0)
        self.assertEqual(pdata.get("db_config_fingerprint"), fp)

        if mtime1 is not None:
            time.sleep(0.05)
            self.assertEqual(marker.stat().st_mtime, mtime1)

    def test_06_restorable_backup_migrate_invariants_recovery(self) -> None:
        """Replace 68-byte placeholder with real dump → restore → migrate×2 → recover."""
        _drop_db(BACKUP_DB)
        _ensure_db(BACKUP_DB)
        conn = _pymysql()
        try:
            with conn.cursor() as cur:
                cur.execute(f"USE `{BACKUP_DB}`")
                cur.execute(
                    "CREATE TABLE rc1_backup_probe ("
                    "id INT PRIMARY KEY, label VARCHAR(64) NOT NULL"
                    ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                )
                cur.execute(
                    "INSERT INTO rc1_backup_probe (id, label) VALUES "
                    "(1,'pre_migrate_invariant'),(2,'row_level_check')"
                )
        finally:
            conn.close()

        dump_path = WEB_CACHE / "rc1_staging_executor_test" / "restorable_pre_migrate.sql"
        raw = _mysqldump(BACKUP_DB, dump_path)
        self.assertGreater(len(raw), 256)
        sha = hashlib.sha256(raw).hexdigest()
        self.assertEqual(hashlib.sha256(dump_path.read_bytes()).hexdigest(), sha)

        # Destroy and restore — identity + row invariants
        _mysql_restore(BACKUP_DB, dump_path)
        conn = _pymysql()
        try:
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA "
                    f"WHERE SCHEMA_NAME=%s",
                    (BACKUP_DB,),
                )
                self.assertEqual(cur.fetchone()[0], BACKUP_DB)
                cur.execute(f"SELECT id, label FROM `{BACKUP_DB}`.rc1_backup_probe ORDER BY id")
                rows = cur.fetchall()
                self.assertEqual(
                    rows,
                    ((1, "pre_migrate_invariant"), (2, "row_level_check")),
                )
        finally:
            conn.close()

        env = _base_env(dry_run=False, db_name=BACKUP_DB)
        env["RC1_PRE_MIGRATE_BACKUP_PATH"] = _path_for_php(dump_path)
        env["RC1_PRE_MIGRATE_BACKUP_SHA256"] = sha
        r = _run_php(env)
        data = _parse_json(r.stdout)
        write_json("staging_executor_backup_restore_migrate.json", data)
        self.assertEqual(r.returncode, 0, r.stderr[-2000:])
        self.assertTrue(data.get("ok"))
        self.assertTrue(data.get("idempotent"))
        self.assertEqual(data.get("backup", {}).get("sha256"), sha)
        self.assertGreater(int(data.get("backup", {}).get("bytes") or 0), 256)

        # Critical rows survive migrate
        conn = _pymysql()
        try:
            with conn.cursor() as cur:
                cur.execute(f"SELECT id, label FROM `{BACKUP_DB}`.rc1_backup_probe ORDER BY id")
                rows = cur.fetchall()
                self.assertEqual(
                    rows,
                    ((1, "pre_migrate_invariant"), (2, "row_level_check")),
                )
        finally:
            conn.close()

        # Recovery to pre-migrate via restore
        _mysql_restore(BACKUP_DB, dump_path)
        conn = _pymysql()
        try:
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT COUNT(*) FROM information_schema.tables "
                    "WHERE table_schema=%s AND table_name='rc1_backup_probe'",
                    (BACKUP_DB,),
                )
                self.assertEqual(cur.fetchone()[0], 1)
                cur.execute(
                    "SELECT COUNT(*) FROM information_schema.tables "
                    "WHERE table_schema=%s AND table_name='erp_mf_production_orders'",
                    (BACKUP_DB,),
                )
                # After recovery dump, migrated ERP tables should be absent
                self.assertEqual(cur.fetchone()[0], 0)
                cur.execute(f"SELECT label FROM `{BACKUP_DB}`.rc1_backup_probe WHERE id=1")
                self.assertEqual(cur.fetchone()[0], "pre_migrate_invariant")
        finally:
            conn.close()

        evidence = {
            "ok": True,
            "drill": "staging_restorable_backup_integrity",
            "backup_bytes": len(raw),
            "backup_sha256": sha,
            "restored_db_identity": BACKUP_DB,
            "row_invariants_ok": True,
            "migrate_x2_ok": True,
            "recovery_to_pre_migrate_ok": True,
            "placeholder_68_byte_replaced": True,
        }
        write_json("staging_executor_backup_integrity.json", evidence)

    def test_07_failed_validation_blocks_migrate_diagnosable(self) -> None:
        env = _base_env(dry_run=False)
        env["RC1_PRE_MIGRATE_BACKUP_SHA256"] = "a" * 64
        r = _run_php(env)
        self.assertEqual(r.returncode, 3)
        data = _parse_json(r.stdout)
        self.assertFalse(data.get("ok"))
        self.assertIn("checksum", (data.get("refused") or "").lower())
        # Must not have completed migrate release path
        self.assertNotIn("migrate_twice", data)


if __name__ == "__main__":
    unittest.main()
