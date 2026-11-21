"""Wave3 MCP + provider discovery/qualification contract tests."""
from __future__ import annotations

import importlib.util
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def load_pg():
    spec = importlib.util.spec_from_file_location(
        "provider_gateway_w3", ROOT / "engine" / "provider_gateway.py"
    )
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader
    spec.loader.exec_module(mod)
    return mod


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


class McpSecurityContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.facade = read("app/Core/McpFacade.php")
        cls.mcp_api = read("mcp_api.php")
        cls.bootstrap = read("app/bootstrap.php")
        cls.module = read("app/Modules/AiModule.php")

    def test_mcp_disabled_by_default(self):
        self.assertIn("enabled_by_default' => false", self.facade)
        self.assertIn("SETTING_KEY = 'mcp_enabled'", self.facade)
        self.assertIn("=== '1'", self.facade)
        self.assertIn("mcp_disabled", self.facade)
        self.assertIn("Disabled by default", self.mcp_api)

    def test_mcp_is_facade_not_second_engine(self):
        self.assertIn("AiToolRegistry", self.facade)
        self.assertIn("proposal_only", self.facade)
        self.assertNotIn("CREATE TABLE", self.facade)
        self.assertIn("McpFacade.php", self.bootstrap)

    def test_forbidden_shell_sql_filesystem(self):
        for name in ("shell", "sql", "filesystem", "raw_sql", "read_file"):
            self.assertIn(f"'{name}'", self.facade)
        self.assertIn("FORBIDDEN_METHODS", self.facade)
        self.assertIn("tools/approve", self.facade)
        self.assertIn("approval_bypass", self.facade)

    def test_protocol_version_documented(self):
        self.assertIn("PROTOCOL_VERSION = '2024-11-05'", self.facade)
        self.assertIn("FACADE_VERSION", self.facade)
        self.assertIn("X-MCP-Protocol-Version", self.mcp_api)

    def test_php_security_suite_passes(self):
        script = ROOT / "tests" / "php_mcp_security_test.php"
        candidates = [
            ["php", str(script)],
            [
                "docker",
                "run",
                "--rm",
                "-v",
                f"{ROOT}:/app",
                "-w",
                "/app",
                "php:8.3-cli",
                "php",
                "tests/php_mcp_security_test.php",
            ],
        ]
        last = None
        for cmd in candidates:
            try:
                proc = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True, timeout=180)
            except FileNotFoundError as exc:
                last = exc
                continue
            last = proc
            if proc.returncode == 0 and "MCP_SECURITY_PASS" in proc.stdout:
                return
        detail = getattr(last, "stdout", "") + getattr(last, "stderr", str(last))
        self.fail(f"php MCP security suite failed: {detail}")

    def test_aimodule_provider_qualification_path(self):
        self.assertIn("ai_save_provider_qualification", self.module)
        self.assertIn("AiProviderQualification", self.module)
        self.assertIn("McpFacade::policySummary", self.module)


class ProviderDiscoveryQualification(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.pg = load_pg()

    def base_cfg(self):
        return {
            "provider_strategy": "local_first",
            "chat_model": "local-chat",
            "fast_model": "local-fast",
            "agent_model": "local-agent",
            "analysis_model": "local-analysis",
            "cloud_provider": {
                "enabled": True,
                "name": "gemini",
                "base_url": "https://example.invalid/v1",
                "api_key": "test-key",
                "chat_model": "cloud-chat",
                "allowed_models": ["cloud-chat"],
            },
        }

    def test_discover_respects_local_only_privacy(self):
        cfg = self.base_cfg()
        cfg["provider_strategy"] = "local_only"
        inv = self.pg.discover_provider_models(cfg, local_models=["local-chat"], probe_cloud=True)
        self.assertTrue(inv["privacy"]["local_only_blocks_cloud"])
        self.assertFalse(inv["privacy"]["cloud_fallback_ignores_privacy"])
        self.assertIn("ollama", inv["providers"])
        self.assertNotIn("cloud", inv["providers"])
        self.assertNotIn("xai", inv["providers"])
        self.assertNotIn("cursor", inv["providers"])

    def test_discover_lists_configured_cloud_when_allowed(self):
        inv = self.pg.discover_provider_models(self.base_cfg(), local_models=["local-chat"])
        self.assertIn("cloud", inv["providers"])
        self.assertIn("cloud-chat", inv["providers"]["cloud"]["configured_models"])

    def test_connection_blocks_cloud_under_local_only(self):
        cfg = self.base_cfg()
        cfg["provider_strategy"] = "local_only"
        r = self.pg.qualify_provider_connection(cfg, "cloud")
        self.assertEqual(r["connection"], "blocked")
        self.assertIn("local_only", r["reason"])

    def test_inference_blocks_cloud_under_local_only(self):
        cfg = self.base_cfg()
        cfg["provider_strategy"] = "local_only"
        called = []

        def chat_fn(**kwargs):
            called.append(kwargs)
            return {"model": kwargs["model"]}

        r = self.pg.qualify_provider_inference(cfg, "gemini", "cloud-chat", chat_fn=chat_fn)
        self.assertEqual(r["inference"], "blocked")
        self.assertEqual(called, [])

    def test_inference_hook_runs_when_allowed(self):
        cfg = self.base_cfg()

        def chat_fn(**kwargs):
            return {"model": kwargs["model"], "ok": True}

        r = self.pg.qualify_provider_inference(cfg, "cloud", "cloud-chat", chat_fn=chat_fn)
        self.assertEqual(r["inference"], "ok")

    def test_build_qualification_report_shape(self):
        report = self.pg.build_qualification_report(
            self.base_cfg(),
            local_models=["local-chat"],
            connection_results={"ollama": {"connection": "ok", "reason": "local_reachable"}},
            inference_results={"ollama": {"inference": "ok", "reason": "bounded_inference_ok"}},
        )
        self.assertEqual(report["schema_version"], "provider-qual-v1")
        self.assertIn(report["status"], {"passed", "degraded", "failed", "not_run"})
        self.assertIn("ollama", report["providers"])
        self.assertFalse(report["privacy"].get("cloud_fallback_ignores_privacy", True))

    def test_existing_adapters_not_replaced(self):
        src = read("engine/provider_gateway.py")
        self.assertIn("def _cloud_chat", src)
        self.assertIn("def _xai_chat", src)
        self.assertIn("def _cursor_agent_chat", src)
        self.assertIn("def install_provider_gateway", src)
        self.assertIn("def discover_provider_models", src)


class DocsContracts(unittest.TestCase):
    def test_docs_present(self):
        d7 = ROOT / "docs/ai/agentic-next/07-MCP-SECURITY-AND-CONTRACT.md"
        d8 = ROOT / "docs/ai/agentic-next/08-PROVIDERS-AND-MODEL-QUALIFICATION.md"
        self.assertTrue(d7.is_file(), "missing 07 doc")
        self.assertTrue(d8.is_file(), "missing 08 doc")
        t7 = d7.read_text(encoding="utf-8")
        t8 = d8.read_text(encoding="utf-8")
        self.assertIn("DISABLED BY DEFAULT", t7)
        self.assertIn("2024-11-05", t7)
        self.assertIn("AiToolRegistry", t7)
        self.assertIn("local_only", t8)
        self.assertIn("discover_provider_models", t8)


if __name__ == "__main__":
    unittest.main()
