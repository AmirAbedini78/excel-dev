"""RC1 T6 platform UX + safe AI source contracts."""
from __future__ import annotations

import pathlib
import shutil
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


def source(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class T6WiringContracts(unittest.TestCase):
    def test_routes_and_module_pages(self):
        registry = source("app/Core/ModuleRegistry.php")
        self.assertIn("'pages'=>['procurement','trading_cockpit']", registry)
        self.assertIn("'pages'=>['production','manufacturing_cockpit']", registry)
        root = source("index.php")
        self.assertIn("UiCockpitModule.php", root)
        self.assertIn("str_starts_with($action,'ui_cockpit_')", root)
        self.assertIn("elseif($page === 'trading_cockpit') UiCockpitModule::renderTrading();", root)
        self.assertIn(
            "elseif($page === 'manufacturing_cockpit') UiCockpitModule::renderManufacturing();",
            root,
        )
        self.assertIn("'trading_cockpit'=>'procurement.view'", root)
        self.assertIn("'manufacturing_cockpit'=>'production.view'", root)
        boot = source("app/bootstrap.php")
        for name in (
            "UiRtlSupport.php",
            "UiRoleActionMatrix.php",
            "AiProposalGuardrails.php",
            "DashboardTradingCockpit.php",
            "DashboardManufacturingCockpit.php",
        ):
            self.assertIn(name, boot)

    def test_cockpit_refuses_unauthorized_mutate_posts(self):
        mod = source("app/Modules/UiCockpitModule.php")
        for needle in (
            "ui_cockpit_post_stock",
            "ui_cockpit_post_gl",
            "ui_cockpit_ai_direct_execute",
            "http_response_code(403)",
            "Tenant::requirePermission('procurement.view')",
            "Tenant::requirePermission('production.view')",
        ):
            self.assertIn(needle, mod)
        # No direct stock/GL writers in cockpit module.
        self.assertNotIn("acc_stock_movements", mod)
        self.assertNotIn("acc_vouchers", mod)
        self.assertNotIn("INSERT INTO", mod)

    def test_trading_cockpit_data_backed_and_safe_claims(self):
        d = source("app/Core/DashboardTradingCockpit.php")
        for needle in (
            "PurchaseMatchingDomain::orders",
            "InventoryDomain::purchasePipeline",
            "ApPostingDomain::workflowStatus",
            "erp_ap_payments",
            "bank_reconciled",
            "posted_unallocated_not_paid",
            "never_from_this_cockpit",
        ):
            self.assertIn(needle, d)

    def test_manufacturing_cockpit_empty_blocked_states(self):
        d = source("app/Core/DashboardManufacturingCockpit.php")
        for needle in (
            "ProductionDomain::listBoms",
            "ProductionDomain::listPlans",
            "production_issue",
            "production_fg_receipt",
            "VALUATION_DEFAULT = 'BLOCKED'",
            "qc_blocked",
            "no_stock_postings",
        ):
            self.assertIn(needle, d)

    def test_role_matrix_covers_rc1_roles(self):
        m = source("app/Core/UiRoleActionMatrix.php")
        for role in (
            "buyer",
            "seller",
            "warehouse_operator",
            "production_planner",
            "qc_operator",
            "accountant",
            "treasurer",
            "manager",
        ):
            self.assertIn(f"'{role}'", m)
        doc = source("docs/ai/rc1/T6-ROLE-ACTION-MATRIX.md")
        self.assertIn("Unauthorized POST", doc)

    def test_ai_guardrails_policy(self):
        g = source("app/Core/AiProposalGuardrails.php")
        for needle in (
            "proposal_only",
            "free_text_mutation_blocked",
            "STOCK_OR_GL_TOOLS",
            "ai.actions.approve",
            "evaluateToolInvocation",
            "evaluateFreeText",
            "approvalContract",
        ):
            self.assertIn(needle, g)
        # Existing approve path still permission-gated.
        repo = source("app/Core/AiRepository.php")
        self.assertIn("Tenant::requirePermission('ai.actions.approve')", repo)
        tools = source("app/Core/AiToolRegistry.php")
        self.assertIn("status'=>'awaiting_human_approval'", tools.replace(" ", ""))

    def test_handoff_and_integration_request_exist(self):
        for path in (
            "docs/ai/rc1/T6-HANDOFF.md",
            "docs/ai/rc1/T6-INTEGRATION-REQUEST.md",
            "docs/ai/rc1/T6-SAFE-AI-GUARDRAILS.md",
            "docs/ai/rc1/T6-BROWSER-NOTES.md",
            "assets/ui-cockpit.css",
        ):
            self.assertTrue((ROOT / path).is_file(), path)


class T6PhpGuardrails(unittest.TestCase):
    def test_php_guardrails_pure(self):
        php_bin = shutil.which("php")
        docker = shutil.which("docker")
        script = ROOT / "tests" / "php_rc1_t6_guardrails_test.php"
        self.assertTrue(script.is_file())
        if php_bin:
            cmd = [php_bin, str(script)]
        elif docker:
            cmd = [
                "docker",
                "run",
                "--rm",
                "-v",
                f"{ROOT}:/app",
                "-w",
                "/app",
                "erpsmart-o2c-php:8.2",
                "php",
                "tests/php_rc1_t6_guardrails_test.php",
            ]
        else:
            self.skipTest("PHP or Docker PHP image required")
        p = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(p.returncode, 0, p.stderr + p.stdout)
        self.assertIn("T6_GUARDRAILS_PASS", p.stdout)


if __name__ == "__main__":
    unittest.main()
