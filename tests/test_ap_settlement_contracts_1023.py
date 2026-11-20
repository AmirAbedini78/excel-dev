"""v10.23.0 AP settlement + domestic trading source contracts."""
from __future__ import annotations

import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


def source(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class ApSettlementContracts1023(unittest.TestCase):
    def test_version_and_wiring(self):
        self.assertIn("SCHEMA_VERSION = '10.23.0'", source("app/Core/RuntimeCache.php"))
        self.assertIn("VERSION='10.23.0'", source("app/Core/ModuleRegistry.php"))
        self.assertIn("ApSettlementDomain.php", source("app/bootstrap.php"))
        self.assertIn("ApSettlementDomain::migrate(pdo())", source("app/bootstrap.php"))
        self.assertIn("VERSION = '10.23.0-ap-settlement'", source("app/Core/ApSettlementDomain.php"))
        self.assertIn("PAYMENT = 'AVAILABLE'", source("app/Core/ApPostingDomain.php"))

    def test_payment_invariants(self):
        d = source("app/Core/ApSettlementDomain.php")
        for needle in (
            "function createDraftPayment",
            "function approvePayment",
            "function postPayment",
            "function allocate",
            "p2p_ap_payment",
            "Maker/Checker",
            "cheque",
            "sayad",
            "bank_reconciled",
            "erp_ap_payments",
            "erp_ap_allocations",
        ):
            self.assertIn(needle, d)
        self.assertIn("METHODS = ['cash', 'bank_transfer']", d)

    def test_ui_actions(self):
        ui = source("app/Modules/AccountingIndustrialModule.php")
        for needle in (
            "acc_ap_payment_draft",
            "acc_ap_payment_approve",
            "acc_ap_payment_post",
            "acc_ap_allocate",
            "ApSettlementDomain::postPayment",
        ):
            self.assertIn(needle, ui)


if __name__ == "__main__":
    unittest.main()
