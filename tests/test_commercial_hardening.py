from __future__ import annotations

import sys
import threading
import time
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
ENGINE = ROOT / "engine"
sys.path.insert(0, str(ENGINE))

import commercial_hardening as CH  # noqa: E402


def worker_class(process_impl, tool_impl=None):
    class DummyWorker:
        def __init__(self):
            self.cfg = {}
            self.current_trace = []
            self.progress_lock = threading.Lock()

        def trace(self, job, stage, message, details=None):
            self.current_trace.append({
                "stage": stage,
                "message": message,
                "details": details or {},
            })

        def tool(self, job, name, arguments, call_id):
            if tool_impl is not None:
                return tool_impl(self, job, name, arguments, call_id)
            return {"ok": True}

        def process_agent(self, job, tools_desc):
            return process_impl(self, job, tools_desc)

    CH.install_commercial_hardening(DummyWorker)
    return DummyWorker


class RuntimeConfigTests(unittest.TestCase):
    TOKEN = "aiw_" + "a" * 48

    def test_remote_https_is_accepted(self):
        CH.validate_runtime_config({
            "server_url": "https://erp.example.test/ai_api.php",
            "worker_token": self.TOKEN,
        })

    def test_remote_http_fails_closed(self):
        with self.assertRaisesRegex(CH.CommercialContractError, "requires_https"):
            CH.validate_runtime_config({
                "server_url": "http://erp.example.test/ai_api.php",
                "worker_token": self.TOKEN,
            })

    def test_loopback_http_is_allowed_for_local_test(self):
        CH.validate_runtime_config({
            "server_url": "http://127.0.0.1:8080/ai_api.php",
            "worker_token": self.TOKEN,
        })

    def test_placeholder_token_is_rejected(self):
        with self.assertRaisesRegex(CH.CommercialContractError, "worker_token_format_invalid"):
            CH.validate_runtime_config({
                "server_url": "https://erp.example.test/ai_api.php",
                "worker_token": "PASTE_TOKEN_FROM_WEB_PANEL",
            })


class MetadataTests(unittest.TestCase):
    def test_recursive_redaction(self):
        token = "aiw_" + "b" * 48
        safe, count = CH.redact_metadata({
            "worker_token": token,
            "nested": {"authorization": "Bearer abcdefghijklmnopqrstuvwxyz", "note": token},
        })
        self.assertGreaterEqual(count, 3)
        self.assertEqual(safe["worker_token"], "[REDACTED]")
        self.assertEqual(safe["nested"]["authorization"], "[REDACTED]")
        self.assertNotIn(token, str(safe))

    def test_blocked_action_reports_actual_model_and_tools(self):
        def process(self, job, tools_desc):
            self.trace(job, "llm_done", "done", {
                "model": "qwen3.5:0.8b",
                "elapsed_seconds": 2.5,
                "first_chunk_seconds": 0.7,
            })
            self.tool(job, "search_parties", {"query": "demo"}, "call-1")
            return "blocked", {
                "mode": "accounting_action_blocked",
                "model": "none",
                "tools_used": [],
            }

        Worker = worker_class(process)
        worker = Worker()
        _, meta = worker.process_agent({"id": 41}, [])
        self.assertEqual(meta["model"], "qwen3.5:0.8b")
        self.assertEqual(meta["model_attempted"], "qwen3.5:0.8b")
        self.assertEqual(meta["tools_used"], ["search_parties"])
        self.assertEqual(meta["tools_attempted"], ["search_parties"])
        self.assertEqual(meta["commercial_hardening"]["risk_class"], "high")

    def test_valid_proposal_contract_is_preserved(self):
        def tool(self, job, name, arguments, call_id):
            return {"proposal_id": 9, "status": "awaiting_human_approval"}

        def process(self, job, tools_desc):
            self.tool(job, "create_voucher_draft", {"lines": []}, "call-2")
            return "proposal", {
                "mode": "accounting_action_proposal",
                "model": "qwen3.5:0.8b",
                "proposal_id": 9,
                "proposal_status": "awaiting_human_approval",
                "tools_used": ["create_voucher_draft"],
            }

        Worker = worker_class(process, tool)
        _, meta = Worker().process_agent({"id": 42}, [])
        hard = meta["commercial_hardening"]
        self.assertTrue(hard["proposal_created"])
        self.assertTrue(hard["human_approval_required"])
        self.assertFalse(hard["automatic_financial_execution"])
        self.assertEqual(hard["mutation_boundary"], "proposal_only")

    def test_read_only_route_cannot_call_proposal_tool(self):
        def process(self, job, tools_desc):
            self.tool(job, "create_voucher_draft", {}, "unsafe")
            return "unsafe", {"mode": "proactive_accounting", "tools_used": []}

        Worker = worker_class(process)
        with self.assertRaisesRegex(CH.CommercialContractError, "read_only_route_created_proposal"):
            Worker().process_agent({"id": 48}, [])

    def test_latency_budget_is_emitted(self):
        def process(self, job, tools_desc):
            time.sleep(0.003)
            return "ok", {"mode": "deterministic_financial_report", "tools_used": []}

        Worker = worker_class(process)
        worker = Worker()
        worker.cfg = {"latency_budgets_seconds": {"deterministic": 0.000001}}
        _, meta = worker.process_agent({"id": 1}, [])
        hard = meta["commercial_hardening"]
        self.assertEqual(hard["latency_budget_class"], "deterministic")
        self.assertEqual(hard["latency_status"], "exceeded")
        self.assertIn("end_to_end_seconds", hard)


if __name__ == "__main__":
    unittest.main()
