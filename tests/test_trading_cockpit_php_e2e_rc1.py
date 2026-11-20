"""RC1 T3: domestic trading cockpit + TR-1 exact IRR proof (PHP+MySQL).

PO 10@1000 → GR 6 → inv 4+2 → AP 6000 → pay 2500+3500 → outstanding 0;
one stock increment; no GL on GR; cockpit timeline source-backed.
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
# Dedicated schema on the verified sandbox container to avoid cross-lane DROP races.
DATABASE = os.environ.get("O2C_DB_NAME", "erpsmart_t3_trading_test")
MYSQL_CONTAINER = "erpsmart-p2p-mysql-test"
EXPECTED_MYSQL_ID_PREFIX = "02eb215a87f6"


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
            ["docker", "inspect", MYSQL_CONTAINER, "--format", "{{.Id}} {{.Config.Image}}"],
            capture_output=True,
            text=True,
            timeout=20,
        )
        if r.returncode != 0:
            return False
        out = (r.stdout or "").strip()
        return EXPECTED_MYSQL_ID_PREFIX in out and "mysql:8.0" in out
    except Exception:
        return False


def _php(scenario: str, args: dict | None = None, timeout: int = 240) -> dict:
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
    completed = subprocess.run(
        cmd,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=timeout,
    )
    out = (completed.stdout or "").strip().splitlines()
    if not out:
        raise AssertionError(f"no_stdout rc={completed.returncode} stderr={completed.stderr}")
    return json.loads(out[-1])


class TradingCockpitPhpE2eRc1(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not _mysql_identity_ok():
            raise unittest.SkipTest(f"{MYSQL_CONTAINER} identity not verified")
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

    def test_tr1_exact_figures_and_cockpit_timeline(self):
        """PO10@1000 → GR6 → inv4+2 → AP6000 → pay2500+3500 → out0; cockpit IDs."""
        payload = _php("trading_tr1_full", {}, timeout=300)
        assert payload.get("ok"), payload

        dup_err = str(payload.get("duplicate_error", ""))
        self.assertNotEqual(dup_err, "expected_duplicate_failure")
        self.assertTrue(len(dup_err) > 5, dup_err)
        self.assertEqual(payload["outstanding_after_ap"]["outstanding"], "6000.00")
        self.assertEqual(payload["inv4_after_pay1"]["outstanding"], "1500.00")
        self.assertEqual(payload["outstanding_after_pay1"]["outstanding"], "3500.00")
        self.assertEqual(payload["inv4_final"]["outstanding"], "0.00")
        self.assertEqual(payload["inv2_final"]["outstanding"], "0.00")
        self.assertEqual(payload["outstanding_final"]["outstanding"], "0.00")
        self.assertEqual(payload["stock_movements"], 1)
        self.assertEqual(payload["receipt_gl_vouchers"], 0)

        life = payload["cockpit"]
        inv4 = int(payload["inv4"])
        inv2 = int(payload["inv2"])
        self.assertEqual(life["currency"], "IRR")
        self.assertEqual(life["quantities"]["ordered_qty"], "10.0000")
        self.assertEqual(life["quantities"]["accepted_qty"], "6.0000")
        self.assertEqual(life["quantities"]["billed_qty"], "6.0000")
        self.assertEqual(life["totals"]["ap_posted_irr"], "6000.00")
        self.assertEqual(life["totals"]["ap_outstanding_irr"], "0.00")
        self.assertEqual(life["totals"]["stock_movements"], 1)
        self.assertEqual(life["totals"]["receipt_gl_vouchers"], 0)
        self.assertTrue(life["nonclaims"]["no_grni"])
        self.assertTrue(life["nonclaims"]["no_fx_gl"])

        steps = [t["step"] for t in life["timeline"]]
        self.assertIn("purchase_order", steps)
        self.assertIn("goods_receipt", steps)
        self.assertIn("supplier_bill", steps)
        self.assertIn("ap_voucher", steps)
        self.assertIn("payment", steps)
        self.assertEqual(len(life["receipts"]), 1)
        self.assertEqual(life["receipts"][0]["accepted_qty"], "6.0000")
        self.assertFalse(life["receipts"][0]["gl_on_receipt"])
        self.assertEqual(len(life["ap_invoices"]), 2)
        self.assertEqual({inv["invoice_doc_id"] for inv in life["ap_invoices"]}, {inv4, inv2})
        for inv in life["ap_invoices"]:
            self.assertEqual(inv["outstanding_irr"], "0.00")
            self.assertIsNotNone(inv["voucher_id"])
        self.assertGreaterEqual(len(life["payments"]), 2)

        self.assertGreaterEqual(len(payload["search"]["rows"]), 1)
        self.assertTrue(payload["dup"]["duplicate"])
        self.assertEqual(payload["foreign"]["status"], "DRAFT_READ_ONLY_FUTURE")
        self.assertFalse(payload["foreign"]["rc1_required"])
        self.assertIn("fx_gl_posting", payload["foreign"]["blocked_until_verified"])


if __name__ == "__main__":
    unittest.main()
