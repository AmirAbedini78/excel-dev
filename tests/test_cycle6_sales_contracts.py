from __future__ import annotations
import json
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

class Cycle6SalesContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.domain=(ROOT/"app/Core/SalesDomain.php").read_text(encoding="utf-8")
        cls.registry=(ROOT/"app/Core/AiToolRegistry.php").read_text(encoding="utf-8")
        cls.bootstrap=(ROOT/"app/bootstrap.php").read_text(encoding="utf-8")
        cls.runtime=(ROOT/"app/Core/RuntimeCache.php").read_text(encoding="utf-8")
        cls.worker=(ROOT/"engine/worker.py").read_text(encoding="utf-8")
        cls.hard=(ROOT/"engine/commercial_hardening.py").read_text(encoding="utf-8")
        cls.ui=(ROOT/"app/Modules/AccountingIndustrialModule.php").read_text(encoding="utf-8")

    def test_reuses_canonical_sales_and_reservation_tables(self):
        self.assertIn("acc_sales_docs",self.domain)
        self.assertIn("acc_sales_lines",self.domain)
        self.assertIn("acc_inventory_reservations",self.domain)
        self.assertNotIn("CREATE TABLE IF NOT EXISTS acc_sales_orders",self.domain)

    def test_delivery_is_separate_and_posts_outbound_ledger(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS acc_sales_deliveries",self.domain)
        self.assertIn("CREATE TABLE IF NOT EXISTS acc_sales_delivery_lines",self.domain)
        self.assertIn("'sales_delivery','out'",self.domain)
        self.assertIn("'sales_delivery',?,?,?,'posted'",self.domain)

    def test_margin_uses_landed_cost_bridge_and_excludes_tax(self):
        self.assertIn("TradeDomain::landedCostSummary",self.domain)
        self.assertIn("actual_cost_type_coverage",self.domain)
        self.assertIn("quantity']*(float)$line['unit_price']-(float)$line['discount_amount']",self.domain)
        self.assertIn("actual_landed_margin_available",self.domain)

    def test_schema_gate_is_10_3(self):
        self.assertIn("SCHEMA_VERSION = '10.3.0'",self.runtime)
        self.assertIn("SalesDomain.php",self.bootstrap)
        self.assertIn("SalesDomain::migrate(pdo())",self.bootstrap)

    def test_ai_contract_has_sales_fulfillment_tools(self):
        for name in ("search_sales_documents","sales_fulfillment","sales_margin_summary","trade_manager_brief","reserve_sales_stock","deliver_sales_stock"):
            self.assertIn(f"'name'=>'{name}'",self.registry)
        self.assertIn("'reserve_sales_stock'=>SalesDomain::reserveStock",self.registry)
        self.assertIn("'deliver_sales_stock'=>SalesDomain::deliverStock",self.registry)

    def test_worker_installs_sales_slice_before_hardening(self):
        self.assertIn("_install_sales_fulfillment(Worker)",self.worker)
        self.assertLess(self.worker.index("_install_sales_fulfillment(Worker)"),self.worker.index("_install_commercial_hardening(Worker)"))
        self.assertIn("api_transport",self.worker)

    def test_hardening_classifies_delivery_high(self):
        self.assertIn('"deliver_sales_stock"',self.hard)
        self.assertIn('"reserve_sales_stock"',self.hard)
        self.assertIn('"guarded_sales_delivery_proposal"',self.hard)
        self.assertIn('"sales_margin_read"',self.hard)

    def test_sales_ui_surfaces_fulfillment_and_margin(self):
        self.assertIn("SalesDomain::fulfillment",self.ui)
        self.assertIn("SalesDomain::marginSummary",self.ui)

    def test_docs_move_cycle5_to_live_closed(self):
        state=json.loads((ROOT/"docs/ai/04-docops/task_state.json").read_text(encoding="utf-8"))
        self.assertEqual(state["baseline_commit"],"12c9000dba8bcafb42829176f8bbf232338ff78f")
        self.assertEqual(state["current_milestone"],"v10.3 Sales Fulfillment + Margin Vertical Slice")
        self.assertIn("PASS_LIVE_JOB78",state["release_gates"]["trade_case_landed_cost_slice"])

if __name__=="__main__":
    unittest.main()
