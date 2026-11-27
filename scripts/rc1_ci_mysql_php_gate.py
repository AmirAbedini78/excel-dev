#!/usr/bin/env python3
"""Mandatory RC1 PHP+MySQL CI gate — fails hard on SkipTest / missing deps.

Unlike commercial source-only release_gate, this gate REQUIRES a live MySQL
and Docker PHP image (or host PHP with mysqli). Exit 1 on skip or failure.

Networking (dual host):
  O2C_DB_HOST      — runner/host pymysql (often 127.0.0.1 on GHA)
  O2C_DB_HOST_PHP  — hostname passed into PHP Docker (host.docker.internal)
Never blindly forward loopback into PHP containers.
"""
from __future__ import annotations

import os
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def _require_env() -> None:
    missing = []
    for key in ("O2C_DB_HOST", "O2C_DB_PORT", "O2C_DB_USER", "O2C_DB_PASS", "O2C_DB_NAME"):
        if not os.environ.get(key):
            missing.append(key)
    if missing:
        raise SystemExit(f"RC1_CI_MYSQL_PHP_GATE_FAIL: missing env {missing}")


def _ensure_php_db_host() -> None:
    """Set O2C_DB_HOST_PHP when absent; remap loopback so PHP Docker reaches MySQL."""
    explicit = (os.environ.get("O2C_DB_HOST_PHP") or "").strip()
    if explicit:
        return
    host = (os.environ.get("O2C_DB_HOST") or "").strip()
    if host in {"127.0.0.1", "localhost", "::1"}:
        os.environ["O2C_DB_HOST_PHP"] = "host.docker.internal"
        print(
            "RC1_CI_MYSQL_PHP_GATE: O2C_DB_HOST_PHP=host.docker.internal "
            f"(remapped from runner O2C_DB_HOST={host})",
            flush=True,
        )
    elif host:
        os.environ["O2C_DB_HOST_PHP"] = host


def _ensure_disposable_databases(names: list[str]) -> None:
    """CREATE + GRANT disposable schemas for p2p (p2p often lacks CREATE DATABASE).

    Prefer docker exec into the named sandbox container; fall back to root TCP.
    Never accepts prod-like names.
    """
    forbidden = ("prod", "production", "zzflgmfd", "cpanel", "live", "baseline")
    for name in names:
        low = name.lower()
        if any(x in low for x in forbidden):
            raise SystemExit(f"RC1_CI_MYSQL_PHP_GATE_FAIL: refused db name {name}")
        if "test" not in low and "e2e" not in low and "staging" not in low:
            raise SystemExit(f"RC1_CI_MYSQL_PHP_GATE_FAIL: non-disposable db {name}")

    root_pass = (
        os.environ.get("O2C_DB_ROOT_PASS")
        or os.environ.get("MYSQL_ROOT_PASSWORD")
        or "p2p_test_root"
    )
    statements: list[str] = []
    for name in names:
        statements.append(
            f"CREATE DATABASE IF NOT EXISTS `{name}` "
            "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        )
        statements.append(f"GRANT ALL PRIVILEGES ON `{name}`.* TO 'p2p'@'%'")
    statements.append("FLUSH PRIVILEGES")

    container = os.environ.get("RC1_MYSQL_CONTAINER", "erpsmart-p2p-mysql-test")
    insp = subprocess.run(
        ["docker", "inspect", "-f", "{{.Name}}", container],
        capture_output=True,
        text=True,
    )
    if insp.returncode == 0 and container in (insp.stdout or ""):
        sql = "; ".join(statements) + ";"
        r = subprocess.run(
            ["docker", "exec", container, "mysql", "-uroot", f"-p{root_pass}", "-e", sql],
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
        )
        if r.returncode != 0:
            raise SystemExit(f"RC1_CI_MYSQL_PHP_GATE_FAIL: db ensure via docker: {r.stderr[-400:]}")
        print(f"RC1_CI_MYSQL_PHP_GATE: ensured DBs via {container}: {names}", flush=True)
        return

    try:
        import pymysql  # type: ignore
    except ImportError as exc:
        raise SystemExit("RC1_CI_MYSQL_PHP_GATE_FAIL: pymysql missing for db ensure") from exc
    conn = pymysql.connect(
        host=os.environ["O2C_DB_HOST"],
        port=int(os.environ["O2C_DB_PORT"]),
        user=os.environ.get("O2C_DB_ROOT_USER") or "root",
        password=root_pass,
        charset="utf8mb4",
        connect_timeout=10,
    )
    try:
        with conn.cursor() as cur:
            # One statement per execute — pymysql multi-statement is off by default.
            for stmt in statements:
                cur.execute(stmt)
        conn.commit()
    finally:
        conn.close()
    print(f"RC1_CI_MYSQL_PHP_GATE: ensured DBs via TCP root: {names}", flush=True)


def main() -> int:
    os.environ.setdefault("RC1_REQUIRED", "1")
    os.environ.setdefault("RC1_ALLOW_ANY_MYSQL", "1")
    _require_env()
    _ensure_php_db_host()
    _ensure_disposable_databases(
        [
            "erpsmart_mf1_test",
            "erpsmart_t3_trading_test",
            "erpsmart_universal_ai_test",
            "erpsmart_rc1_staging_executor_test",
            "erpsmart_rc1_staging_backup_test",
        ]
    )

    # Fail if Docker PHP image missing when tests need it.
    img = subprocess.run(
        ["docker", "image", "inspect", "erpsmart-o2c-php:8.2"],
        capture_output=True,
        text=True,
    )
    if img.returncode != 0:
        # CI builds or pulls image in workflow; missing here is a hard fail.
        print("RC1_CI_MYSQL_PHP_GATE_FAIL: erpsmart-o2c-php:8.2 unavailable", flush=True)
        return 1

    class FailOnSkipResult(unittest.TextTestResult):
        def addSkip(self, test, reason):  # noqa: N802
            super().addSkip(test, reason)
            self.failures.append(
                (test, f"RC1_CI_MYSQL_PHP_GATE_FAIL: unexpected skip: {reason}")
            )

    # Isolate disposable schemas — never share MF vs trading on one O2C_DB_NAME.
    cases = [
        (
            "erpsmart_mf1_test",
            "tests.test_manufacturing_mf1_php_e2e.ManufacturingMf1PhpE2e.test_06_concurrent_issue_two_orders",
        ),
        (
            "erpsmart_t3_trading_test",
            "tests.test_trading_cockpit_php_e2e_rc1.TradingCockpitPhpE2eRc1.test_tr1_exact_figures_and_cockpit_timeline",
        ),
        (
            "erpsmart_universal_ai_test",
            "tests.test_universal_ai_ops_php_e2e.UniversalAiOpsPhpE2e",
        ),
    ]
    # Staging safety suite (auth/cache/backup) — disposable DBs only.
    staging_suite_name = "tests.rc1.test_rc1_staging_migrate_executor"
    loader = unittest.TestLoader()
    runner = unittest.TextTestRunner(verbosity=2, resultclass=FailOnSkipResult)
    any_fail = False
    print(
        "RC1_CI_MYSQL_PHP_GATE: "
        f"runner_host={os.environ.get('O2C_DB_HOST')} "
        f"php_host={os.environ.get('O2C_DB_HOST_PHP')} "
        f"port={os.environ.get('O2C_DB_PORT')}",
        flush=True,
    )
    for db_name, test_name in cases:
        os.environ["O2C_DB_NAME"] = db_name
        suite = loader.loadTestsFromName(test_name)
        result = runner.run(suite)
        if (
            not result.wasSuccessful()
            or result.failures
            or result.errors
            or result.skipped
        ):
            any_fail = True
            if result.skipped:
                print(
                    f"RC1_CI_MYSQL_PHP_GATE_FAIL: {len(result.skipped)} skip(s) on {db_name}",
                    flush=True,
                )
    os.environ["O2C_DB_NAME"] = "erpsmart_rc1_staging_executor_test"
    os.environ.setdefault("RC1_ALLOW_ANY_MYSQL", "1")
    staging_suite = loader.loadTestsFromName(staging_suite_name)
    staging_result = runner.run(staging_suite)
    if (
        not staging_result.wasSuccessful()
        or staging_result.failures
        or staging_result.errors
        or staging_result.skipped
    ):
        any_fail = True
        if staging_result.skipped:
            print(
                f"RC1_CI_MYSQL_PHP_GATE_FAIL: {len(staging_result.skipped)} skip(s) on staging safety",
                flush=True,
            )
    if not any_fail:
        print("RC1_CI_MYSQL_PHP_GATE: PASS", flush=True)
        return 0
    print("RC1_CI_MYSQL_PHP_GATE_FAIL: tests failed", flush=True)
    return 1


if __name__ == "__main__":
    sys.path.insert(0, str(ROOT))
    raise SystemExit(main())
