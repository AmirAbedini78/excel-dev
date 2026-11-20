"""v10.18 Order-to-Cash source contracts: states, reservation/delivery gates, draft invoice, AR blocked."""
from __future__ import annotations

import pathlib
import re
import shutil
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


def source(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class O2CContracts1018(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.o2c = source("app/Core/OrderToCashDomain.php")
        cls.sales = source("app/Core/SalesDomain.php")
        cls.ui = source("app/Modules/AccountingIndustrialModule.php")
        cls.boot = source("app/bootstrap.php")
        cls.ai = source("app/Core/AiToolRegistry.php")
        cls.runtime = source("app/Core/RuntimeCache.php")
        cls.registry = source("app/Core/ModuleRegistry.php")
        cls.hard = source("engine/commercial_hardening.py")

    def test_version_gate(self):
        self.assertIn("SCHEMA_VERSION = '10.19.0'", self.runtime)
        self.assertIn("VERSION='10.19.0'", self.registry)
        self.assertIn("VERSION = '10.19.0-o2c'", self.o2c)
        self.assertIn("VERSION='10.19.0'", self.sales)
        self.assertIn("uq_o2c_invoice_line", self.o2c)
        self.assertIn("erp_o2c_stock_locks", self.o2c)
        self.assertIn("BILLING_NOTE_MARKER", self.o2c)
        self.assertIn("request_hash", self.o2c)

    def test_bootstrap_wires_o2c(self):
        self.assertIn("OrderToCashDomain.php", self.boot)
        self.assertIn("OrderToCashDomain::migrate(pdo())", self.boot)
        self.assertLess(
            self.boot.index("SalesDomain::migrate(pdo())"),
            self.boot.index("OrderToCashDomain::migrate(pdo())"),
        )

    def test_ar_kernel_and_settlement_not_implemented(self):
        self.assertIn("AR_POSTING = 'KERNEL_AVAILABLE'", self.o2c)
        self.assertIn("SETTLEMENT = 'NOT_IMPLEMENTED'", self.o2c)
        self.assertNotIn("INSERT INTO acc_vouchers", self.o2c)
        self.assertNotIn("acc_cash_transactions", self.o2c)

    def test_confirm_maker_checker_and_fulfillable_gate(self):
        self.assertIn("function confirmOrder", self.o2c)
        self.assertIn("ایجادکننده سند نمی‌تواند آن را تأیید کند", self.o2c)
        self.assertIn("workflow_status='approved'", self.o2c)
        self.assertIn("requireFulfillableStatus", self.o2c)
        self.assertIn("OrderToCashDomain::requireFulfillableStatus", self.sales)

    def test_invoice_links_and_idempotency_tables(self):
        self.assertIn("erp_o2c_invoice_links", self.o2c)
        self.assertIn("erp_o2c_delivery_idempotency", self.o2c)
        self.assertIn("uq_o2c_invoice_line", self.o2c)
        self.assertIn("erp_o2c_invoice_idempotency", self.o2c)
        self.assertIn("erp_o2c_delivery_idempotency", self.sales)

    def test_delivery_invoice_is_draft_only(self):
        self.assertIn("function createInvoiceFromDelivery", self.o2c)
        self.assertIn("'draft','not_sent'", self.o2c)
        self.assertIn("بدون ثبت AR/GL", self.o2c)

    def test_exact_quantity_scaling(self):
        self.assertIn("function scaledQty", self.o2c)
        self.assertIn("OrderToCashDomain::scaledQty", self.sales)
        self.assertIn("OrderToCashDomain::fixedQty", self.sales)

    def test_ui_workflow_actions(self):
        for action in (
            "acc_o2c_confirm_order",
            "acc_o2c_reserve",
            "acc_o2c_deliver",
            "acc_o2c_invoice_delivery",
        ):
            self.assertIn(action, self.ui)
        self.assertIn("OrderToCashDomain::fulfillmentSnapshot", self.ui)
        self.assertIn("Maker/Checker", self.ui)
        # New sales docs force draft regardless of posted workflow_status.
        self.assertIn(",'draft',trim((string)($_POST['taxpayer_status']", self.ui)

    def test_ai_proposal_tools(self):
        for name in (
            "confirm_sales_order",
            "create_o2c_invoice_from_delivery",
            "reserve_sales_stock",
            "deliver_sales_stock",
        ):
            self.assertIn(f"'name'=>'{name}'", self.ai)
            self.assertIn(name, self.hard)
        self.assertIn("OrderToCashDomain::confirmOrder", self.ai)
        self.assertIn("OrderToCashDomain::createInvoiceFromDelivery", self.ai)
        self.assertIn("OrderToCashDomain::fulfillmentSnapshot", self.ai)

    def test_entry_point_matrix_documented(self):
        doc = source("docs/ai/40-O2C-ARCHITECTURE-1018.md")
        for needle in (
            "Entry-point matrix",
            "acc_save_sale",
            "acc_o2c_confirm_order",
            "reserve_sales_stock",
            "create_o2c_invoice_from_delivery",
            "AR_POSTING",
            "BLOCKED",
        ):
            self.assertIn(needle, doc)

    def test_php_syntax_when_available(self):
        php = shutil.which("php")
        if not php:
            self.skipTest("php CLI not on PATH (CI verifies)")
        for rel in (
            "app/Core/OrderToCashDomain.php",
            "app/Core/SalesDomain.php",
            "app/Modules/AccountingIndustrialModule.php",
        ):
            completed = subprocess.run(
                [php, "-l", str(ROOT / rel)],
                cwd=ROOT,
                capture_output=True,
                text=True,
            )
            self.assertEqual(completed.returncode, 0, completed.stdout + completed.stderr)

    def test_schema_gate_monotonic(self):
        m = re.search(r"SCHEMA_VERSION\s*=\s*'(\d+)\.(\d+)\.(\d+)'", self.runtime)
        self.assertIsNotNone(m)
        self.assertGreaterEqual(tuple(map(int, m.groups())), (10, 18, 1))


if __name__ == "__main__":
    unittest.main()
