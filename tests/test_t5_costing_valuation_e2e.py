"""T5 RC1 costing/valuation E2E — PHP domain + disposable MySQL (no GRNI, WIP/FG BLOCKED)."""
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
# Lane-isolated disposable DB on the verified sandbox container (avoids cross-lane DROP TABLE races).
DATABASE = os.environ.get("O2C_DB_NAME", "erpsmart_t5_test")
MYSQL_CONTAINER = "erpsmart-p2p-mysql-test"
MYSQL_CONTAINER_ID_PREFIX = "02eb215a87f6"


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


def _mysql_identity_ok() -> bool:
    try:
        r = subprocess.run(
            ["docker", "inspect", "-f", "{{.Id}} {{.Name}}", MYSQL_CONTAINER],
            capture_output=True,
            text=True,
            timeout=20,
        )
        if r.returncode != 0:
            return False
        out = (r.stdout or "").strip()
        return MYSQL_CONTAINER_ID_PREFIX in out and MYSQL_CONTAINER in out
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
        "tests/harness/t5_costing_e2e_runner.php",
        scenario,
        json.dumps(args or {}, ensure_ascii=False),
    ]
    completed = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    out = (completed.stdout or "").strip().splitlines()
    if not out:
        raise AssertionError(f"no_stdout rc={completed.returncode} stderr={completed.stderr}")
    try:
        payload = json.loads(out[-1])
    except json.JSONDecodeError as exc:
        raise AssertionError(f"bad_json rc={completed.returncode} stdout={out[-3:]} stderr={completed.stderr}") from exc
    return payload


def _matched_invoice() -> tuple[int, int]:
    """PO 10 @ 1000, GR 6, invoice 4 → matched draft. Returns (po_id, inv4)."""
    seed = _php("seed_po", {"created_by": 1, "qty": "10.0000", "price": "1000.00"})
    assert seed.get("ok"), seed
    po = int(seed["po_id"])
    assert _php("approve_po", {"po_id": po, "user_id": 2}).get("ok")
    assert _php("receipt", {"po_id": po, "qty": "6.0000"}).get("ok")
    line = int(_php("line_id", {"po_id": po})["purchase_line_id"])
    b1 = _php(
        "submit_bill",
        {
            "po_id": po,
            "user_id": 1,
            "invoice_no": "INV-T5-4",
            "lines": {str(line): {"quantity": "4.0000", "unit_price": "1000.00"}},
        },
    )
    assert b1.get("ok"), b1
    a1 = _php("approve_bill", {"bill_id": int(b1["result"]["id"]), "user_id": 2})
    assert a1.get("ok"), a1
    inv4 = int(a1["result"]["id"])
    assert _php("ap_set_map").get("ok")
    return po, inv4


def _post_full(invoice_id: int, key: str) -> dict:
    assert _php("ap_validate", {"invoice_doc_id": invoice_id, "user_id": 3}).get("ok")
    assert _php("ap_approve", {"invoice_doc_id": invoice_id, "user_id": 1}).get("ok")
    posted = _php("ap_post", {"invoice_doc_id": invoice_id, "user_id": 3, "idempotency_key": key})
    assert posted.get("ok"), posted
    return posted


class T5CostingValuationE2e(unittest.TestCase):
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
        if not _mysql_identity_ok():
            raise unittest.SkipTest("refusing MySQL without verified erpsmart-p2p-mysql-test identity")
        if "test" not in DATABASE.lower() and "e2e" not in DATABASE.lower() and "p2p" not in DATABASE.lower():
            raise unittest.SkipTest("refusing non-disposable database name")

    def test_01_receipt_no_gl_invoice_ap_once(self):
        po, inv4 = _matched_invoice()
        counts_before = _php("stock_and_gl_counts")
        self.assertEqual(counts_before["stock_movements"], 1)
        self.assertEqual(counts_before["receipt_gl_vouchers"], 0)
        self.assertEqual(counts_before["ap_vouchers"], 0)

        posted = _post_full(inv4, "a" * 32)
        self.assertFalse(posted["result"].get("existing"))
        vid = int(posted["result"]["voucher_id"])

        again = _php("ap_post", {"invoice_doc_id": inv4, "user_id": 3, "idempotency_key": "a" * 32})
        self.assertTrue(again.get("ok"), again)
        self.assertTrue(again["result"].get("existing"))
        self.assertEqual(int(again["result"]["voucher_id"]), vid)

        counts = _php("stock_and_gl_counts")
        self.assertEqual(counts["stock_movements"], 1)
        self.assertEqual(counts["ap_vouchers"], 1)
        self.assertEqual(counts["ap_postings"], 1)
        self.assertEqual(counts["receipt_gl_vouchers"], 0)
        self.assertIsNotNone(po)

        dups = _php("duplicate_keys")["result"]
        self.assertTrue(dups["ok"], dups)
        self.assertEqual(dups["receipt_gl_voucher_count"], 0)

    def test_02_cost_unvalued_when_missing_evidence(self):
        assert _php("reset").get("ok")
        out = _php("seed_unvalued_issue", {"production_order_id": 9001, "qty": "2.0000"})
        self.assertTrue(out.get("ok"), out)
        resolved = out["resolved"]
        self.assertEqual(resolved["classification"], "UNVALUED")
        self.assertFalse(resolved.get("is_actual"))
        self.assertIsNone(resolved.get("unit_cost_irr"))
        self.assertIsNone(resolved.get("total_cost_irr"))
        rollup = out["rollup"]
        self.assertEqual(rollup["classification"], "UNVALUED")
        self.assertEqual(rollup["gl_valuation"], "BLOCKED")
        self.assertFalse(rollup["gl_posted"])
        self.assertIsNone(rollup.get("posted_actual_total_irr"))

    def test_03_posted_actual_rollup_fg_blocked(self):
        _, inv4 = _matched_invoice()
        posted = _post_full(inv4, "b" * 32)
        self.assertFalse(posted["result"].get("existing"))
        flow = _php(
            "seed_actual_issue_flow",
            {"production_order_id": 9100, "qty": "2.0000", "fg_qty": "1.0000"},
        )
        self.assertTrue(flow.get("ok"), flow)
        resolved = flow["resolved"]
        self.assertEqual(resolved["classification"], "POSTED_ACTUAL")
        self.assertTrue(resolved["is_actual"])
        self.assertEqual(resolved["unit_cost_irr"], "1000.00")
        self.assertEqual(resolved["total_cost_irr"], "2000.00")
        self.assertEqual(int(resolved["invoice_doc_id"]), inv4)
        self.assertEqual(int(resolved["voucher_id"]), int(posted["result"]["voucher_id"]))

        fg = flow["fg_resolved"]
        self.assertEqual(fg["classification"], "BLOCKED")
        self.assertEqual(fg["gl_valuation"], "BLOCKED")

        rollup = flow["rollup"]
        self.assertEqual(rollup["classification"], "POSTED_ACTUAL")
        self.assertEqual(rollup["posted_actual_total_irr"], "2000.00")
        self.assertEqual(rollup["fg_unit_cost_posted_actual_irr"], "2000.00")
        self.assertEqual(rollup["gl_valuation"], "BLOCKED")
        self.assertFalse(rollup["gl_posted"])

        policy = _php("wip_fg_policy")["result"]
        self.assertEqual(policy["policy"], "BLOCKED")
        self.assertFalse(policy["enabled"])
        attempt = _php("attempt_wip_fg_gl", {"production_order_id": 9100})["result"]
        self.assertFalse(attempt["posted"])
        self.assertEqual(attempt["classification"], "BLOCKED")
        self.assertIsNone(attempt["voucher_id"])

    def test_04_reconcile_and_no_duplicate_keys(self):
        _, inv4 = _matched_invoice()
        posted = _post_full(inv4, "c" * 32)
        vid = int(posted["result"]["voucher_id"])
        idem = _php("idempotent_repost", {"invoice_doc_id": inv4, "idempotency_key": "c" * 32})
        self.assertTrue(idem.get("ok"), idem)
        self.assertTrue(idem["second"].get("existing"))
        self.assertEqual(int(idem["second"]["voucher_id"]), vid)
        self.assertTrue(idem["duplicates"]["ok"], idem["duplicates"])

        recon = _php("reconcile")["result"]
        self.assertTrue(recon["balanced"], recon.get("failures"))
        self.assertEqual(recon["max_abs_diff_irr"], "0.00")
        ap_rows = [r for r in recon["rows"] if r.get("scope") == "ap_invoice"]
        self.assertGreaterEqual(len(ap_rows), 1)
        self.assertEqual(ap_rows[0]["diff_posted_net_minus_voucher_ap_irr"], "0.00")
        self.assertEqual(ap_rows[0]["posted_net_irr"], "4000.00")
        voucher_rows = [r for r in recon["rows"] if r.get("scope") == "voucher_balance"]
        self.assertTrue(all(r["ok"] for r in voucher_rows), voucher_rows)


if __name__ == "__main__":
    unittest.main()
