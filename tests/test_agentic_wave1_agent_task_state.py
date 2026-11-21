"""Wave1 durable agent_task state, recovery, and invalid-tool coordination."""
from __future__ import annotations

import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENGINE = ROOT / "engine"
if str(ENGINE) not in sys.path:
    sys.path.insert(0, str(ENGINE))

import agent_task_state as ATS
import agent_intelligence_kernel as K


READ_TOOLS = [
    {"name": "supplier_performance_summary", "mode": "read"},
    {"name": "document_analytics", "mode": "read"},
    {"name": "search_trade_cases", "mode": "read"},
    {"name": "trade_risk_summary", "mode": "read"},
    {"name": "trade_case_snapshot", "mode": "read"},
    {"name": "shipment_commitment_impact", "mode": "read"},
]


def env(attached=None):
    return {
        "context_envelope": {
            "version": "v2",
            "validated": True,
            "company_id": 1,
            "attached_entities": attached or [],
            "current_page": {"entities": []},
        }
    }


def job(prompt, *, company_id=1, result_json=None, attached=None):
    j = {"id": 501, "company_id": company_id, "prompt": prompt, "context": env(attached), "lease_token": "x"}
    if result_json is not None:
        j["result_json"] = result_json
    return j


def supplier_rows():
    return {
        "rows": [{
            "party_id": 11,
            "code": "SUP-11",
            "name": "تامین کننده آزمایشی",
            "purchase_document_count": 2,
            "purchase_net_total": 1_000_000,
            "ordered_qty": 10.0,
            "accepted_qty": 8.0,
            "open_qty": 2.0,
            "receipt_fill_rate": 0.8,
            "trade_case_count": 0,
            "high_risk_cases": 0,
            "medium_risk_cases": 0,
            "max_delay_days": 0.0,
            "arrival_sample": 1,
            "on_time_rate": 1.0,
        }],
        "limitations": {},
    }


class FakeWorker:
    def __init__(self, tool_results=None):
        self.cfg = {"kernel_planner_mode": "deterministic"}
        self.tool_results = tool_results or {}
        self.tool_calls = []
        self.traces = []

    def trace(self, job, stage, message, data=None):
        self.traces.append((stage, message, data or {}))

    def model_for(self, role):
        return "test-model"

    def tool(self, job, name, args, call_id):
        self.tool_calls.append((name, dict(args), call_id))
        value = self.tool_results.get(name)
        if callable(value):
            return value(dict(args))
        return value if value is not None else {}

    def ollama_chat(self, *args, **kwargs):
        raise RuntimeError("model_disabled_in_wave1_test")


class AgentTaskStateMachineTests(unittest.TestCase):
    def test_happy_path_transitions(self):
        t = ATS.new_task(company_id=7, capability="supplier.performance", job_id=9)
        self.assertEqual(t["state"], "CREATED")
        t = ATS.transition(t, "VALIDATING")
        t = ATS.transition(t, "PLANNING")
        t = ATS.transition(t, "EXECUTING_READS")
        t = ATS.transition(t, "ANALYZING")
        t = ATS.transition(t, "COMPLETED")
        self.assertEqual(t["state"], "COMPLETED")
        self.assertGreaterEqual(len(t["transition_log"]), 5)

    def test_illegal_transition_rejected(self):
        t = ATS.new_task(company_id=1)
        with self.assertRaises(ValueError):
            ATS.transition(t, "COMPLETED")

    def test_invalid_tool_rejection_blocks(self):
        t = ATS.transition(ATS.new_task(company_id=1), "VALIDATING")
        t = ATS.transition(t, "PLANNING")
        t = ATS.reject_invalid_tool(t, "invented_drop_table", source="planner")
        self.assertEqual(t["state"], "BLOCKED")
        self.assertIn("invalid_tool", t["block_reason"])
        self.assertEqual(t["invalid_tool_rejections"][-1]["tool_id"], "invented_drop_table")

    def test_mutation_idempotent_across_duplicate_completion(self):
        t = ATS.transition(ATS.new_task(company_id=1), "VALIDATING")
        t = ATS.transition(t, "PLANNING")
        t = ATS.transition(t, "ANALYZING")
        t = ATS.transition(t, "PROPOSAL_READY")
        t, apply1 = ATS.begin_mutation(t, "mut-abc-1")
        self.assertTrue(apply1)
        t = ATS.complete_mutation(t, "mut-abc-1")
        self.assertIn("mut-abc-1", t["completed_mutation_keys"])
        t2, apply2 = ATS.begin_mutation(t, "mut-abc-1")
        self.assertFalse(apply2)
        t3 = ATS.record_duplicate_completion(t2, "mut-abc-1")
        self.assertEqual(t3["completed_mutation_keys"].count("mut-abc-1"), 1)

    def test_lease_recovery_mid_mutation_does_not_reenter_apply(self):
        t = ATS.transition(ATS.new_task(company_id=3), "VALIDATING")
        for s in ("PLANNING", "ANALYZING", "PROPOSAL_READY", "AWAITING_APPROVAL", "EXECUTING_APPROVED_ACTION"):
            t = ATS.transition(t, s)
        t["completed_mutation_keys"] = ["already-done"]
        resumed = ATS.resume_after_lease_recovery(t, job_company_id=3)
        self.assertEqual(resumed["state"], "VERIFYING")
        again, should = ATS.begin_mutation(resumed, "already-done")
        self.assertFalse(should)

    def test_company_change_forces_revalidating(self):
        t = ATS.transition(ATS.new_task(company_id=1, capability="trade.risk"), "VALIDATING")
        t = ATS.transition(t, "PLANNING")
        t = ATS.mark_read_action(t, "a1", "trade_risk_summary")
        self.assertEqual(t["state"], "EXECUTING_READS")
        t = ATS.revalidate_for_company_change(t, 99)
        self.assertEqual(t["state"], "VALIDATING")
        self.assertEqual(t["company_id"], 99)
        self.assertEqual(t["executed_action_ids"], [])
        self.assertEqual(t["block_reason"], "company_changed")

    def test_merge_preserves_agent_task_across_live_heartbeat(self):
        task = ATS.transition(ATS.new_task(company_id=1), "VALIDATING")
        task = ATS.transition(task, "PLANNING")
        first = ATS.merge_into_result({}, live={"stage": "start"}, agent_task=task)
        self.assertEqual(first["agent_task"]["state"], "PLANNING")
        second = ATS.merge_into_result(first, live={"stage": "heartbeat", "details": {}})
        self.assertEqual(second["agent_task"]["state"], "PLANNING")
        self.assertEqual(second["live"]["stage"], "heartbeat")


class KernelDurableStateTests(unittest.TestCase):
    def test_kernel_emits_agent_task_completed(self):
        w = FakeWorker(tool_results={"supplier_performance_summary": supplier_rows()})
        text, meta = K.run_kernel(
            w,
            job("عملکرد تامین کننده را بررسی کن"),
            READ_TOOLS,
            "supplier.performance",
        )
        self.assertTrue(text)
        self.assertIn("agent_task", meta)
        self.assertEqual(meta["agent_task"]["schema"], ATS.SCHEMA)
        self.assertEqual(meta["agent_task"]["state"], "COMPLETED")
        self.assertGreaterEqual(len(meta["agent_task"]["tool_sequence"]), 1)
        stages = [s for s, _, _ in w.traces]
        self.assertIn("kernel_task_state", stages)

    def test_kernel_rejects_invented_tool_via_available_set(self):
        w = FakeWorker(tool_results={"supplier_performance_summary": supplier_rows()})
        j = job("عملکرد تامین کننده را بررسی کن")
        # Force an invented tool after primary by patching actions path via unavailable tool execute.
        state_task = ATS.transition(ATS.new_task(company_id=1), "VALIDATING")
        state_task = ATS.transition(state_task, "PLANNING")
        state = {
            "executed_action_ids": set(),
            "tool_sequence": [],
            "available_tools": {"supplier_performance_summary"},
            "agent_task": state_task,
            "evidence": [],
            "observations": [],
            "limitations": [],
            "prompt": "x",
            "capability": "supplier.performance",
            "entities": [],
            "explicit_entities": [],
            "planner_calls": 0,
            "deadline": 1e18,
            "planner_mode": "deterministic",
        }
        with self.assertRaises(RuntimeError) as ctx:
            K._execute_action(w, j, state, {
                "id": "evil.1",
                "tool": "drop_all_vouchers",
                "arguments": {},
            })
        self.assertIn("invalid_tool", str(ctx.exception))
        self.assertEqual(state["agent_task"]["state"], "BLOCKED")
        self.assertEqual(len(w.tool_calls), 0)

    def test_idempotent_resume_skips_repeated_primary_action(self):
        prior = ATS.transition(ATS.new_task(company_id=1, capability="supplier.performance"), "VALIDATING")
        prior = ATS.transition(prior, "PLANNING")
        prior = ATS.mark_read_action(prior, "supplier.performance.primary", "supplier_performance_summary")
        # Leave mid-flight so resume continues without re-running primary.
        prior["state"] = "EXECUTING_READS"
        calls = {"n": 0}

        def counting(_args):
            calls["n"] += 1
            return supplier_rows()

        w = FakeWorker(tool_results={"supplier_performance_summary": counting})
        text, meta = K.run_kernel(
            w,
            job("عملکرد تامین کننده", result_json={"agent_task": prior}),
            READ_TOOLS,
            "supplier.performance",
        )
        # Skipped re-execution leaves no fresh evidence → partial is honest; never re-call primary.
        self.assertIn(meta["agent_task"]["state"], {"COMPLETED", "PARTIALLY_COMPLETED"})
        primary_calls = [c for c in w.tool_calls if c[0] == "supplier_performance_summary"]
        self.assertEqual(len(primary_calls), 0)

    def test_terminal_resume_is_noop(self):
        prior = ATS.transition(ATS.new_task(company_id=1, capability="supplier.performance"), "VALIDATING")
        for s in ("PLANNING", "EXECUTING_READS", "ANALYZING", "COMPLETED"):
            if ATS.can_transition(prior["state"], s):
                prior = ATS.transition(prior, s)
        prior["tool_sequence"] = ["supplier_performance_summary"]
        w = FakeWorker(tool_results={"supplier_performance_summary": supplier_rows()})
        text, meta = K.run_kernel(
            w,
            job("عملکرد تامین کننده", result_json=json.dumps({"agent_task": prior})),
            READ_TOOLS,
            "supplier.performance",
        )
        self.assertEqual(meta["agent_task"]["state"], "COMPLETED")
        self.assertEqual(meta["mode"], "agent_intelligence_kernel_resume_terminal")
        self.assertEqual(w.tool_calls, [])


if __name__ == "__main__":
    unittest.main()
