"""v10.20.0 AR posting integrity source contracts."""
from __future__ import annotations

import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


def source(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class ArPostingContracts1019(unittest.TestCase):
    def test_version_and_wiring(self):
        self.assertIn("SCHEMA_VERSION = '10.20.0'", source("app/Core/RuntimeCache.php"))
        self.assertIn("VERSION='10.20.0'", source("app/Core/ModuleRegistry.php"))
        self.assertIn("AccountingPostingDomain.php", source("app/bootstrap.php"))
        self.assertIn("AccountingPostingDomain::migrate(pdo())", source("app/bootstrap.php"))
        self.assertIn("VERSION = '10.20.0-ar'", source("app/Core/AccountingPostingDomain.php"))
        self.assertIn("VERSION = '10.20.0-o2c'", source("app/Core/OrderToCashDomain.php"))

    def test_canonical_ledger_only(self):
        d = source("app/Core/AccountingPostingDomain.php")
        self.assertIn("acc_vouchers", d)
        self.assertIn("acc_voucher_lines", d)
        self.assertIn("acc_fiscal_years", d)
        self.assertIn("acc_period_closes", d)
        self.assertNotIn("CREATE TABLE IF NOT EXISTS ar_ledger", d)
        self.assertIn("SETTLEMENT = 'AVAILABLE'", d)

    def test_integrity_invariants(self):
        d = source("app/Core/AccountingPostingDomain.php")
        for needle in (
            "function postCustomerInvoice",
            "function assessReadiness",
            "function setCompanyMap",
            "function assertFiscalOpen",
            "function customerOutstanding",
            "function invoiceRequestHash",
            "function signedScaled",
            "Maker/Checker",
            "erp_ar_company_maps",
            "erp_ar_postings",
            "o2c_ar",
            "isBillingDocument",
            "allow_posting",
            "CREDIT_BALANCE",
            "UNAVAILABLE",
            "isDuplicateKey",
            "race_recovered",
        ):
            self.assertIn(needle, d)

    def test_cancel_billing_protection(self):
        o2c = source("app/Core/OrderToCashDomain.php")
        self.assertIn("function cancelDraftBillingInvoice", o2c)
        self.assertIn("function deleteOrCancelSalesDraft", o2c)
        self.assertIn("status='cancelled'", o2c)
        ui = source("app/Modules/AccountingIndustrialModule.php")
        self.assertIn("acc_o2c_cancel_billing_invoice", ui)
        self.assertIn("deleteOrCancelSalesDraft", ui)

    def test_ui_action(self):
        ui = source("app/Modules/AccountingIndustrialModule.php")
        self.assertIn("acc_ar_post_invoice", ui)
        self.assertIn("AccountingPostingDomain::postCustomerInvoice", ui)

    def test_o2c_ar_flag_updated(self):
        self.assertIn("AR_POSTING = 'KERNEL_AVAILABLE'", source("app/Core/OrderToCashDomain.php"))
        self.assertIn("SETTLEMENT = 'AVAILABLE'", source("app/Core/OrderToCashDomain.php"))


if __name__ == "__main__":
    unittest.main()
