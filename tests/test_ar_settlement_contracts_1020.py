"""v10.20 settlement source contracts."""
from __future__ import annotations

import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


def source(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class ArSettlementContracts1020(unittest.TestCase):
    def test_version_wiring(self):
        self.assertIn("SCHEMA_VERSION = '10.22.0'", source("app/Core/RuntimeCache.php"))
        self.assertIn("VERSION='10.22.0'", source("app/Core/ModuleRegistry.php"))
        self.assertIn("ArSettlementDomain.php", source("app/bootstrap.php"))
        self.assertIn("ArSettlementDomain::migrate(pdo())", source("app/bootstrap.php"))
        self.assertIn("VERSION = '10.21.0-settlement'", source("app/Core/ArSettlementDomain.php"))
        self.assertIn("SETTLEMENT = 'AVAILABLE'", source("app/Core/ArSettlementDomain.php"))
        self.assertIn("SETTLEMENT = 'AVAILABLE'", source("app/Core/OrderToCashDomain.php"))

    def test_lifecycle_methods(self):
        d = source("app/Core/ArSettlementDomain.php")
        for needle in (
            "function createDraftReceipt",
            "function approveReceipt",
            "function postReceipt",
            "function allocate",
            "function reverseReceipt",
            "function invoiceSettlementStatus",
            "PARTIALLY_SETTLED",
            "o2c_ar_receipt",
            "bank_reconciled",
            "erp_ar_receipts",
            "erp_ar_allocations",
            "cash_gl_account_id",
        ):
            self.assertIn(needle, d)

    def test_ui_actions(self):
        ui = source("app/Modules/AccountingIndustrialModule.php")
        for a in (
            "acc_ar_receipt_draft",
            "acc_ar_receipt_approve",
            "acc_ar_receipt_post",
            "acc_ar_allocate",
            "acc_ar_receipt_reverse",
            "acc_ar_set_cash_gl",
        ):
            self.assertIn(a, ui)


if __name__ == "__main__":
    unittest.main()
