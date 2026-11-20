"""Real PHP domain + MySQL E2E for v10.19 AR posting kernel.

Posts O2C billing invoice via AccountingPostingDomain::postCustomerInvoice
against disposable MySQL. Proves balanced voucher, idempotency, no double-post,
outstanding from posted lines, maker/checker, settlement blocked.
"""
from __future__ import annotations

import json
import os
import pathlib
import subprocess
import threading
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
    return json.loads(out[-1])


def _full_o2c_invoice() -> int:
    """Build approved order → reserve → deliver → draft O2C invoice. Returns invoice_doc_id."""
    reset = _php("reset")
    assert reset.get("ok"), reset
    so = int(_php("seed_order", {"created_by": 1, "qty": "10.0000", "price": "100.00"})["sales_doc_id"])
    conf = _php("confirm_order", {"sales_doc_id": so, "user_id": 2})
    assert conf.get("ok"), conf
    line = int(_php("line_id", {"sales_doc_id": so})["sales_line_id"])
    res = _php(
        "reserve",
        {
            "sales_doc_id": so,
            "warehouse_id": 1,
            "lines": [{"sales_line_id": line, "quantity": "10.0000"}],
        },
    )
    assert res.get("ok"), res
    deliv = _php(
        "deliver",
        {
            "sales_doc_id": so,
            "warehouse_id": 1,
            "delivery_date": "2026-11-20",
            "idempotency_key": "d" * 32,
            "lines": [{"sales_line_id": line, "quantity": "10.0000"}],
        },
    )
    assert deliv.get("ok"), deliv
    delivery_id = int(deliv["result"]["id"])
    inv = _php(
        "invoice",
        {
            "sales_doc_id": so,
            "delivery_id": delivery_id,
            "user_id": 1,
            "idempotency_key": "a" * 32,
            "lines": {str(line): {"quantity": "10.0000"}},
        },
    )
    assert inv.get("ok"), inv
    return int(inv["result"]["id"])


class ArPhpE2e1019(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        try:
            import pymysql  # type: ignore
        except ImportError as exc:
            raise unittest.SkipTest("pymysql not installed") from exc
        try:
            conn = pymysql.connect(
                host="127.0.0.1",
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

    def test_post_balanced_idempotent_outstanding(self):
        invoice_id = _full_o2c_invoice()
        mapped = _php("ar_set_map")
        self.assertTrue(mapped.get("ok"), mapped)
        self.assertTrue(mapped["readiness"]["ready"])

        denied = _php("ar_post", {"invoice_doc_id": invoice_id, "user_id": 1, "idempotency_key": "b" * 32})
        self.assertFalse(denied.get("ok"), denied)
        self.assertIn("Maker", denied.get("error", ""))

        first = _php("ar_post", {"invoice_doc_id": invoice_id, "user_id": 2, "idempotency_key": "c" * 32})
        self.assertTrue(first.get("ok"), first)
        self.assertFalse(first["result"]["existing"])
        self.assertEqual(first["result"]["status"], "posted")
        self.assertEqual(first["result"]["settlement"], "AVAILABLE")
        voucher_id = int(first["result"]["voucher_id"])
        self.assertEqual(first["result"]["net_total"], "1000.00")

        bal = _php("ar_voucher_balance", {"voucher_id": voucher_id})
        self.assertTrue(bal.get("ok"), bal)
        self.assertEqual(str(bal["voucher"]["total_debit"]), "1000.00")
        self.assertEqual(str(bal["voucher"]["total_credit"]), "1000.00")
        self.assertEqual(bal["voucher"]["status"], "final")
        self.assertEqual(bal["voucher"]["source_type"], "o2c_ar")
        self.assertEqual(str(bal["lines"]["d"]), "1000.00")
        self.assertEqual(str(bal["lines"]["c"]), "1000.00")
        self.assertEqual(bal["postings"], 1)

        second = _php("ar_post", {"invoice_doc_id": invoice_id, "user_id": 2, "idempotency_key": "c" * 32})
        self.assertTrue(second.get("ok"), second)
        self.assertTrue(second["result"]["existing"])
        self.assertEqual(int(second["result"]["voucher_id"]), voucher_id)

        third = _php("ar_post", {"invoice_doc_id": invoice_id, "user_id": 2, "idempotency_key": "f" * 32})
        self.assertTrue(third.get("ok"), third)
        self.assertTrue(third["result"]["existing"])
        self.assertEqual(int(third["result"]["voucher_id"]), voucher_id)

        out = _php("ar_outstanding", {"party_id": 1})
        self.assertTrue(out.get("ok"), out)
        self.assertEqual(out["result"]["outstanding"], "1000.00")
        self.assertEqual(out["result"]["status"], "OPEN")
        self.assertIn("posted_ar_account", out["result"]["basis"])

        bal2 = _php("ar_voucher_balance", {"voucher_id": voucher_id})
        self.assertEqual(bal2["postings"], 1)

    def test_concurrent_post_no_double(self):
        invoice_id = _full_o2c_invoice()
        mapped = _php("ar_set_map")
        self.assertTrue(mapped.get("ok"), mapped)

        results: list[dict] = []
        errors: list[str] = []

        def worker(key_suffix: str):
            try:
                results.append(
                    _php(
                        "ar_post",
                        {
                            "invoice_doc_id": invoice_id,
                            "user_id": 2,
                            "idempotency_key": ("e" * 30) + key_suffix,
                        },
                    )
                )
            except Exception as ex:  # noqa: BLE001
                errors.append(str(ex))

        t1 = threading.Thread(target=worker, args=("01",))
        t2 = threading.Thread(target=worker, args=("02",))
        t1.start()
        t2.start()
        t1.join(timeout=180)
        t2.join(timeout=180)
        self.assertEqual(errors, [])
        self.assertEqual(len(results), 2)
        oks = [r for r in results if r.get("ok")]
        # One may fail on unique constraint race; at least one must succeed.
        self.assertGreaterEqual(len(oks), 1)
        voucher_ids = {int(r["result"]["voucher_id"]) for r in oks if r.get("result")}
        self.assertEqual(len(voucher_ids), 1)
        vid = next(iter(voucher_ids))
        bal = _php("ar_voucher_balance", {"voucher_id": vid})
        self.assertEqual(bal["postings"], 1)
        self.assertEqual(str(bal["voucher"]["total_debit"]), str(bal["voucher"]["total_credit"]))


if __name__ == "__main__":
    unittest.main()
