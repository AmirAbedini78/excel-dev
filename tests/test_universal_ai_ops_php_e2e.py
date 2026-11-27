"""Universal AI ops MySQL E2E — disposable schema, PASS under RC1 (skip only when RC1 unset)."""
from __future__ import annotations

import json
import os
import pathlib
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
PORT = os.environ.get("O2C_DB_PORT", "3307")
USER = os.environ.get("O2C_DB_USER", "p2p")
PASSWORD = os.environ.get("O2C_DB_PASS", "p2p_test")


def _db_name() -> str:
    return os.environ.get("O2C_DB_NAME", "erpsmart_universal_ai_test")


def _runner_db_host() -> str:
    return (os.environ.get("O2C_DB_HOST") or "127.0.0.1").strip()


def _php_db_host() -> str:
    explicit = (os.environ.get("O2C_DB_HOST_PHP") or "").strip()
    if explicit:
        return explicit
    host = _runner_db_host()
    if host in {"127.0.0.1", "localhost", "::1"}:
        return "host.docker.internal"
    return host or "host.docker.internal"


def _rc1_required() -> bool:
    return os.environ.get("RC1_REQUIRED", "").strip() in {"1", "true", "TRUE", "yes"}


def _php_image() -> str | None:
    for image in ("erpsmart-o2c-php:8.2", "php:8.2-cli"):
        try:
            r = subprocess.run(
                ["docker", "image", "inspect", image],
                capture_output=True,
                text=True,
                timeout=60,
            )
            if r.returncode == 0:
                return image
        except Exception:
            continue
    return None


def _docker_ok() -> bool:
    return _php_image() is not None


def _mysql_ok() -> bool:
    if os.environ.get("RC1_ALLOW_ANY_MYSQL", "").strip() in {"1", "true", "TRUE", "yes"}:
        return True
    try:
        r = subprocess.run(
            ["docker", "inspect", "-f", "{{.Name}}", "erpsmart-p2p-mysql-test"],
            capture_output=True,
            text=True,
            timeout=20,
        )
        return r.returncode == 0 and "erpsmart-p2p-mysql-test" in (r.stdout or "")
    except Exception:
        return False


def _require_stack() -> None:
    if not _docker_ok():
        if _rc1_required():
            raise AssertionError("RC1_REQUIRED: erpsmart-o2c-php:8.2 unavailable")
        raise unittest.SkipTest("erpsmart-o2c-php:8.2 unavailable")
    if not _mysql_ok():
        if _rc1_required():
            raise AssertionError("RC1_REQUIRED: disposable MySQL unavailable")
        raise unittest.SkipTest("disposable MySQL unavailable")


def _php(scenario: str, args: dict | None = None, timeout: int = 180) -> dict:
    _require_stack()
    image = _php_image()
    if not image:
        raise AssertionError("php docker image missing")
    php_host = _php_db_host()
    cmd = [
        "docker",
        "run",
        "--rm",
        "--add-host=host.docker.internal:host-gateway",
        "-e",
        f"O2C_DB_HOST={php_host}",
        "-e",
        f"O2C_DB_PORT={PORT}",
        "-e",
        f"O2C_DB_USER={USER}",
        "-e",
        f"O2C_DB_PASS={PASSWORD}",
        "-e",
        f"O2C_DB_NAME={_db_name()}",
        "-v",
        f"{ROOT}:/app",
        "-w",
        "/app",
        image,
        "php",
        "tests/harness/universal_ai_e2e_runner.php",
        scenario,
        json.dumps(args or {}, ensure_ascii=False),
    ]
    completed = subprocess.run(
        cmd, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=timeout
    )
    out = (completed.stdout or "").strip().splitlines()
    if not out:
        raise AssertionError(f"no_stdout rc={completed.returncode} stderr={completed.stderr}")
    try:
        return json.loads(out[-1])
    except json.JSONDecodeError as exc:
        raise AssertionError(f"bad_json={out[-1]!r} stderr={completed.stderr}") from exc


class UniversalAiOpsPhpE2e(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        _require_stack()

    def test_01_reads_catalog_search_report_navigate(self):
        res = _php("reads_and_navigation")
        self.assertTrue(res.get("ok"), res)
        self.assertTrue(res.get("DATABASE_TESTED"))
        self.assertGreaterEqual(res.get("catalog_count", 0), 1)
        self.assertGreaterEqual(res.get("search_count", 0), 1)
        self.assertIn("علی", res.get("read_label") or "")
        self.assertGreaterEqual(res.get("report_groups", 0), 1)
        self.assertIn("page=hr", res.get("nav_url") or "")
        self.assertIn("edit=", res.get("nav_url") or "")
        self.assertTrue(res.get("leave_has_review"))
        self.assertTrue(res.get("leave_has_create"))

    def test_02_catalog_rbac_view_only(self):
        res = _php("catalog_rbac_view_only")
        self.assertTrue(res.get("ok"), res)
        self.assertTrue(res.get("no_create"))
        self.assertTrue(res.get("no_review"))
        self.assertTrue(res.get("has_read"))

    def test_03_propose_create_approve_idempotent(self):
        res = _php("propose_create_approve")
        self.assertTrue(res.get("ok"), res)
        self.assertTrue(res.get("idempotent_same_id"), res)
        self.assertEqual(res.get("proposal_status"), "executed")
        self.assertEqual(res.get("leave_rows"), 1)
        self.assertEqual(res.get("leave_status"), "pending")
        self.assertFalse(res.get("physical_delete"))
        self.assertGreater(res.get("executed_id", 0), 0)

    def test_04_maker_checker_review(self):
        res = _php("maker_checker_review")
        self.assertTrue(res.get("ok"), res)
        self.assertTrue(res.get("creator_review_blocked"), res)
        self.assertEqual(res.get("final_status"), "approved")
        self.assertTrue(res.get("executed"))

    def test_05_stale_suite_snapshot(self):
        res = _php("stale_snapshot_rejects")
        self.assertTrue(res.get("ok"), res)
        self.assertTrue(res.get("stale_rejected"), res)

    def test_06_enterprise_stale_revision_and_history(self):
        res = _php("enterprise_stale_revision")
        self.assertTrue(res.get("ok"), res)
        self.assertTrue(res.get("stale_rejected"), res)
        self.assertGreaterEqual(res.get("history_count", 0), 1)

    def test_07_cross_company_reject(self):
        res = _php("cross_company_reject")
        self.assertTrue(res.get("ok"), res)
        self.assertTrue(res.get("cross_company_blocked"), res)

    def test_08_module_disabled(self):
        res = _php("module_disabled")
        self.assertTrue(res.get("ok"), res)
        self.assertTrue(res.get("search_blocked"), res)
        self.assertTrue(res.get("catalog_hides_employees"), res)

    def test_09_reject_proposal(self):
        res = _php("reject_proposal")
        self.assertTrue(res.get("ok"), res)
        self.assertEqual(res.get("status"), "rejected")
        self.assertTrue(res.get("no_leave_created"))


if __name__ == "__main__":
    unittest.main(verbosity=2)
