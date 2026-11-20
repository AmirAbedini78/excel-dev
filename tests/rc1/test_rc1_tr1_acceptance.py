"""RC1 TR-1 acceptance: domestic procurement via single PHP process + disposable MySQL."""
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
class Rc1Tr1Acceptance(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        try:
            cls.sandbox = verify_sandbox(required=True)
        except Rc1DependencyMissing as exc:
            raise AssertionError(f"RC1_REQUIRED dependency missing for TR-1: {exc}") from exc
        write_json("tr1_sandbox.json", cls.sandbox)

    def test_tr1_po10_gr6_ap6000_pay_settle(self):
        data = php_runner("tests/harness/rc1_tr1_runner.php", "run", {}, timeout=300)
        self.assertTrue(data.get("ok"), data)
        self.assertEqual(data.get("status"), "PASS")
        self.assertEqual(data.get("outstanding_after_ap"), "6000.00")
        self.assertEqual(data.get("outstanding_final"), "0.00")
        self.assertEqual(data.get("stock_movements"), 1)
        self.assertEqual(data.get("receipt_gl_vouchers"), 0)
        evidence = {"contract": "TR-1", "status": "PASS", "result": data, "sandbox": self.sandbox}
        write_json("tr1_acceptance.json", evidence)


if __name__ == "__main__":
    os.environ["RC1_REQUIRED"] = "1"
    unittest.main()
