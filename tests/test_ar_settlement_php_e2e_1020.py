"""Real PHP+MySQL E2E for v10.20 AR settlement lifecycle."""
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


def _posted_invoice() -> int:
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
    inv = _php(
        "invoice",
        {
            "sales_doc_id": so,
            "delivery_id": int(deliv["result"]["id"]),
            "user_id": 1,
            "idempotency_key": "a" * 32,
            "lines": {str(line): {"quantity": "10.0000"}},
        },
    )
    inv_id = int(inv["result"]["id"])
    assert _php("ar_set_map").get("ok")
    assert _php("ar_set_cash_gl", {"cash_gl_account_id": 6}).get("ok")
    posted = _php("ar_post", {"invoice_doc_id": inv_id, "user_id": 2, "idempotency_key": "c" * 32})
    assert posted.get("ok"), posted
    return inv_id


class ArSettlementPhpE2e1020(unittest.TestCase):
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

    def test_partial_then_full_settlement(self):
        inv_id = _posted_invoice()
        draft = _php(
            "receipt_draft",
            {
                "user_id": 1,
                "party_id": 1,
                "cash_account_id": 1,
                "receipt_date": "2026-11-20",
                "amount": "400.00",
                "idempotency_key": "ab" * 16,
            },
        )
        self.assertTrue(draft.get("ok"), draft)
        rid = int(draft["result"]["id"])
        self.assertTrue(_php("receipt_approve", {"receipt_id": rid, "user_id": 2}).get("ok"))
        posted = _php("receipt_post", {"receipt_id": rid, "user_id": 2})
        self.assertTrue(posted.get("ok"), posted)
        self.assertFalse(posted["result"].get("bank_reconciled", True))

        alloc = _php(
            "allocate",
            {
                "receipt_id": rid,
                "invoice_doc_id": inv_id,
                "amount": "400.00",
                "user_id": 2,
                "idempotency_key": "cd" * 16,
            },
        )
        self.assertTrue(alloc.get("ok"), alloc)
        self.assertEqual(alloc["result"]["invoice"]["status"], "PARTIALLY_SETTLED")
        self.assertEqual(alloc["result"]["invoice"]["open_amount"], "600.00")

        # Over-allocate must fail
        bad = _php(
            "allocate",
            {"receipt_id": rid, "invoice_doc_id": inv_id, "amount": "1.00", "user_id": 2},
        )
        self.assertFalse(bad.get("ok"), bad)

        # Second receipt 600 → SETTLED
        d2 = _php(
            "receipt_draft",
            {
                "user_id": 1,
                "party_id": 1,
                "cash_account_id": 1,
                "receipt_date": "2026-11-20",
                "amount": "600.00",
                "idempotency_key": "ef" * 16,
            },
        )
        rid2 = int(d2["result"]["id"])
        assert _php("receipt_approve", {"receipt_id": rid2, "user_id": 2}).get("ok")
        assert _php("receipt_post", {"receipt_id": rid2, "user_id": 2}).get("ok")
        a2 = _php(
            "allocate",
            {
                "receipt_id": rid2,
                "invoice_doc_id": inv_id,
                "amount": "600.00",
                "user_id": 2,
                "idempotency_key": "11" * 16,
            },
        )
        self.assertTrue(a2.get("ok"), a2)
        self.assertEqual(a2["result"]["invoice"]["status"], "SETTLED")
        self.assertEqual(a2["result"]["invoice"]["open_amount"], "0.00")

        # Idempotent retry
        again = _php(
            "allocate",
            {
                "receipt_id": rid2,
                "invoice_doc_id": inv_id,
                "amount": "600.00",
                "user_id": 2,
                "idempotency_key": "11" * 16,
            },
        )
        self.assertTrue(again.get("ok"), again)
        self.assertTrue(again["result"]["existing"])

        out = _php("ar_outstanding", {"party_id": 1})
        self.assertEqual(out["result"]["outstanding"], "0.00")
        self.assertEqual(out["result"]["status"], "SETTLED")

    def test_maker_checker_and_cross_customer_blocked(self):
        inv_id = _posted_invoice()
        draft = _php(
            "receipt_draft",
            {
                "user_id": 1,
                "party_id": 1,
                "cash_account_id": 1,
                "receipt_date": "2026-11-20",
                "amount": "100.00",
                "idempotency_key": "22" * 16,
            },
        )
        rid = int(draft["result"]["id"])
        # Creator cannot approve
        denied = _php("receipt_approve", {"receipt_id": rid, "user_id": 1})
        self.assertFalse(denied.get("ok"), denied)


if __name__ == "__main__":
    unittest.main()
