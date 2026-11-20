"""v10.22.0 AP posting kernel source contracts."""
from __future__ import annotations

import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


def source(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class ApPostingContracts1022(unittest.TestCase):
    def test_version_and_wiring(self):
        self.assertIn("SCHEMA_VERSION = '10.23.0'", source("app/Core/RuntimeCache.php"))
        self.assertIn("VERSION='10.23.0'", source("app/Core/ModuleRegistry.php"))
        self.assertIn("ApPostingDomain.php", source("app/bootstrap.php"))
        self.assertIn("ApPostingDomain::migrate(pdo())", source("app/bootstrap.php"))
        self.assertIn("VERSION = '10.23.0-ap'", source("app/Core/ApPostingDomain.php"))

    def test_canonical_ledger_only(self):
        d = source("app/Core/ApPostingDomain.php")
        self.assertIn("acc_vouchers", d)
        self.assertIn("acc_voucher_lines", d)
        self.assertIn("p2p_ap", d)
        self.assertIn("VALUATION_MODEL = 'invoice_based'", d)
        self.assertIn("PAYMENT = 'AVAILABLE'", d)
        self.assertNotIn("CREATE TABLE IF NOT EXISTS ap_ledger", d)
        self.assertIn("erp_ap_company_maps", d)
        self.assertIn("erp_ap_postings", d)
        self.assertIn("erp_ap_workflow", d)

    def test_integrity_invariants(self):
        d = source("app/Core/ApPostingDomain.php")
        for needle in (
            "function postSupplierInvoice",
            "function validateInvoice",
            "function approveInvoice",
            "function assessReadiness",
            "function setCompanyMap",
            "function supplierOutstanding",
            "function invoiceOutstanding",
            "function reversePostedInvoice",
            "function invoiceRequestHash",
            "Maker/Checker",
            "isGoodsMatchEnforced",
            "allow_service_manual",
            "allow_posting",
            "isDuplicateKey",
            "race_recovered",
            "حساب‌های AP نباید با نگاشت AR",
        ):
            self.assertIn(needle, d)

    def test_ui_actions(self):
        ui = source("app/Modules/AccountingIndustrialModule.php")
        for needle in (
            "acc_ap_set_map",
            "acc_ap_validate_invoice",
            "acc_ap_approve_invoice",
            "acc_ap_post_invoice",
            "acc_ap_reverse_invoice",
            "ApPostingDomain::postSupplierInvoice",
        ):
            self.assertIn(needle, ui)

    def test_architecture_doc(self):
        doc = source("docs/ai/55-AP-POSTING-ARCHITECTURE-1022.md")
        self.assertIn("invoice-based", doc.lower())
        self.assertIn("GRNI", doc)
        self.assertIn("NOT_DEPLOYED", doc)
        self.assertIn("Stock movement", doc)


if __name__ == "__main__":
    unittest.main()
