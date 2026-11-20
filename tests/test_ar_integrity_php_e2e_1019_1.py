"""Real PHP+MySQL E2E for v10.20.0 AR integrity hardening (A2–A7)."""
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
        "tests/harness/o2c_e2e_runner.php",
        scenario,
        json.dumps(args or {}, ensure_ascii=False),
    ]
    completed = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    out = (completed.stdout or "").strip().splitlines()
    if not out:
        raise AssertionError(f"no_stdout rc={completed.returncode} stderr={completed.stderr}")
    return json.loads(out[-1])


def _partial_invoice(qty: str = "4.0000") -> tuple[int, int, int]:
    """Returns (source_sales_doc_id, delivery_id, invoice_doc_id) after invoicing qty of 10."""
    assert _php("reset").get("ok")
    so = int(_php("seed_order", {"created_by": 1, "qty": "10.0000", "price": "100.00"})["sales_doc_id"])
    assert _php("confirm_order", {"sales_doc_id": so, "user_id": 2}).get("ok")
    line = int(_php("line_id", {"sales_doc_id": so})["sales_line_id"])
    assert _php(
        "reserve",
        {"sales_doc_id": so, "warehouse_id": 1, "lines": [{"sales_line_id": line, "quantity": "10.0000"}]},
    ).get("ok")
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
            "lines": {str(line): {"quantity": qty}},
        },
    )
    assert inv.get("ok"), inv
    return so, delivery_id, int(inv["result"]["id"])


class ArIntegrityPhpE2e10191(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        try:
            import pymysql  # type: ignore
        except ImportError as exc:
            raise unittest.SkipTest("pymysql missing") from exc
        try:
            pymysql.connect(
                host="127.0.0.1",
                port=int(PORT),
                user=USER,
                password=PASSWORD,
                charset="utf8mb4",
                connect_timeout=3,
            ).close()
        except Exception as exc:  # noqa: BLE001
            raise unittest.SkipTest(f"MySQL sandbox unavailable: {exc}") from exc

    def test_a2_cancel_billing_restores_invoiceable(self):
        so, delivery_id, inv_id = _partial_invoice("4.0000")
        # Hard delete path must cancel, not orphan
        cancelled = _php("delete_sale_draft", {"sales_doc_id": inv_id, "user_id": 2})
        self.assertTrue(cancelled.get("ok"), cancelled)
        self.assertEqual(cancelled["result"]["status"], "void")
        rows = _php(
            "query",
            {
                "sql": f"SELECT status,quantity FROM erp_o2c_invoice_links WHERE invoice_doc_id={inv_id}"
            },
        )["rows"]
        self.assertTrue(rows)
        self.assertEqual(rows[0]["status"], "cancelled")
        # Remaining 10 still invoiceable
        inv2 = _php(
            "invoice",
            {
                "sales_doc_id": so,
                "delivery_id": delivery_id,
                "user_id": 1,
                "idempotency_key": "b" * 32,
                "lines": {
                    str(int(_php("line_id", {"sales_doc_id": so})["sales_line_id"])): {
                        "quantity": "10.0000"
                    }
                },
            },
        )
        self.assertTrue(inv2.get("ok"), inv2)

    def test_a3_a4_a5_fingerprint_fiscal_map(self):
        _, _, inv_id = _partial_invoice("10.0000")
        # Invalid map: revenue account typed as asset
        bad = _php(
            "ar_set_map_custom",
            {"receivable_account_id": 1, "revenue_account_id": 4, "tax_account_id": 3},
        )
        self.assertFalse(bad.get("ok"), bad)

        assert _php("ar_set_map").get("ok")
        closed = _php("ar_close_period", {"period_key": "2026-11"})
        self.assertTrue(closed.get("ok"), closed)
        blocked = _php("ar_post", {"invoice_doc_id": inv_id, "user_id": 2, "idempotency_key": "c" * 32})
        self.assertFalse(blocked.get("ok"), blocked)
        self.assertIn("2026-11", blocked.get("error", ""))

        assert _php("ar_reopen_period", {"period_key": "2026-11"}).get("ok")
        # Tamper line but leave header — reconciliation / fingerprint must reject
        assert _php(
            "tamper_invoice_line",
            {"invoice_doc_id": inv_id, "unit_price": "999.00", "line_total": "9990.00"},
        ).get("ok")
        bad_post = _php("ar_post", {"invoice_doc_id": inv_id, "user_id": 2, "idempotency_key": "e" * 32})
        self.assertFalse(bad_post.get("ok"), bad_post)

    def test_a6_outstanding_status_and_credit(self):
        _, _, inv_id = _partial_invoice("10.0000")
        assert _php("ar_set_map").get("ok")
        assert _php("ar_reopen_period", {"period_key": "2026-11"}).get("ok")
        first = _php("ar_post", {"invoice_doc_id": inv_id, "user_id": 2, "idempotency_key": "f" * 32})
        self.assertTrue(first.get("ok"), first)
        out = _php("ar_outstanding", {"party_id": 1})
        self.assertEqual(out["result"]["outstanding"], "1000.00")
        self.assertEqual(out["result"]["status"], "OPEN")

        # Simulate credit: insert reversing AR credit line on same voucher/account (test-only)
        vid = int(first["result"]["voucher_id"])
        _php(
            "query",
            {
                "sql": "SELECT 1"
            },
        )
        # Use docker php to insert credit via raw connection in a dedicated scenario would be cleaner;
        # inject via seed runner: run SQL through a special path — add credit with temporary runner case.
        # Fallback: direct pymysql
        import pymysql

        conn = pymysql.connect(
            host="127.0.0.1",
            port=int(PORT),
            user=USER,
            password=PASSWORD,
            database=DATABASE,
            charset="utf8mb4",
            autocommit=True,
        )
        with conn.cursor() as cur:
            cur.execute(
                "INSERT INTO acc_voucher_lines (workspace_id,voucher_id,line_no,account_id,party_id,description,debit,credit,created_at) "
                "VALUES (10,%s,90,1,1,'credit test',0,1500.00,NOW())",
                (vid,),
            )
        conn.close()
        credit = _php("ar_outstanding", {"party_id": 1})
        self.assertEqual(credit["result"]["outstanding"], "-500.00")
        self.assertEqual(credit["result"]["status"], "CREDIT_BALANCE")

    def test_a7_concurrent_post_hash_safe(self):
        _, _, inv_id = _partial_invoice("10.0000")
        assert _php("ar_set_map").get("ok")
        assert _php("ar_reopen_period", {"period_key": "2026-11"}).get("ok")
        results: list[dict] = []

        def worker(sfx: str):
            results.append(
                _php(
                    "ar_post",
                    {"invoice_doc_id": inv_id, "user_id": 2, "idempotency_key": ("9" * 30) + sfx},
                )
            )

        t1 = threading.Thread(target=worker, args=("a1",))
        t2 = threading.Thread(target=worker, args=("a2",))
        t1.start()
        t2.start()
        t1.join(180)
        t2.join(180)
        oks = [r for r in results if r.get("ok")]
        self.assertGreaterEqual(len(oks), 1)
        vids = {int(r["result"]["voucher_id"]) for r in oks if r.get("result")}
        self.assertEqual(len(vids), 1)
        bal = _php("ar_voucher_balance", {"voucher_id": next(iter(vids))})
        self.assertEqual(bal["postings"], 1)
        self.assertEqual(str(bal["voucher"]["total_debit"]), str(bal["voucher"]["total_credit"]))


if __name__ == "__main__":
    unittest.main()
