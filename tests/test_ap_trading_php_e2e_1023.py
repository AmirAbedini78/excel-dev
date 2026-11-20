"""Domestic trading E2E: PO→GR→3WM→AP post→pay→allocate→settle (v10.23)."""
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
        "tests/harness/ap_e2e_runner.php",
        scenario,
        json.dumps(args or {}, ensure_ascii=False),
    ]
    completed = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    out = (completed.stdout or "").strip().splitlines()
    if not out:
        raise AssertionError(f"no_stdout rc={completed.returncode} stderr={completed.stderr}")
    return json.loads(out[-1])


def _post_full(invoice_id: int, key: str) -> dict:
    assert _php("ap_validate", {"invoice_doc_id": invoice_id, "user_id": 3}).get("ok")
    assert _php("ap_approve", {"invoice_doc_id": invoice_id, "user_id": 1}).get("ok")
    posted = _php("ap_post", {"invoice_doc_id": invoice_id, "user_id": 3, "idempotency_key": key})
    assert posted.get("ok"), posted
    return posted


def _pay(amount: str, key: str) -> int:
    draft = _php(
        "payment_draft",
        {
            "user_id": 1,
            "party_id": 1,
            "cash_account_id": 1,
            "payment_method": "bank_transfer",
            "payment_date": "2026-11-20",
            "amount": amount,
            "idempotency_key": key,
        },
    )
    assert draft.get("ok"), draft
    rid = int(draft["result"]["id"])
    assert _php("payment_approve", {"payment_id": rid, "user_id": 2}).get("ok")
    posted = _php("payment_post", {"payment_id": rid, "user_id": 2})
    assert posted.get("ok"), posted
    return rid


class ApTradingPhpE2e1023(unittest.TestCase):
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

    def test_domestic_trading_settle_6000(self):
        """PO 10×1000; GR 6; inv 4+2; AP 6000; pay 2500→3500; pay 3500→0. No VAT invented."""
        seed = _php("seed_po", {"created_by": 1, "qty": "10.0000", "price": "1000.00"})
        po = int(seed["po_id"])
        assert _php("approve_po", {"po_id": po, "user_id": 2}).get("ok")
        assert _php("receipt", {"po_id": po, "qty": "6.0000"}).get("ok")
        line = int(_php("line_id", {"po_id": po})["purchase_line_id"])
        b1 = _php(
            "submit_bill",
            {
                "po_id": po,
                "user_id": 1,
                "invoice_no": "T-INV-4",
                "lines": {str(line): {"quantity": "4.0000", "unit_price": "1000.00"}},
            },
        )
        assert b1.get("ok"), b1
        inv4 = int(_php("approve_bill", {"bill_id": int(b1["result"]["id"]), "user_id": 2})["result"]["id"])
        b2 = _php(
            "submit_bill",
            {
                "po_id": po,
                "user_id": 1,
                "invoice_no": "T-INV-2",
                "lines": {str(line): {"quantity": "2.0000", "unit_price": "1000.00"}},
            },
        )
        assert b2.get("ok"), b2
        inv2 = int(_php("approve_bill", {"bill_id": int(b2["result"]["id"]), "user_id": 2})["result"]["id"])
        assert _php("ap_set_map").get("ok")
        _post_full(inv4, "a" * 32)
        _post_full(inv2, "b" * 32)
        self.assertEqual(_php("ap_outstanding", {"party_id": 1})["result"]["outstanding"], "6000.00")

        pay1 = _pay("2500.00", "c" * 32)
        # allocate 2500 to inv4
        alloc1 = _php(
            "ap_allocate",
            {
                "payment_id": pay1,
                "invoice_doc_id": inv4,
                "amount": "2500.00",
                "idempotency_key": "d" * 32,
                "user_id": 1,
            },
        )
        assert alloc1.get("ok"), alloc1
        self.assertEqual(_php("ap_invoice_outstanding", {"invoice_doc_id": inv4})["result"]["outstanding"], "1500.00")
        self.assertEqual(_php("ap_outstanding", {"party_id": 1})["result"]["outstanding"], "3500.00")

        pay2 = _pay("3500.00", "e" * 32)
        # settle remaining: 1500 on inv4 + 2000 on inv2
        assert _php(
            "ap_allocate",
            {
                "payment_id": pay2,
                "invoice_doc_id": inv4,
                "amount": "1500.00",
                "idempotency_key": "f" * 32,
                "user_id": 1,
            },
        ).get("ok")
        assert _php(
            "ap_allocate",
            {
                "payment_id": pay2,
                "invoice_doc_id": inv2,
                "amount": "2000.00",
                "idempotency_key": "1" * 32,
                "user_id": 1,
            },
        ).get("ok")
        self.assertEqual(_php("ap_invoice_outstanding", {"invoice_doc_id": inv4})["result"]["outstanding"], "0.00")
        self.assertEqual(_php("ap_invoice_outstanding", {"invoice_doc_id": inv2})["result"]["outstanding"], "0.00")
        out = _php("ap_outstanding", {"party_id": 1})["result"]
        self.assertEqual(out["outstanding"], "0.00")
        self.assertIn(out["status"], ("SETTLED", "OPEN"))
        counts = _php("stock_and_gl_counts")
        self.assertEqual(counts["stock_movements"], 1)
        self.assertEqual(counts["receipt_gl_vouchers"], 0)
        # cheque rejected
        bad = _php(
            "payment_draft",
            {
                "user_id": 1,
                "party_id": 1,
                "cash_account_id": 1,
                "payment_method": "cheque",
                "amount": "10.00",
                "idempotency_key": "2" * 32,
            },
        )
        self.assertFalse(bad.get("ok"), bad)
        # payment draft ≠ settled (create draft without post)
        draft = _php(
            "payment_draft",
            {
                "user_id": 1,
                "party_id": 1,
                "cash_account_id": 1,
                "amount": "100.00",
                "idempotency_key": "3" * 32,
            },
        )
        assert draft.get("ok"), draft
        self.assertEqual(_php("ap_outstanding", {"party_id": 1})["result"]["outstanding"], "0.00")


if __name__ == "__main__":
    unittest.main()
