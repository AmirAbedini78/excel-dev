"""RC1 SYS-1 acceptance: service/retail profile smoke without forced manufacturing."""
from __future__ import annotations

import os
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "tests" / "harness"))

from rc1_common import (  # noqa: E402
    Rc1DependencyMissing,
    php_runner,
    rc1_required,
    verify_sandbox,
    write_json,
)


@unittest.skipUnless(rc1_required(), "RC1 acceptance opt-in; run scripts/rc1_acceptance_gate.py")
class Rc1Sys1Acceptance(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        try:
            cls.sandbox = verify_sandbox(required=True)
        except Rc1DependencyMissing as exc:
            raise AssertionError(f"RC1_REQUIRED dependency missing for SYS-1: {exc}") from exc

    def test_sys1_service_retail_profile_smoke(self):
        data = php_runner(
            "tests/harness/rc1_sys1_runner.php",
            "service_retail_profile_smoke",
            {},
        )
        self.assertTrue(data.get("ok"), data)
        self.assertEqual(data["retail"]["profile_code"], "retail")
        self.assertNotIn("production", data["retail"]["modules"])
        self.assertTrue(data["canonical_tables"]["acc_parties"])
        self.assertTrue(data["canonical_tables"]["acc_items"])
        self.assertEqual(data["functional_currency"], "IRR")
        self.assertEqual(data["company2"]["display_currency"], "TOMAN")
        evidence = {
            "contract": "SYS-1",
            "status": "PASS",
            "scope": "profile_smoke_php_mysql",
            "result": data,
            "sandbox": self.sandbox,
            "residual": "Browser login walkthrough + full permission matrix still OPS/T6.",
        }
        write_json("sys1_acceptance.json", evidence)


if __name__ == "__main__":
    os.environ["RC1_REQUIRED"] = "1"
    unittest.main()
