"""Static smoke tests for the Universal ERP Agent routing foundation.
These tests are intentionally not a substitute for model + MySQL end-to-end tests.
"""
from pathlib import Path
import ast
import unittest

ROOT = Path(__file__).resolve().parents[1]


class UniversalAgentRoutingContract(unittest.TestCase):
    def test_worker_python_parses(self):
        ast.parse((ROOT / "engine" / "worker.py").read_text(encoding="utf-8"))

    def test_skill_router_python_parses(self):
        ast.parse((ROOT / "engine" / "business_skills.py").read_text(encoding="utf-8"))

    def test_worker_exposes_only_generic_proposal_on_write_intent(self):
        source = (ROOT / "engine" / "worker.py").read_text(encoding="utf-8")
        self.assertIn('write_intent and str(d.get("name") or "") == "propose_erp_operation"', source)
        self.assertIn('wanted.update({"erp_entity_catalog", "erp_entity_search", "erp_entity_read", "propose_erp_operation"})', source)

    def test_universal_skill_contains_operational_read_tools(self):
        source = (ROOT / "engine" / "business_skills.py").read_text(encoding="utf-8")
        self.assertIn('"universal-erp-operations"', source)
        for tool in (
            "erp_entity_catalog",
            "erp_entity_search",
            "erp_entity_read",
            "erp_entity_report",
            "erp_navigation_resolve",
            "erp_entity_history",
        ):
            self.assertIn(tool, source)

    def test_financial_proposal_tools_stay_guarded(self):
        source = (ROOT / "engine" / "worker.py").read_text(encoding="utf-8")
        # Only the generic Suite/Enterprise proposal may enter the LLM loop on write intent.
        self.assertIn('str(d.get("name") or "") == "propose_erp_operation"', source)
        for forbidden in (
            "create_sales_invoice_draft",
            "create_voucher_draft",
            "deliver_sales_stock",
            "create_warehouse_receipt",
        ):
            self.assertNotIn(f'"{forbidden}"', source.split("def select_tool_descriptors")[1].split("def ")[0])

    def test_ops_adapter_forbids_destructive_sql(self):
        ops = (ROOT / "app" / "Core" / "AiUniversalOperations.php").read_text(encoding="utf-8")
        for needle in ("DELETE FROM ", "TRUNCATE TABLE ", "DROP TABLE "):
            self.assertNotIn(needle, ops)


if __name__ == "__main__":
    unittest.main(verbosity=2)
