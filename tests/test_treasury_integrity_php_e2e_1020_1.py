"""PHP+MySQL E2E for v10.20.1 treasury integrity (cash GL map, historical reverse, sync)."""
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
    assert _php("ar_set_cash_account_gl", {"cash_account_id": 1, "gl_account_id": 6}).get("ok")
    assert _php("ar_set_cash_account_gl", {"cash_account_id": 2, "gl_account_id": 7}).get("ok")
    posted = _php("ar_post", {"invoice_doc_id": inv_id, "user_id": 2, "idempotency_key": "c" * 32})
    assert posted.get("ok"), posted
    return inv_id


def _post_receipt(cash_account_id: int, amount: str, key: str) -> dict:
    draft = _php(
        "receipt_draft",
        {
            "user_id": 1,
            "party_id": 1,
            "cash_account_id": cash_account_id,
            "payment_method": "bank_transfer",
            "receipt_date": "2026-11-20",
            "amount": amount,
            "idempotency_key": key,
        },
    )
    assert draft.get("ok"), draft
    rid = int(draft["result"]["id"])
    assert _php("receipt_approve", {"receipt_id": rid, "user_id": 2}).get("ok")
    posted = _php("receipt_post", {"receipt_id": rid, "user_id": 2})
    assert posted.get("ok"), posted
    return posted


class TreasuryIntegrityPhpE2e10201(unittest.TestCase):
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

    def test_a2_distinct_bank_gl_accounts(self):
        _posted_invoice()
        p1 = _post_receipt(1, "100.00", "aa" * 16)
        p2 = _post_receipt(2, "50.00", "bb" * 16)
        self.assertEqual(int(p1["result"]["cash_gl_account_id"]), 6)
        self.assertEqual(int(p2["result"]["cash_gl_account_id"]), 7)
        lines1 = _php("voucher_lines", {"voucher_id": int(p1["result"]["voucher_id"])})["rows"]
        lines2 = _php("voucher_lines", {"voucher_id": int(p2["result"]["voucher_id"])})["rows"]
        self.assertEqual(int(lines1[0]["account_id"]), 6)
        self.assertEqual(int(lines2[0]["account_id"]), 7)

    def test_a3_a4_reverse_after_map_change_uses_historical(self):
        inv_id = _posted_invoice()
        posted = _post_receipt(1, "400.00", "cc" * 16)
        rid = int(posted["result"]["id"])
        vid = int(posted["result"]["voucher_id"])
        self.assertEqual(int(posted["result"]["cash_gl_account_id"]), 6)
        # Change mapping Bank1 → GL7
        assert _php("ar_set_cash_account_gl", {"cash_account_id": 1, "gl_account_id": 7}).get("ok")
        rev = _php(
            "receipt_reverse",
            {"receipt_id": rid, "user_id": 2, "reversal_date": "2026-11-21"},
        )
        self.assertTrue(rev.get("ok"), rev)
        self.assertEqual(int(rev["result"]["cash_gl_account_id"]), 6)  # historical A, not B
        rev_lines = _php("voucher_lines", {"voucher_id": int(rev["result"]["reversal_voucher_id"])})["rows"]
        # Cr cash historical = account 6
        self.assertEqual(int(rev_lines[1]["account_id"]), 6)
        txs = _php("cash_tx_status", {"source_id": rid})["rows"]
        statuses = {r["source_type"]: r["status"] for r in txs}
        self.assertEqual(statuses.get("ar_receipt"), "reversed")
        self.assertEqual(statuses.get("ar_receipt_reversal"), "posted")
        # Invoice reopen after reverse (no allocations in this test)
        st = _php("invoice_settlement", {"invoice_doc_id": inv_id})
        self.assertEqual(st["result"]["status"], "OPEN")

    def test_a5_cheque_method_rejected(self):
        _posted_invoice()
        bad = _php(
            "receipt_draft",
            {
                "user_id": 1,
                "party_id": 1,
                "cash_account_id": 1,
                "payment_method": "cheque",
                "receipt_date": "2026-11-20",
                "amount": "10.00",
                "idempotency_key": "dd" * 16,
            },
        )
        self.assertFalse(bad.get("ok"), bad)

    def test_a7_customer_vs_invoice_breakdown(self):
        inv_id = _posted_invoice()
        posted = _post_receipt(1, "400.00", "ee" * 16)
        rid = int(posted["result"]["id"])
        alloc = _php(
            "allocate",
            {
                "receipt_id": rid,
                "invoice_doc_id": inv_id,
                "amount": "400.00",
                "user_id": 2,
                "idempotency_key": "ff" * 16,
            },
        )
        self.assertTrue(alloc.get("ok"), alloc)
        br = _php("customer_breakdown", {"party_id": 1})
        self.assertTrue(br.get("ok"), br)
        self.assertEqual(br["result"]["unapplied_receipt_credit"], "0.00")
        self.assertEqual(br["result"]["open_invoice_total"], "600.00")
        self.assertEqual(br["result"]["customer_ar_balance"], "600.00")
        self.assertFalse(br["result"]["bank_reconciled"])


if __name__ == "__main__":
    unittest.main()
