"""v10.20.1 treasury integrity source contracts."""
from __future__ import annotations

import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


def source(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class TreasuryIntegrityContracts10201(unittest.TestCase):
    def test_versions(self):
        self.assertIn("SCHEMA_VERSION = '10.20.1'", source("app/Core/RuntimeCache.php"))
        self.assertIn("VERSION='10.20.1'", source("app/Core/ModuleRegistry.php"))
        self.assertIn("VERSION = '10.20.1-settlement'", source("app/Core/ArSettlementDomain.php"))

    def test_integrity_features(self):
        d = source("app/Core/ArSettlementDomain.php")
        for needle in (
            "function setCashAccountGl",
            "function resolveCashGl",
            "function customerBalanceBreakdown",
            "gl_account_id",
            "cash_gl_account_id",
            "ar_gl_account_id",
            "payment_method",
            "METHODS",
            "ar_receipt_reversal",
            "status='reversed'",
            "erp_ar_cash_gl_map_audit",
            "unapplied_receipt_credit",
            "bank_reconciled",
        ):
            self.assertIn(needle, d)

    def test_ui_wiring(self):
        ui = source("app/Modules/AccountingIndustrialModule.php")
        self.assertIn("acc_ar_set_cash_account_gl", ui)
        self.assertIn("setCashAccountGl", ui)
        self.assertIn("payment_method", ui)


if __name__ == "__main__":
    unittest.main()
