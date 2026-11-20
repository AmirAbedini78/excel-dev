"""MF-1 manufacturing E2E against disposable MySQL (Docker PHP + erpsmart-p2p-mysql-test)."""
from __future__ import annotations

import json
import os
import pathlib
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
HOST = os.environ.get("O2C_DB_HOST", "host.docker.internal")
PORT = os.environ.get("O2C_DB_PORT", "3307")
USER = os.environ.get("O2C_DB_USER", "p2p")
PASSWORD = os.environ.get("O2C_DB_PASS", "p2p_test")
DATABASE = os.environ.get("O2C_DB_NAME", "erpsmart_mf1_t1_test")


def _docker_ok() -> bool:
    try:
        r = subprocess.run(
            ["docker", "image", "inspect", "erpsmart-o2c-php:8.2"],
            capture_output=True,
            text=True,
            timeout=20,
        )
        return r.returncode == 0
    except Exception:
        return False


def _php(scenario: str, args: dict | None = None, timeout: int = 180) -> dict:
    if not _docker_ok():
        raise unittest.SkipTest("erpsmart-o2c-php:8.2 unavailable")
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
        f"{ROOT}:/app",
        "-w",
        "/app",
        "erpsmart-o2c-php:8.2",
        "php",
        "tests/harness/mf_e2e_runner.php",
        scenario,
        json.dumps(args or {}, ensure_ascii=False),
    ]
    completed = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    out = (completed.stdout or "").strip().splitlines()
    if not out:
        raise AssertionError(f"no_stdout rc={completed.returncode} stderr={completed.stderr}")
    try:
        return json.loads(out[-1])
    except json.JSONDecodeError as exc:
        raise AssertionError(f"bad_json={out[-1]!r} stderr={completed.stderr}") from exc


class ManufacturingMf1PhpE2e(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        # Ensure isolated MF schema exists on verified MySQL container.
        try:
            subprocess.run(
                [
                    "docker",
                    "exec",
                    "erpsmart-p2p-mysql-test",
                    "mysql",
                    "-uroot",
                    "-pp2p_test_root",
                    "-e",
                    f"CREATE DATABASE IF NOT EXISTS `{DATABASE}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
                    f"GRANT ALL ON `{DATABASE}`.* TO 'p2p'@'%'; FLUSH PRIVILEGES;",
                ],
                capture_output=True,
                text=True,
                timeout=30,
            )
        except Exception:
            pass

    def test_00_mysql_identity(self):
        r = subprocess.run(
            ["docker", "inspect", "-f", "{{.Id}} {{.Name}}", "erpsmart-p2p-mysql-test"],
            capture_output=True,
            text=True,
            timeout=20,
        )
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertIn("erpsmart-p2p-mysql-test", r.stdout)

    def test_01_lifecycle_happy_path(self):
        res = _php("lifecycle_happy")
        self.assertTrue(res.get("ok"), res)
        self.assertTrue(res.get("DATABASE_TESTED"))
        self.assertTrue(res.get("issue_replay"))
        self.assertTrue(res.get("fg_replay"))
        self.assertTrue(res.get("fg_reject_blocked"))
        self.assertEqual(res.get("received_fg"), "2.0000")
        self.assertEqual(res.get("gl_posting"), "BLOCKED")
        self.assertEqual(res.get("raw_a_on_hand"), "8.0000")
        self.assertEqual(res.get("fg_on_hand"), "2.0000")

    def test_02_insufficient_stock_rejected(self):
        res = _php("insufficient_stock")
        self.assertTrue(res.get("ok"), res)
        self.assertTrue(res.get("rejected"))

    def test_03_injected_rollback(self):
        res = _php("injected_rollback")
        self.assertTrue(res.get("ok"), res)
        self.assertTrue(res.get("injected_failed"))
        self.assertTrue(res.get("issues_unchanged"))
        self.assertTrue(res.get("moves_unchanged"))
        self.assertTrue(res.get("on_hand_unchanged"))

    def test_04_gates_bundle(self):
        res = _php("suite_gates")
        self.assertTrue(res.get("ok"), res)
        cases = res.get("cases") or {}
        for name in (
            "maker_checker_isolation",
            "duplicate_and_closed_period",
            "concurrent_issue",
            "mrp_adapter",
        ):
            self.assertTrue(cases.get(name, {}).get("pass"), f"{name}: {cases.get(name)}")


if __name__ == "__main__":
    unittest.main()
