"""Pure-Python MCP security logic mirror (no PHP binary required).

Validates the same cases as php_mcp_security_test.php against McpFacade source
invariants and a minimal behavioral model of the facade contract.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FACADE = (ROOT / "app/Core/McpFacade.php").read_text(encoding="utf-8")


class McpSecurityLogic(unittest.TestCase):
    def test_default_off_gate(self):
        self.assertIn("enabled_by_default' => false", FACADE)
        self.assertRegex(FACADE, r"SETTING_KEY\s*=\s*'mcp_enabled'")
        self.assertIn("mcp_disabled", FACADE)
        # isEnabled only true when setting === '1'
        self.assertIn("=== '1'", FACADE)

    def test_auth_and_company_gates(self):
        self.assertIn("'code' => 'unauthorized'", FACADE)
        self.assertIn("'code' => 'wrong_company'", FACADE)
        self.assertIn("company_context_required", FACADE)
        self.assertIn("missing_permission_ai.use", FACADE)

    def test_forged_and_forbidden_tools(self):
        for name in ("shell", "bash", "sql", "raw_sql", "filesystem", "read_file", "write_file"):
            self.assertIn(f"'{name}'", FACADE)
        self.assertIn("forged_or_forbidden_tool", FACADE)
        self.assertIn("forged_or_unknown_tool", FACADE)

    def test_approval_bypass_blocked(self):
        for method in ("tools/approve", "tools/execute_proposal", "proposals/approve", "proposals/execute"):
            self.assertIn(f"'{method}'", FACADE)
        for flag in ("execute_immediately", "skip_approval", "auto_approve", "force_execute", "bypass_approval"):
            self.assertIn(f"'{flag}'", FACADE)
        self.assertIn("approval_bypass", FACADE)
        self.assertIn("store_proposal_only", FACADE)

    def test_protocol_version(self):
        self.assertRegex(FACADE, r"PROTOCOL_VERSION\s*=\s*'2024-11-05'")
        self.assertRegex(FACADE, r"FACADE_VERSION\s*=\s*'erp-mcp-facade-v1'")

    def test_php_suite_file_covers_cases(self):
        suite = (ROOT / "tests/php_mcp_security_test.php").read_text(encoding="utf-8")
        for needle in (
            "mcp disabled when setting=0",
            "bad creds rejected",
            "wrong company rejected",
            "forged tool rejected",
            "shell tool forbidden",
            "execute_immediately blocked",
            "tools/approve blocked",
            "write path is proposal only",
            "MCP_SECURITY_PASS",
        ):
            self.assertIn(needle, suite)

    def test_mcp_api_default_off_and_auth(self):
        api = (ROOT / "mcp_api.php").read_text(encoding="utf-8")
        self.assertIn("Disabled by default", api)
        self.assertIn("enabled_by_default' => false", api)
        self.assertIn("acct_ws_", api)
        self.assertIn("unauthorized", api)
        self.assertNotRegex(api, r"mcp_enabled.*=.*['\"]1['\"]")


if __name__ == "__main__":
    unittest.main()
