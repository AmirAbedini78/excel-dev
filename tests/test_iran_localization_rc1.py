"""RC1 T4 — Iran-first localization source + PHP unit contracts."""
from __future__ import annotations

import pathlib
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP_IMAGE = "erpsmart-o2c-php:8.2"


def source(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def docker_php_available() -> bool:
    try:
        r = subprocess.run(
            ["docker", "image", "inspect", PHP_IMAGE],
            capture_output=True,
            text=True,
            check=False,
        )
        return r.returncode == 0
    except OSError:
        return False


class IranLocalizationRc1Contracts(unittest.TestCase):
    def test_owned_files_exist(self):
        for rel in (
            "app/Core/IranLocalizationDate.php",
            "app/Core/IranLocalizationMoney.php",
            "app/Core/IranLocalizationDocument.php",
            "app/Core/IranLocalizationTemplates/invoice_print.php",
            "app/Core/IranLocalizationTemplates/stock_print.php",
            "docs/ai/rc1/T4-REGULATORY-EVIDENCE-MATRIX.md",
            "docs/ai/rc1/T4-HANDOFF.md",
            "tests/php_iran_localization_rc1_test.php",
        ):
            self.assertTrue((ROOT / rel).is_file(), rel)

    def test_business_profile_sys1(self):
        d = source("app/Core/BusinessProfileDomain.php")
        self.assertIn("requires_manufacturing_docs", d)
        self.assertIn("assessDocumentRequirements", d)
        self.assertIn("service_retail_free_of_mfg_gate", d)
        self.assertIn("IranLocalizationMoney", d)
        # service/retail must explicitly not require manufacturing docs
        self.assertIn("'requires_manufacturing_docs' => false", d)
        self.assertIn("MANUFACTURING_DOC_TYPES", d)

    def test_money_rules(self):
        m = source("app/Core/IranLocalizationMoney.php")
        self.assertIn("TOMAN_FACTOR = 10", m)
        self.assertIn("FUNCTIONAL_CURRENCY = 'IRR'", m)
        self.assertIn("assertNotFloat", m)
        self.assertIn("dualCompanyDisplay", m)
        self.assertIn("ledger_unaffected", m)

    def test_date_rules(self):
        d = source("app/Core/IranLocalizationDate.php")
        self.assertIn("STORE_FORMAT = 'Y-m-d'", d)
        self.assertIn("ROLE_TRANSACTION", d)
        self.assertIn("ROLE_POSTING", d)
        self.assertIn("roundTripJalali", d)
        self.assertIn("timezone", d.lower())
        self.assertIn("parseGregorianDateOnly", d)

    def test_document_rtl_ltr(self):
        d = source("app/Core/IranLocalizationDocument.php")
        self.assertIn('dir="ltr"', d)
        self.assertIn('dir="rtl"', d)
        self.assertIn("<bdi>", d)
        self.assertIn("legal", d.lower())

    def test_regulatory_matrix_honest(self):
        m = source("docs/ai/rc1/T4-REGULATORY-EVIDENCE-MATRIX.md")
        self.assertIn("UNVERIFIED", m)
        self.assertIn("سامانه مودیان", m)
        self.assertIn("صیاد", m)
        self.assertIn("OFF", m)
        self.assertNotRegex(m, r"VAT\s*=\s*\d+")
        self.assertIn("rc1-t4-reg-1", m)

    def test_jalali_from_gregorian_date_only(self):
        j = source("app/Core/Jalali.php")
        self.assertIn("Prefer date-only", j)
        self.assertIn("checkdate", j)

    def test_php_localization_suite(self):
        if not docker_php_available():
            raise unittest.SkipTest(f"{PHP_IMAGE} unavailable")
        # Windows path mount for Docker Desktop
        mount = str(ROOT).replace("\\", "/")
        cmd = [
            "docker",
            "run",
            "--rm",
            "-v",
            f"{mount}:/app",
            "-w",
            "/app",
            PHP_IMAGE,
            "php",
            "tests/php_iran_localization_rc1_test.php",
        ]
        r = subprocess.run(cmd, capture_output=True, text=True, check=False)
        self.assertEqual(r.returncode, 0, r.stdout + "\n" + r.stderr)
        self.assertIn("IRAN_LOCALIZATION_PHP=PASS", r.stdout)
        self.assertIn("DATABASE_TESTED=none", r.stdout)


if __name__ == "__main__":
    unittest.main()
