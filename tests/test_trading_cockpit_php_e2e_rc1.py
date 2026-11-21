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
PORT = os.environ.get("O2C_DB_PORT", "3307")
USER = os.environ.get("O2C_DB_USER", "p2p")
PASSWORD = os.environ.get("O2C_DB_PASS", "p2p_test")
MYSQL_CONTAINER = "erpsmart-p2p-mysql-test"


def _db_name() -> str:
    # Dedicated schema on the verified sandbox; call-time for gate isolation.
    return os.environ.get("O2C_DB_NAME", "erpsmart_t3_trading_test")
EXPECTED_MYSQL_ID_PREFIX = os.environ.get("RC1_MYSQL_CONTAINER_ID_PREFIX", "ab78c5c42f00")[:12]


def _runner_db_host() -> str:
    """Host-side (pymysql / runner) MySQL hostname — may be 127.0.0.1 on GHA."""
    return (os.environ.get("O2C_DB_HOST") or "127.0.0.1").strip()


def _php_db_host() -> str:
    """In-container hostname for PHP Docker — never forward loopback blindly."""
    explicit = (os.environ.get("O2C_DB_HOST_PHP") or "").strip()
    if explicit:
        return explicit
    host = _runner_db_host()
    if host in {"127.0.0.1", "localhost", "::1"}:
        return "host.docker.internal"
    return host or "host.docker.internal"


# Backward-compatible alias; prefer _php_db_host() at call time.
HOST = os.environ.get("O2C_DB_HOST_PHP") or os.environ.get("O2C_DB_HOST", "host.docker.internal")


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
    # CI / alternate disposable MySQL: require connectivity only (see workflow rc1-mysql-php-gate).
    if os.environ.get("RC1_ALLOW_ANY_MYSQL", "").strip() in {"1", "true", "TRUE", "yes"}:
        return True
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
    php_host = _php_db_host()
    cmd = [
        "docker",
        "run",
        "--rm",
        "--add-host=host.docker.internal:host-gateway",
        "-e",
        f"O2C_DB_HOST={php_host}",
        "-e",
        f"O2C_DB_PORT={PORT}",
        "-e",
        f"O2C_DB_USER={USER}",
        "-e",
        f"O2C_DB_PASS={PASSWORD}",
        "-e",
        f"O2C_DB_NAME={_db_name()}",
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
    # Force UTF-8: Windows default console encoding (e.g. cp1256) mojibake-s Persian JSON.
    completed = subprocess.run(
        cmd, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=timeout
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
                host=_runner_db_host(),
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
        import unicodedata

        payload = _php("trading_tr1_full", {}, timeout=300)
        assert payload.get("ok"), payload

        # Prefer machine-readable duplicate code; Persian UI text remains for humans (NFC-safe).
        self.assertEqual(payload.get("duplicate_error_code"), "P2P_SUPPLIER_INVOICE_DUPLICATE", payload)
        dup_msg = unicodedata.normalize("NFC", str(payload.get("duplicate_error", "")))
        self.assertIn("P2P_SUPPLIER_INVOICE_DUPLICATE", dup_msg)
        # Soft Persian check: strip yeh variants (U+06CC / U+064A) before substring match.
        soft = dup_msg.replace("\u064a", "\u06cc")
        self.assertTrue(
            "تکراری" in soft or "تکراري" in soft or "تکرار" in soft,
            f"duplicate Persian missing: {dup_msg!r}",
        )
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
