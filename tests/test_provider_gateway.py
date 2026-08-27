import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("provider_gateway", ROOT / "engine" / "provider_gateway.py")
pg = importlib.util.module_from_spec(spec)
assert spec.loader
spec.loader.exec_module(pg)


def base_cfg():
    return {
        "provider_strategy": "local_first",
        "chat_model": "local-chat",
        "fast_model": "local-fast",
        "agent_model": "local-agent",
        "analysis_model": "local-analysis",
        "cloud_provider": {
            "enabled": True,
            "name": "test-cloud",
            "base_url": "https://example.invalid/v1",
            "api_key": "test-key",
            "chat_model": "cloud-chat",
            "fast_model": "cloud-fast",
            "agent_model": "cloud-agent",
            "analysis_model": "cloud-analysis",
        },
    }


class ProviderGatewayTests(unittest.TestCase):
    def test_config_rejects_insecure_remote_cloud(self):
        cfg = base_cfg()
        cfg["cloud_provider"]["base_url"] = "http://example.com/v1"
        with self.assertRaises(pg.ProviderGatewayError) as cm:
            pg.validate_provider_config(cfg)
        self.assertIn("remote_requires_https", str(cm.exception))

    def test_cloud_only_requires_cloud_provider(self):
        cfg = base_cfg()
        cfg["provider_strategy"] = "cloud_only"
        cfg["cloud_provider"]["enabled"] = False
        with self.assertRaises(pg.ProviderGatewayError) as cm:
            pg.validate_provider_config(cfg)
        self.assertIn("required_by_strategy", str(cm.exception))

    def test_openai_message_adapter_links_tool_result_to_call_id(self):
        messages = [
            {"role": "user", "content": "x"},
            {"role": "assistant", "content": "", "tool_calls": [
                {"id": "call_123", "type": "function", "function": {"name": "trial_balance", "arguments": {"x": 1}}}
            ]},
            {"role": "tool", "tool_name": "trial_balance", "content": json.dumps({"ok": True})},
        ]
        out = pg._openai_messages(messages)
        self.assertEqual(out[1]["tool_calls"][0]["function"]["arguments"], '{"x":1}')
        self.assertEqual(out[2]["tool_call_id"], "call_123")
        self.assertEqual(out[2]["name"], "trial_balance")

    def test_response_format_degrades_safely(self):
        variants = pg._response_format_variants({"type": "object", "properties": {}})
        self.assertEqual(variants[0]["type"], "json_schema")
        self.assertEqual(variants[1], {"type": "json_object"})
        self.assertIsNone(variants[2])

    def test_gateway_preserves_local_first_and_falls_back_to_cloud(self):
        class FakeWorker:
            def __init__(self, cfg):
                self.cfg = cfg
                self.models = ["local-chat", "local-fast", "local-agent", "local-analysis"]
                self.base_payload = {"metadata": {"provider": "ollama"}, "models": list(self.models)}
                self.trace_events = []

            def trace(self, job, stage, message, details=None):
                self.trace_events.append((stage, details or {}))

            def model_for(self, role):
                return {
                    "fast": "local-fast",
                    "agent": "local-agent",
                    "analysis": "local-analysis",
                    "fallback": "local-chat",
                }[role]

            def ollama_chat(self, job, round_no, messages, tools, **kwargs):
                raise RuntimeError("local down")

            def process_agent(self, job, tools_desc):
                model = self.model_for("agent")
                response = self.ollama_chat(job, 0, [{"role": "user", "content": "hello"}], [], model=model)
                return response["message"]["content"], {"provider": "ollama", "model": model}

        cloud_calls = []
        original_cloud_chat = pg._cloud_chat
        try:
            def fake_cloud(worker, job, round_no, messages, tools, role, num_predict, temperature, timeout_seconds, response_format):
                cloud_calls.append(role)
                return {
                    "message": {"role": "assistant", "content": "cloud ok"},
                    "_metrics": {"model": "cloud-agent"},
                    "_provider": "test-cloud",
                }
            pg._cloud_chat = fake_cloud
            pg.install_provider_gateway(FakeWorker)
            w = FakeWorker(base_cfg())
            text, meta = w.process_agent({"id": 1}, [])
            self.assertEqual(text, "cloud ok")
            self.assertEqual(cloud_calls, ["agent"])
            self.assertEqual(meta["provider"], "test-cloud")
            self.assertEqual(meta["providers_used"], ["test-cloud"])
            self.assertTrue(any(stage == "provider_fallback" for stage, _ in w.trace_events))
        finally:
            pg._cloud_chat = original_cloud_chat

    def test_cloud_models_are_advertised_without_secret(self):
        class FakeWorker2:
            def __init__(self, cfg):
                self.cfg = cfg
                self.models = ["local-chat"]
                self.base_payload = {"metadata": {}, "models": list(self.models)}
            def model_for(self, role):
                return "local-chat"
            def ollama_chat(self, *args, **kwargs):
                return {"message": {"role": "assistant", "content": "ok"}, "_metrics": {}}
            def process_agent(self, job, tools):
                return "ok", {}

        pg.install_provider_gateway(FakeWorker2)
        w = FakeWorker2(base_cfg())
        self.assertIn("cloud-agent", w.base_payload["models"])
        metadata_dump = json.dumps(w.base_payload["metadata"], ensure_ascii=False)
        self.assertNotIn("test-key", metadata_dump)
        self.assertEqual(w.base_payload["metadata"]["provider_strategy"], "local_first")


if __name__ == "__main__":
    unittest.main()
