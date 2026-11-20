"""RC1 TR-2 acceptance: sales/AR settle via single PHP process + disposable MySQL."""
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
class Rc1Tr2Acceptance(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        try:
            cls.sandbox = verify_sandbox(required=True)
        except Rc1DependencyMissing as exc:
            raise AssertionError(f"RC1_REQUIRED dependency missing for TR-2: {exc}") from exc

    def test_tr2_sales_ar_400_600_settle(self):
        data = php_runner("tests/harness/rc1_tr2_runner.php", "run", {}, timeout=300)
        self.assertTrue(data.get("ok"), data)
        self.assertEqual(data.get("status"), "PASS")
        self.assertEqual(data.get("invoice_amount_irr"), "1000.00")
        self.assertEqual(data.get("outstanding"), "0.00")
        evidence = {"contract": "TR-2", "status": "PASS", "result": data, "sandbox": self.sandbox}
        write_json("tr2_acceptance.json", evidence)


if __name__ == "__main__":
    os.environ["RC1_REQUIRED"] = "1"
    unittest.main()
