"""v10.17 P2P hardening source contracts: fail-closed policy, amend, entry-point guards."""
import pathlib
import shutil
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


def source(path):
    return (ROOT / path).read_text(encoding="utf-8")


class P2PHardening1017Tests(unittest.TestCase):
    def test_version_gate(self):
        self.assertIn("SCHEMA_VERSION = '10.18.0'", source("app/Core/RuntimeCache.php"))
        self.assertIn("VERSION='10.18.0'", source("app/Core/ModuleRegistry.php"))
        self.assertIn("10.17.0-hardening", source("app/Core/PurchaseMatchingDomain.php"))

    def test_fail_closed_domain_api(self):
        d = source("app/Core/PurchaseMatchingDomain.php")
        for needle in (
            "function isGoodsMatchEnforced",
            "function assertManualGoodsInvoiceAllowed",
            "function recordException",
            "function assertOrderLinesMutable",
            "function amend",
            "function setCompanyPolicy",
            "erp_p2p_company_policy",
            "erp_p2p_bill_revisions",
            "erp_p2p_policy_exceptions",
            "Fail-Closed",
        ):
            self.assertIn(needle, d)

    def test_accounting_and_ai_entry_points_guarded(self):
        a = source("app/Modules/AccountingIndustrialModule.php")
        ai = source("app/Core/AiToolRegistry.php")
        self.assertIn("PurchaseMatchingDomain::assertManualGoodsInvoiceAllowed", a)
        self.assertIn("p2p_exception_reason", a)
        self.assertIn("PurchaseMatchingDomain::assertManualGoodsInvoiceAllowed", ai)
        self.assertIn("recordException", ai)

    def test_module_amend_and_policy_actions(self):
        m = source("app/Modules/PurchaseMatchingModule.php")
        self.assertIn("p2p_amend_bill", m)
        self.assertIn("p2p_set_policy", m)
        self.assertIn("PurchaseMatchingDomain::amend", m)
        self.assertIn("اصلاح و ارسال مجدد", m)

    def test_exception_permission_seeded(self):
        t = source("app/Core/Tenant.php")
        self.assertIn("accounting.purchase.exception", t)

    def test_no_gl_payment_stock_in_domain(self):
        d = source("app/Core/PurchaseMatchingDomain.php")
        for forbidden in (
            "INSERT INTO acc_vouchers",
            "INSERT INTO acc_stock_movements",
            "INSERT INTO acc_checks",
            "UPDATE acc_cash_accounts",
        ):
            self.assertNotIn(forbidden, d)

    def test_php_contract_still_runs_when_cli_present(self):
        php = shutil.which("php")
        if not php:
            self.skipTest("PHP CLI unavailable; CI must run")
        p = subprocess.run([php, str(ROOT / "tests/php_p2p_contract_test.php")], text=True, capture_output=True)
        self.assertEqual(0, p.returncode, p.stderr + p.stdout)


if __name__ == "__main__":
    unittest.main()
