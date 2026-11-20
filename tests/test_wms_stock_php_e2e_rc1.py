"""RC1 T2 — shared stock API PHP+MySQL E2E (disposable sandbox)."""
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
DATABASE = os.environ.get("O2C_DB_NAME", "erpsmart_rc1_wms_test")


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


def _php(scenario: str, args: dict | None = None, timeout: int = 120) -> dict:
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
        "tests/harness/wms_e2e_runner.php",
        scenario,
        json.dumps(args or {}, ensure_ascii=False),
    ]
    completed = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    out = (completed.stdout or "").strip().splitlines()
    if not out:
        raise AssertionError(f"no_stdout rc={completed.returncode} stderr={completed.stderr}")
    return json.loads(out[-1])


class WmsStockPhpE2eRc1(unittest.TestCase):
    def test_reserve_issue_partial_fg_idempotent(self):
        reset = _php("reset", {})
        self.assertTrue(reset.get("ok"), reset)
        r = _php(
            "reserve",
            {
                "production_order_id": 101,
                "warehouse_id": 1,
                "lines": [{"item_id": 1, "quantity": "6.0000"}],
                "idempotency_key": "a" * 32,
            },
        )
        self.assertTrue(r.get("ok"), r)
        again = _php(
            "reserve",
            {
                "production_order_id": 101,
                "warehouse_id": 1,
                "lines": [{"item_id": 1, "quantity": "6.0000"}],
                "idempotency_key": "a" * 32,
            },
        )
        self.assertTrue(again.get("ok"), again)
        self.assertTrue(again.get("result", {}).get("existing"))

        issue = _php(
            "issue",
            {
                "production_order_id": 101,
                "warehouse_id": 1,
                "lines": [{"item_id": 1, "quantity": "2.0000", "unit_cost": "100.00"}],
                "idempotency_key": "b" * 32,
            },
        )
        self.assertTrue(issue.get("ok"), issue)
        issue2 = _php(
            "issue",
            {
                "production_order_id": 101,
                "warehouse_id": 1,
                "lines": [{"item_id": 1, "quantity": "2.0000", "unit_cost": "100.00"}],
                "idempotency_key": "b" * 32,
            },
        )
        self.assertTrue(issue2.get("result", {}).get("existing"))

        fg = _php(
            "fg",
            {
                "production_order_id": 101,
                "warehouse_id": 1,
                "lines": [{"item_id": 2, "quantity": "1.0000", "unit_cost": "0.00"}],
                "idempotency_key": "c" * 32,
            },
        )
        self.assertTrue(fg.get("ok"), fg)

        over = _php(
            "issue",
            {
                "production_order_id": 101,
                "warehouse_id": 1,
                "lines": [{"item_id": 1, "quantity": "99.0000"}],
                "idempotency_key": "d" * 32,
            },
        )
        self.assertFalse(over.get("ok"))

    def test_rejected_gr_never_stocks(self):
        r = _php("rejected_gr_smoke", {})
        self.assertTrue(r.get("ok"), r)
        self.assertEqual(r.get("result", {}).get("on_hand_delta"), "0.0000")


if __name__ == "__main__":
    unittest.main()
