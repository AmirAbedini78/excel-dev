"""v10.21 Iran-first foundations source contracts."""
from __future__ import annotations

import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


def source(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class IranFirstContracts1021(unittest.TestCase):
    def test_versions(self):
        self.assertIn("SCHEMA_VERSION = '10.21.0'", source("app/Core/RuntimeCache.php"))
        self.assertIn("VERSION='10.21.0'", source("app/Core/ModuleRegistry.php"))
        self.assertIn("BusinessProfileDomain.php", source("app/bootstrap.php"))
        self.assertIn("BusinessProfileDomain::migrate(pdo())", source("app/bootstrap.php"))

    def test_profile_domain(self):
        d = source("app/Core/BusinessProfileDomain.php")
        for needle in (
            "FUNCTIONAL_CURRENCY = 'IRR'",
            "TOMAN",
            "displayToIrrScaled",
            "irrScaledToDisplay",
            "trading",
            "manufacturing",
            "erp_company_business_profiles",
            "Never silent",
        ):
            self.assertIn(needle, d)

    def test_ui_and_docs(self):
        self.assertIn("acc_set_business_profile", source("app/Modules/AccountingIndustrialModule.php"))
        self.assertTrue((ROOT / "docs/ai/51-IRAN-FIRST-ARCHITECTURE-1021.md").is_file())
        self.assertTrue((ROOT / "docs/ai/52-TRADING-MANUFACTURING-ROADMAP-1021.md").is_file())
        self.assertTrue((ROOT / "docs/ai/53-REGULATORY-GAP-MATRIX-1021.md").is_file())

    def test_toman_conversion_rules_in_source(self):
        d = source("app/Core/BusinessProfileDomain.php")
        self.assertIn("return $units * 10", d)
        self.assertIn("intdiv($irrScaled, 10)", d)


if __name__ == "__main__":
    unittest.main()
