#!/usr/bin/env python3
"""Static + pure-logic tests for master data tools and report DSL (no DB required)."""
from __future__ import annotations

import ast
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


class MasterDataAndReportBuilderContracts(unittest.TestCase):
    def test_master_domain_no_physical_delete(self) -> None:
        src = read("app/Core/MasterDataDomain.php")
        self.assertNotIn("DELETE FROM", src)
        self.assertIn("active=0", src)
        self.assertIn("physical_delete", src)
        for ent in ("customer", "supplier", "product"):
            self.assertIn(f"'{ent}'", src)

    def test_report_builder_allowlist_no_raw_sql(self) -> None:
        src = read("app/Core/AiReportBuilder.php")
        self.assertNotIn("pdo()->query", src)
        self.assertNotIn("mysqli", src)
        for kind in ("suite_entity", "enterprise_entity", "document_analytics", "master_parties", "master_products"):
            self.assertIn(f"'{kind}'", src)
        self.assertIn("xlsx_csv", src)
        self.assertIn("content_base64", src)

    def test_registry_wires_new_tools(self) -> None:
        reg = read("app/Core/AiToolRegistry.php")
        for name in (
            "master_data_search",
            "propose_master_data_operation",
            "erp_report_export",
            "MasterDataDomain::prepareProposal",
            "MasterDataDomain::executeProposal",
            "AiReportBuilder::run",
        ):
            self.assertIn(name, reg)

    def test_bootstrap_loads_new_classes(self) -> None:
        boot = read("app/bootstrap.php")
        self.assertIn("MasterDataDomain.php", boot)
        self.assertIn("AiReportBuilder.php", boot)

    def test_persian_write_idioms(self) -> None:
        ast.parse(read("engine/business_skills.py"))
        src = read("engine/business_skills.py")
        self.assertIn("PERSIAN_WRITE_IDIOMS", src)
        self.assertIn("مشتری جدید", src)
        # Import helper path
        import sys

        sys.path.insert(0, str(ROOT / "engine"))
        import business_skills as bs

        self.assertTrue(bs.is_write_request("یک مشتری جدید تعریف کن"))
        self.assertTrue(bs.is_write_request("ویرایش مشتری تهران"))
        self.assertFalse(bs.is_write_request("گزارش فروش ماه جاری چیست"))

    def test_multi_step_budgets_raised(self) -> None:
        src = read("engine/agent_intelligence_kernel.py")
        self.assertRegex(src, r"MAX_TOOL_CALLS\s*=\s*6")
        self.assertRegex(src, r"MAX_PLANNER_CALLS\s*=\s*4")

    def test_gap_notes_exist(self) -> None:
        gaps = read("docs/ai/agentic-next/21-DOMAIN-GAPS-BLOCKED.md")
        for needle in ("Bank reconciliation", "Payroll", "MF costing", "BLOCKED"):
            self.assertIn(needle, gaps)

    def test_proposal_ui_shows_tool_and_no_physical_delete_note(self) -> None:
        js = read("assets/business-copilot.js")
        self.assertIn("copilot-proposal-tool", js)
        self.assertIn("حذف فیزیکی", js)


if __name__ == "__main__":
    unittest.main(verbosity=2)
