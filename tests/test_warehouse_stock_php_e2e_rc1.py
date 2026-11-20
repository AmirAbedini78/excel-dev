"""RC1 T2 warehouse stock integrity — real PHP PDO + disposable MySQL."""
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
DATABASE = os.environ.get("O2C_DB_NAME", "erpsmart_p2p_test")
EXPECTED_CONTAINER_ID = (
    "02eb215a87f6a726cf4efb1546f4b09865c9eb6eec9de1e9dc0e6b3163672db6"
)


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


def _verify_mysql_container() -> str:
    r = subprocess.run(
        ["docker", "inspect", "erpsmart-p2p-mysql-test", "--format", "{{.Id}}"],
        capture_output=True,
        text=True,
        timeout=20,
    )
    if r.returncode != 0:
        raise unittest.SkipTest("erpsmart-p2p-mysql-test container missing")
    cid = (r.stdout or "").strip()
    if EXPECTED_CONTAINER_ID not in cid and not cid.startswith(EXPECTED_CONTAINER_ID[:12]):
        raise unittest.SkipTest(
            f"REFUSED unverified DB container id={cid!r}; expected {EXPECTED_CONTAINER_ID}"
        )
    return cid


def _run_suite(timeout: int = 300) -> dict:
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
        "tests/harness/wms_e2e_suite.php",
    ]
    completed = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    out = (completed.stdout or "").strip().splitlines()
    if not out:
        raise AssertionError(
            f"no_stdout rc={completed.returncode} stderr={completed.stderr}"
        )
    try:
        payload = json.loads(out[-1])
    except json.JSONDecodeError as exc:
        raise AssertionError(
            f"bad_json rc={completed.returncode} last={out[-1]!r} stderr={completed.stderr}"
        ) from exc
    payload["_rc"] = completed.returncode
    payload["_stderr"] = completed.stderr
    return payload


class WarehouseStockPhpE2eRc1(unittest.TestCase):
    suite: dict
    mysql_id: str

    @classmethod
    def setUpClass(cls):
        try:
            import pymysql  # type: ignore
        except ImportError as exc:
            raise unittest.SkipTest("pymysql missing") from exc
        cls.mysql_id = _verify_mysql_container()
        try:
            pymysql.connect(
                host="127.0.0.1",
                port=int(PORT),
                user=USER,
                password=PASSWORD,
                database=DATABASE,
                charset="utf8mb4",
                connect_timeout=3,
            ).close()
        except Exception as exc:  # noqa: BLE001
            raise unittest.SkipTest(f"MySQL sandbox unavailable: {exc}") from exc
        cls.suite = _run_suite()
        if not cls.suite.get("ok"):
            # Keep class setup from aborting individual assertions — tests assert each case.
            pass

    def _case(self, name: str) -> dict:
        results = self.suite.get("results") or {}
        self.assertIn(name, results, self.suite)
        return results[name]

    def test_01_rejected_gr_never_stocks_accepted_increments_once(self):
        c = self._case("test_01_rejected_gr")
        self.assertTrue(c.get("ok"), c)

    def test_02_concurrent_fight_for_last_units(self):
        c = self._case("test_02_concurrent")
        self.assertTrue(c.get("ok"), c)

    def test_03_idempotent_issue_and_payload_mismatch(self):
        c = self._case("test_03_idempotency")
        self.assertTrue(c.get("ok"), c)

    def test_04_partial_issue_and_fg_receipt(self):
        c = self._case("test_04_partial")
        self.assertTrue(c.get("ok"), c)

    def test_05_cross_company_isolation(self):
        c = self._case("test_05_cross_company")
        self.assertTrue(c.get("ok"), c)

    def test_06_rollback_fault_injection(self):
        c = self._case("test_06_rollback")
        self.assertTrue(c.get("ok"), c)

    def test_07_reverse_issue_restores_stock_and_reservation(self):
        c = self._case("test_07_reverse")
        self.assertTrue(c.get("ok"), c)

    def test_99_suite_green_and_db_identity(self):
        self.assertTrue(self.suite.get("ok"), self.suite.get("failed"))
        self.assertIn(EXPECTED_CONTAINER_ID[:12], self.mysql_id)


if __name__ == "__main__":
    unittest.main()
