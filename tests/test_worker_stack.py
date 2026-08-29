from __future__ import annotations

import sys
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "engine"))

import worker  # noqa: E402


class ActualWorkerStackTests(unittest.TestCase):
    def test_all_guard_layers_are_installed(self):
        expected = (
            "_deep_safe_v4_installed",
            "_agent_guard_v1_installed",
            "_read_guard_v3_installed",
            "_adaptive_router_v1_installed",
            "_workflow_planner_v1_installed",
            "_action_orchestrator_v1_installed",
            "_financial_intelligence_v1_installed",
            "_forecast_risk_v1_installed",
            "_proactive_accounting_v1_installed",
            "_finance_actions_v1_installed",
            "_inventory_procurement_v1_installed",
            "_trade_logistics_v1_installed",
            "_sales_fulfillment_v1_installed",
            "_commercial_hardening_v1_installed",
        )
        missing = [name for name in expected if not getattr(worker.Worker, name, False)]
        self.assertEqual(missing, [])

    def test_generic_agent_never_receives_proposal_descriptors(self):
        descriptors = [
            {"name": "search_parties", "mode": "read"},
            {"name": "search_items", "mode": "read"},
            {"name": "trial_balance", "mode": "read"},
            {"name": "create_sales_invoice_draft", "mode": "proposal"},
            {"name": "create_voucher_draft", "mode": "proposal"},
        ]
        invoice = worker.Worker.select_tool_descriptors("یک فاکتور را بررسی کن", descriptors)
        voucher = worker.Worker.select_tool_descriptors("یک سند حسابداری را بررسی کن", descriptors)
        for selected in (invoice, voucher):
            self.assertTrue(selected)
            self.assertTrue(all(row.get("mode") != "proposal" for row in selected))

    def test_commercial_wrapper_is_the_public_process_agent(self):
        self.assertEqual(worker.Worker.process_agent.__name__, "hardened_process_agent")
        self.assertEqual(worker.Worker.tool.__name__, "hardened_tool")

    def test_control_plane_keepalive_transport_is_installed(self):
        self.assertTrue(getattr(worker.Api, "_keepalive_transport_v1_installed", False))
        self.assertEqual(worker.Api.post.__name__, "keepalive_post")


if __name__ == "__main__":
    unittest.main()
