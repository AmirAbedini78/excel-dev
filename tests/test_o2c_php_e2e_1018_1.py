"""Real PHP domain + MySQL E2E for v10.18.1 O2C integrity hotfix.

Runs OrderToCashDomain / SalesDomain methods inside Docker php:8.2-cli
against disposable MySQL (default host.docker.internal:3307).

Proves A1–A4 fixes. SKIP is NOT a pass when Docker/MySQL unavailable —
this module raises SkipTest only for missing runtime; CI/local with Docker must run.
"""
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


def _docker_php_available() -> bool:
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
    if not _docker_php_available():
        raise unittest.SkipTest("erpsmart-o2c-php:8.2 Docker image unavailable")
    payload = json.dumps(args or {}, ensure_ascii=False)
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
        "tests/harness/o2c_e2e_runner.php",
        scenario,
        payload,
    ]
    completed = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    out = (completed.stdout or "").strip().splitlines()
    if not out:
        raise AssertionError(f"no_stdout rc={completed.returncode} stderr={completed.stderr}")
    data = json.loads(out[-1])
    if not data.get("ok") and scenario not in {"confirm_billing_should_fail", "reserve_billing_should_fail"}:
        # Propagate domain failure JSON for negative assertions; raise for unexpected failures.
        if scenario in {"invoice", "deliver", "reserve"} and data.get("error"):
            return data
        raise AssertionError(f"scenario={scenario} failed: {data} stderr={completed.stderr}")
    return data


class O2CPhpE2E10181(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        # Ensure MySQL reachable from host first (sandbox protect).
        try:
            import pymysql  # type: ignore
        except ImportError as exc:
            raise unittest.SkipTest("pymysql not installed") from exc
        host = "127.0.0.1"
        try:
            conn = pymysql.connect(
                host=host,
                port=int(PORT),
                user=USER,
                password=PASSWORD,
                charset="utf8mb4",
                connect_timeout=3,
            )
            conn.close()
        except Exception as exc:  # noqa: BLE001
            raise unittest.SkipTest(f"MySQL sandbox unavailable: {exc}") from exc
        if "test" not in DATABASE.lower() and "e2e" not in DATABASE.lower():
            raise unittest.SkipTest(f"refusing non-test database {DATABASE}")
        data = _php("reset")
        cls.assertTrue(cls, data.get("ok"))

    def test_01_partial_invoicing_two_invoices_same_delivery_line(self):
        """A1: deliver 10, invoice 4 then 6 — both succeed; total ≤ delivered."""
        so = _php("seed_order", {"created_by": 1, "qty": "10.0000"})["sales_doc_id"]
        _php("confirm_order", {"sales_doc_id": so, "user_id": 2})
        line = _php("line_id", {"sales_doc_id": so})["sales_line_id"]
        _php(
            "reserve",
            {
                "sales_doc_id": so,
                "warehouse_id": 1,
                "lines": [{"sales_line_id": line, "quantity": "10.0000"}],
            },
        )
        deliv = _php(
            "deliver",
            {
                "sales_doc_id": so,
                "warehouse_id": 1,
                "delivery_date": "2026-11-20",
                "idempotency_key": "a" * 32,
                "lines": [{"sales_line_id": line, "quantity": "10.0000"}],
            },
        )["result"]
        delivery_id = int(deliv["id"])
        inv1 = _php(
            "invoice",
            {
                "sales_doc_id": so,
                "delivery_id": delivery_id,
                "idempotency_key": "b" * 32,
                "lines": {str(line): {"quantity": "4.0000"}},
            },
        )["result"]
        inv2 = _php(
            "invoice",
            {
                "sales_doc_id": so,
                "delivery_id": delivery_id,
                "idempotency_key": "c" * 32,
                "lines": {str(line): {"quantity": "6.0000"}},
            },
        )["result"]
        self.assertNotEqual(inv1["id"], inv2["id"])
        rows = _php(
            "query",
            {
                "sql": f"SELECT SUM(quantity) q FROM erp_o2c_invoice_links WHERE delivery_id={delivery_id} AND sales_line_id={line}"
            },
        )["rows"]
        self.assertEqual(float(rows[0]["q"]), 10.0)
        # Over-invoice must fail
        bad = _php(
            "invoice",
            {
                "sales_doc_id": so,
                "delivery_id": delivery_id,
                "lines": {str(line): {"quantity": "0.0001"}},
            },
        )
        self.assertFalse(bad.get("ok"))

    def test_02_billing_invoice_not_fulfillable(self):
        """A2: O2C billing invoice cannot be confirmed or reserved."""
        so = _php("seed_order", {"created_by": 1, "qty": "5.0000"})["sales_doc_id"]
        _php("confirm_order", {"sales_doc_id": so, "user_id": 2})
        line = _php("line_id", {"sales_doc_id": so})["sales_line_id"]
        _php(
            "reserve",
            {
                "sales_doc_id": so,
                "warehouse_id": 1,
                "lines": [{"sales_line_id": line, "quantity": "5.0000"}],
            },
        )
        deliv = _php(
            "deliver",
            {
                "sales_doc_id": so,
                "warehouse_id": 1,
                "delivery_date": "2026-11-20",
                "idempotency_key": "d" * 32,
                "lines": [{"sales_line_id": line, "quantity": "5.0000"}],
            },
        )["result"]
        inv = _php(
            "invoice",
            {
                "sales_doc_id": so,
                "delivery_id": int(deliv["id"]),
                "lines": {str(line): {"quantity": "5.0000"}},
            },
        )["result"]
        blocked = _php("confirm_billing_should_fail", {"sales_doc_id": int(inv["id"])})
        self.assertTrue(blocked.get("blocked"))
        blocked2 = _php(
            "reserve_billing_should_fail",
            {
                "sales_doc_id": int(inv["id"]),
                "warehouse_id": 1,
                "lines": [{"sales_line_id": line, "quantity": "1.0000"}],
            },
        )
        self.assertTrue(blocked2.get("blocked"))

    def test_03_concurrent_reservations_real_php(self):
        """A3: two PHP child processes compete for 70 of 100 available — exactly one wins."""
        _php("reset")
        so1 = _php("seed_order", {"created_by": 1, "qty": "70.0000"})["sales_doc_id"]
        so2 = _php("seed_order", {"created_by": 1, "qty": "70.0000"})["sales_doc_id"]
        _php("confirm_order", {"sales_doc_id": so1, "user_id": 2})
        _php("confirm_order", {"sales_doc_id": so2, "user_id": 2})
        line1 = _php("line_id", {"sales_doc_id": so1})["sales_line_id"]
        line2 = _php("line_id", {"sales_doc_id": so2})["sales_line_id"]
        args1 = json.dumps(
            {
                "sales_doc_id": so1,
                "warehouse_id": 1,
                "user_id": 2,
                "lines": [{"sales_line_id": line1, "quantity": "70.0000"}],
            }
        )
        args2 = json.dumps(
            {
                "sales_doc_id": so2,
                "warehouse_id": 1,
                "user_id": 2,
                "lines": [{"sales_line_id": line2, "quantity": "70.0000"}],
            }
        )
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
            "sh",
            "tests/harness/o2c_concurrent_reserve.sh",
            args1,
            args2,
        ]
        completed = subprocess.run(cmd, capture_output=True, text=True, timeout=180)
        self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)
        out = json.loads(completed.stdout.strip().splitlines()[-1])
        r1, r2 = out["out1"], out["out2"]
        oks = [r for r in (r1, r2) if r.get("ok")]
        fails = [r for r in (r1, r2) if not r.get("ok")]
        self.assertEqual(len(oks), 1, out)
        self.assertEqual(len(fails), 1, out)
        rows = _php(
            "query",
            {"sql": "SELECT COALESCE(SUM(quantity),0) q FROM acc_inventory_reservations WHERE status='active' AND item_id=1"},
        )["rows"]
        self.assertEqual(float(rows[0]["q"]), 70.0)

    def test_04_delivery_idempotent_retry_after_full_delivery(self):
        """A4: retry same key+payload after full delivery returns existing; no second movement."""
        _php("reset")
        so = _php("seed_order", {"created_by": 1, "qty": "3.0000"})["sales_doc_id"]
        _php("confirm_order", {"sales_doc_id": so, "user_id": 2})
        line = _php("line_id", {"sales_doc_id": so})["sales_line_id"]
        _php(
            "reserve",
            {
                "sales_doc_id": so,
                "warehouse_id": 1,
                "lines": [{"sales_line_id": line, "quantity": "3.0000"}],
            },
        )
        key = "e" * 32
        payload = {
            "sales_doc_id": so,
            "warehouse_id": 1,
            "delivery_date": "2026-11-20",
            "idempotency_key": key,
            "lines": [{"sales_line_id": line, "quantity": "3.0000"}],
        }
        first = _php("deliver", payload)["result"]
        second = _php("deliver", payload)["result"]
        self.assertEqual(int(first["id"]), int(second["id"]))
        self.assertTrue(second.get("existing"))
        moves = _php(
            "query",
            {
                "sql": f"SELECT COUNT(*) c FROM acc_stock_movements WHERE source_type='sales_delivery' AND source_id={int(first['id'])}"
            },
        )["rows"]
        self.assertEqual(int(moves[0]["c"]), 1)
        # Same key, different payload → reject
        bad = _php(
            "deliver",
            {
                "sales_doc_id": so,
                "warehouse_id": 1,
                "delivery_date": "2026-11-20",
                "idempotency_key": key,
                "lines": [{"sales_line_id": line, "quantity": "1.0000"}],
            },
        )
        self.assertFalse(bad.get("ok"))


if __name__ == "__main__":
    unittest.main()
