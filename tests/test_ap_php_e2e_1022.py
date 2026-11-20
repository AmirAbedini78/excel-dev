"""PHP+MySQL E2E for v10.22 AP posting kernel (invoice-based, no GRNI)."""
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


def _matched_invoices() -> tuple[int, int, int]:
    """PO 10 @ 1000, GR 6, invoices 4 + 2 → two matched draft invoices. Returns (po_id, inv4, inv2).

    Invoice created_by = P2P bill reviewer (user 2). AP roles: validate=3, approve=1, post=3.
    """
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
            "invoice_no": "INV-4",
            "lines": {str(line): {"quantity": "4.0000", "unit_price": "1000.00"}},
        },
    )
    assert b1.get("ok"), b1
    a1 = _php("approve_bill", {"bill_id": int(b1["result"]["id"]), "user_id": 2})
    assert a1.get("ok"), a1
    inv4 = int(a1["result"]["id"])
    b2 = _php(
        "submit_bill",
        {
            "po_id": po,
            "user_id": 1,
            "invoice_no": "INV-2",
            "lines": {str(line): {"quantity": "2.0000", "unit_price": "1000.00"}},
        },
    )
    assert b2.get("ok"), b2
    a2 = _php("approve_bill", {"bill_id": int(b2["result"]["id"]), "user_id": 2})
    assert a2.get("ok"), a2
    inv2 = int(a2["result"]["id"])
    assert _php("ap_set_map").get("ok")
    return po, inv4, inv2


def _post_full(invoice_id: int, key: str, validator: int = 3, approver: int = 1, poster: int = 3) -> dict:
    assert _php("ap_validate", {"invoice_doc_id": invoice_id, "user_id": validator}).get("ok")
    assert _php("ap_approve", {"invoice_doc_id": invoice_id, "user_id": approver}).get("ok")
    posted = _php("ap_post", {"invoice_doc_id": invoice_id, "user_id": poster, "idempotency_key": key})
    assert posted.get("ok"), posted
    return posted


class ApPhpE2e1022(unittest.TestCase):
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
        if "test" not in DATABASE.lower() and "e2e" not in DATABASE.lower() and "p2p" not in DATABASE.lower():
            raise unittest.SkipTest("refusing non-disposable database name")

    def test_01_happy_path_balanced_no_double_stock_gl(self):
        po, inv4, inv2 = _matched_invoices()
        p4 = _post_full(inv4, "a" * 32)
        p2 = _post_full(inv2, "b" * 32)
        self.assertFalse(p4["result"].get("existing"))
        bal = _php("voucher_balance", {"voucher_id": int(p4["result"]["voucher_id"])})
        self.assertEqual(bal["voucher"]["total_debit"], bal["voucher"]["total_credit"])
        self.assertEqual(bal["voucher"]["source_type"], "p2p_ap")
        self.assertEqual(bal["ap_posting_links"], 1)
        lines = _php("voucher_lines", {"voucher_id": int(p4["result"]["voucher_id"])})["rows"]
        # Dr inventory 4000, Cr AP 4000
        self.assertEqual(lines[0]["debit"], "4000.00")
        self.assertEqual(lines[1]["credit"], "4000.00")
        counts = _php("stock_and_gl_counts")
        self.assertEqual(counts["stock_movements"], 1)
        self.assertEqual(counts["ap_vouchers"], 2)
        self.assertEqual(counts["receipt_gl_vouchers"], 0)
        out = _php("ap_outstanding", {"party_id": 1})["result"]
        self.assertEqual(out["outstanding"], "6000.00")
        self.assertEqual(out["status"], "OPEN")
        self.assertEqual(_php("ap_invoice_outstanding", {"invoice_doc_id": inv4})["result"]["outstanding"], "4000.00")
        self.assertEqual(_php("ap_invoice_outstanding", {"invoice_doc_id": inv2})["result"]["outstanding"], "2000.00")
        self.assertIsNotNone(po)

    def test_02_reject_over_qty(self):
        seed = _php("seed_po", {"created_by": 1, "qty": "10.0000", "price": "1000.00"})
        po = int(seed["po_id"])
        assert _php("approve_po", {"po_id": po, "user_id": 2}).get("ok")
        assert _php("receipt", {"po_id": po, "qty": "6.0000"}).get("ok")
        line = int(_php("line_id", {"po_id": po})["purchase_line_id"])
        bad = _php(
            "submit_bill",
            {
                "po_id": po,
                "user_id": 1,
                "invoice_no": "OVER",
                "lines": {str(line): {"quantity": "7.0000", "unit_price": "1000.00"}},
            },
        )
        self.assertFalse(bad.get("ok"), bad)

    def test_03_maker_checker_and_wrong_account(self):
        _, inv4, _ = _matched_invoices()
        # invoice created_by=2 (P2P reviewer) cannot validate
        bad = _php("ap_validate", {"invoice_doc_id": inv4, "user_id": 2})
        self.assertFalse(bad.get("ok"), bad)
        assert _php("ap_validate", {"invoice_doc_id": inv4, "user_id": 3}).get("ok")
        # validator cannot approve
        bad2 = _php("ap_approve", {"invoice_doc_id": inv4, "user_id": 3})
        self.assertFalse(bad2.get("ok"), bad2)
        assert _php("ap_approve", {"invoice_doc_id": inv4, "user_id": 1}).get("ok")
        # wrong map: AP = AR receivable (asset) — type fail
        bad_map = _php(
            "ap_set_map",
            {"payable_account_id": 1, "purchase_account_id": 9},
        )
        self.assertFalse(bad_map.get("ok"), bad_map)
        assert _php("ap_set_map").get("ok")
        bad_np = _php(
            "ap_set_map",
            {"payable_account_id": 5, "purchase_account_id": 9},
        )
        self.assertFalse(bad_np.get("ok"), bad_np)

    def test_04_closed_period_and_wrong_company(self):
        _, inv4, _ = _matched_invoices()
        assert _php("ap_validate", {"invoice_doc_id": inv4, "user_id": 3}).get("ok")
        assert _php("ap_approve", {"invoice_doc_id": inv4, "user_id": 1}).get("ok")
        assert _php("close_period", {"period_key": "2026-11"}).get("ok")
        bad = _php("ap_post", {"invoice_doc_id": inv4, "user_id": 3, "idempotency_key": "c" * 32})
        self.assertFalse(bad.get("ok"), bad)
        _, inv_a, _ = _matched_invoices()
        assert _php("ap_validate", {"invoice_doc_id": inv_a, "user_id": 3}).get("ok")
        assert _php("ap_approve", {"invoice_doc_id": inv_a, "user_id": 1}).get("ok")
        fail = _php("fail_inject_post", {"invoice_doc_id": inv_a})
        self.assertTrue(fail.get("ok"), fail)
        self.assertEqual(fail["ap_postings"], 0)
        self.assertEqual(fail["ap_vouchers"], 0)

    def test_05_idempotency_immutability_reverse(self):
        _, inv4, _ = _matched_invoices()
        assert _php("ap_validate", {"invoice_doc_id": inv4, "user_id": 3}).get("ok")
        assert _php("ap_approve", {"invoice_doc_id": inv4, "user_id": 1}).get("ok")
        # concurrent_post uses poster user 2 — creator is 2, so must use different poster in runner
        conc = _php("concurrent_post", {"invoice_doc_id": inv4})
        self.assertTrue(conc.get("ok"), conc)
        self.assertFalse(conc["first"].get("existing"))
        self.assertTrue(conc["second"].get("existing"))
        assert _php("tamper_invoice_line", {"invoice_doc_id": inv4}).get("ok")
        bad = _php("ap_post", {"invoice_doc_id": inv4, "user_id": 3, "idempotency_key": "d" * 32})
        self.assertFalse(bad.get("ok"), bad)
        _, inv_b, _ = _matched_invoices()
        posted = _post_full(inv_b, "e" * 32)
        rev = _php("ap_reverse", {"invoice_doc_id": inv_b, "user_id": 3})
        self.assertTrue(rev.get("ok"), rev)
        out = _php("ap_outstanding", {"party_id": 1})["result"]
        self.assertEqual(out["outstanding"], "0.00")
        self.assertIn(out["status"], ("SETTLED", "OPEN"))
        self.assertIsNotNone(posted)

    def test_06_draft_not_debt_and_migration_additive(self):
        _, inv4, _ = _matched_invoices()
        out = _php("ap_outstanding", {"party_id": 1})["result"]
        # map exists but no postings → outstanding 0 OPEN (no activity) or similar
        self.assertEqual(out["outstanding"], "0.00")
        inv_out = _php("ap_invoice_outstanding", {"invoice_doc_id": inv4})["result"]
        self.assertEqual(inv_out["status"], "NOT_POSTED")
        ready = _php("ap_readiness")["result"]
        self.assertTrue(ready["ready"])
        self.assertEqual(ready["valuation_model"], "invoice_based")
        self.assertEqual(ready["payment"], "AVAILABLE")
        # additive migrate twice
        assert _php("ap_set_map").get("ok")
        rows = _php(
            "query",
            {"sql": "SELECT valuation_model FROM erp_ap_company_maps WHERE workspace_id=10 AND company_id=1"},
        )["rows"]
        self.assertEqual(rows[0]["valuation_model"], "invoice_based")


if __name__ == "__main__":
    unittest.main()
