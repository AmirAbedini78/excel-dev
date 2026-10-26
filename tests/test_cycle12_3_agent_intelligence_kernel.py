from __future__ import annotations

import json
import sys
import tempfile
import time
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENGINE = ROOT / "engine"
if str(ENGINE) not in sys.path:
    sys.path.insert(0, str(ENGINE))

import agent_intelligence_kernel as K


READ_TOOLS = [
    {"name": "supplier_performance_summary", "mode": "read"},
    {"name": "document_analytics", "mode": "read"},
    {"name": "search_trade_cases", "mode": "read"},
    {"name": "party_ledger", "mode": "read"},
    {"name": "trade_risk_summary", "mode": "read"},
    {"name": "trade_case_snapshot", "mode": "read"},
    {"name": "landed_cost_summary", "mode": "read"},
    {"name": "shipment_commitment_impact", "mode": "read"},
    {"name": "create_trade_case", "mode": "proposal"},
]


def env(attached=None, page=None):
    return {
        "context_envelope": {
            "version": "v2",
            "validated": True,
            "company_id": 1,
            "attached_entities": attached or [],
            "current_page": {"entities": page or []},
        }
    }


def job(prompt, attached=None, page=None):
    return {"id": 77, "company_id": 1, "prompt": prompt, "context": env(attached, page), "lease_token": "x"}


def supplier_rows(ids=(11, 22)):
    names = {11: "ابزار دقیق سپهر", 22: "تامین برق ایرانیان", 33: "پخش صنعتی کارن"}
    rows = []
    for i, pid in enumerate(ids):
        rows.append({
            "party_id": pid,
            "code": f"SUP-{pid}",
            "name": names.get(pid, f"تامین کننده {pid}"),
            "purchase_document_count": 2,
            "purchase_net_total": 2_000_000_000 - i * 100_000_000,
            "ordered_qty": 100.0,
            "accepted_qty": 0.0,
            "rejected_qty": 0.0,
            "open_qty": 70.0 if pid == 11 else 20.0,
            "receipt_fill_rate": 0.0,
            "receipt_acceptance_rate": None,
            "trade_case_count": 1 if pid == 22 else 0,
            "active_trade_case_count": 1 if pid == 22 else 0,
            "high_risk_cases": 1 if pid == 22 else 0,
            "medium_risk_cases": 0,
            "max_delay_days": 0.0,
            "cost_overrun_cases": 0,
            "arrival_sample": 0,
            "on_time_arrivals": 0,
            "late_arrivals": 0,
            "on_time_rate": None,
        })
    return {"rows": rows, "limitations": {"sla": "SLA کامل نیست."}}


class FakeWorker:
    def __init__(self, *, planner=None, analyst=None, tool_results=None):
        self.cfg = {"kernel_planner_mode": "model"}
        self.planner = list(planner or [K.FINISH_ACTION])
        self.analyst = analyst
        self.tool_results = tool_results or {}
        self.tool_calls = []
        self.traces = []
        self.model_calls = []

    def trace(self, job, stage, message, data=None):
        self.traces.append((stage, message, data or {}))

    def model_for(self, role):
        return {"agent": "agent-test", "analysis": "analysis-test", "fallback": "fallback-test"}.get(role, "fallback-test")

    def tool(self, job, name, args, call_id):
        self.tool_calls.append((name, dict(args), call_id))
        value = self.tool_results.get(name)
        if callable(value):
            return value(dict(args))
        return value if value is not None else {}

    def ollama_chat(self, job, round_no, messages, tools, **kwargs):
        model = kwargs.get("model")
        self.model_calls.append(model)
        schema = kwargs.get("response_format") or {}
        props = schema.get("properties") if isinstance(schema, dict) else {}
        if isinstance(props, dict) and "action_id" in props and "finding_ids" not in props:
            selected = self.planner.pop(0) if self.planner else K.FINISH_ACTION
            return {"message": {"content": json.dumps({"action_id": selected}, ensure_ascii=False)}}
        if callable(self.analyst):
            payload = self.analyst(messages)
        elif isinstance(self.analyst, dict):
            payload = self.analyst
        else:
            payload = {"finding_ids": ["F0"], "action_id": "A0"}
        return {"message": {"content": json.dumps(payload, ensure_ascii=False)}}


class KernelTests(unittest.TestCase):
    def test_supplier_two_explicit_refs_preserve_exact_ids(self):
        w = FakeWorker(tool_results={"supplier_performance_summary": supplier_rows((11, 22))})
        j = job(
            "این دوتا تامین کننده رو مقایسه کن و بگو کدوم مناسب تره",
            attached=[
                {"type": "party.supplier", "id": 11, "label": "ابزار دقیق سپهر"},
                {"type": "party.supplier", "id": 22, "label": "تامین برق ایرانیان"},
            ],
        )
        text, meta = K.run_kernel(w, j, READ_TOOLS, "supplier.performance")
        self.assertEqual(w.tool_calls[0][0], "supplier_performance_summary")
        self.assertEqual(w.tool_calls[0][1]["party_ids"], [11, 22])
        self.assertEqual(meta["mode"], "agent_intelligence_kernel_read")
        self.assertIn("جمع‌بندی تصمیم", text)
        self.assertNotIn("Grounded / Structured Evidence", text)

    def test_explicit_supplier_plus_company_fails_closed_not_portfolio(self):
        w = FakeWorker(tool_results={"supplier_performance_summary": supplier_rows()})
        j = job(
            "این دوتا تامین کننده رو مقایسه کن",
            attached=[
                {"type": "party.supplier", "id": 11, "label": "ابزار دقیق سپهر"},
                {"type": "company", "id": 2, "label": "شرکت توسعه صنعت البرز"},
            ],
        )
        text, meta = K.run_kernel(w, j, READ_TOOLS, "supplier.performance")
        self.assertEqual(w.tool_calls, [])
        self.assertEqual(meta["block_reason"], "supplier_entity_type_mismatch")
        self.assertIn("party.supplier", text)
        self.assertIn("company", text)
        self.assertIn("مقایسه کل سبد", text)

    def test_supplier_no_mentions_still_discovers_portfolio(self):
        w = FakeWorker(tool_results={"supplier_performance_summary": supplier_rows((11, 22, 33))})
        text, meta = K.run_kernel(w, job("کدوم تامین کننده عملکرد بهتری داشته و چرا؟"), READ_TOOLS, "supplier.performance")
        self.assertNotIn("party_ids", w.tool_calls[0][1])
        self.assertIn("جمع‌بندی تصمیم", text)
        self.assertLessEqual(sum(1 for x in text.splitlines() if x.startswith("• [E")), K.MAX_FINAL_EVIDENCE)
        self.assertEqual(meta["kernel"], K.KERNEL_CONTRACT)

    def test_dynamic_second_tool_is_selected_by_action_id_only(self):
        def analytics(args):
            self.assertEqual(args["party_id"], 11)
            self.assertEqual(args["kind"], "purchases")
            self.assertEqual(args["group_by"], "item")
            return {"groups": [{"label": "PLC", "line_total": 900_000_000}]}

        w = FakeWorker(
            planner=["supplier.purchase_mix.11", K.FINISH_ACTION],
            tool_results={"supplier_performance_summary": supplier_rows((11, 22)), "document_analytics": analytics},
        )
        text, meta = K.run_kernel(w, job("کدوم تامین کننده بهتره؟"), READ_TOOLS, "supplier.performance")
        self.assertEqual([x[0] for x in w.tool_calls], ["supplier_performance_summary", "document_analytics"])
        self.assertEqual(meta["tool_sequence"], ["supplier_performance_summary", "document_analytics"])
        self.assertIn("جمع‌بندی تصمیم", text)

    def test_planner_invalid_action_cannot_create_tool_or_arguments(self):
        w = FakeWorker(
            planner=["create_trade_case"],
            tool_results={"supplier_performance_summary": supplier_rows((11, 22)), "document_analytics": {"groups": []}},
        )
        K.run_kernel(w, job("کدوم تامین کننده بهتره؟"), READ_TOOLS, "supplier.performance")
        names = [x[0] for x in w.tool_calls]
        self.assertNotIn("create_trade_case", names)
        self.assertTrue(set(names).issubset({"supplier_performance_summary", "document_analytics", "search_trade_cases", "party_ledger"}))

    def test_trade_risk_can_replan_to_case_snapshot(self):
        risk = {"risk_count": 1, "rows": [{
            "trade_case_id": 51, "case_no": "TRD-X", "supplier_name": "تامین برق ایرانیان",
            "risk_level": "high", "risk_score": 4, "reasons": ["customs_hold"],
            "shipment_status": "customs", "clearance_status": "hold", "delay_days": 0,
            "projected_landed_total_irr": 620_000_000,
        }]}
        snapshot = {"case": {"id": 51, "case_no": "TRD-X", "status": "customs", "clearance_status": "hold"}, "shipments": [], "landed_cost": {"projected_landed_total_irr": 620_000_000}}
        w = FakeWorker(planner=["trade.snapshot.51", K.FINISH_ACTION], tool_results={"trade_risk_summary": risk, "trade_case_snapshot": snapshot})
        text, meta = K.run_kernel(w, job("چه چیزی الان در بازرگانی بیشترین ریسک را دارد و چرا؟"), READ_TOOLS, "trade.risk")
        self.assertEqual([x[0] for x in w.tool_calls], ["trade_risk_summary", "trade_case_snapshot"])
        self.assertIn("جمع‌بندی تصمیم", text)
        self.assertEqual(meta["capability"], "trade.risk")

    def test_shipment_requires_real_context(self):
        w = FakeWorker()
        text, meta = K.run_kernel(w, job("اگر این محموله دیر برسد چه مشتری هایی اثر می گیرند؟"), READ_TOOLS, "shipment.impact")
        self.assertEqual(w.tool_calls, [])
        self.assertEqual(meta["block_reason"], "shipment_context_required")
        self.assertIn("پرونده بازرگانی", text)

    def test_shipment_page_context_is_accepted(self):
        impact = {
            "trade_case_id": 51,
            "case": {"id": 51, "case_no": "TRD-X"},
            "shipment": {"status": "customs", "delay_days": 0},
            "summary": {"affected_sales_count": 2, "affected_customer_count": 1, "items_with_trade_case_dependency_signal": 1},
            "item_exposure": [{"item_id": 5, "item_name": "PLC", "outstanding_sales_qty": 4, "available": 0, "uncovered_after_current_available": 4, "potential_dependency_on_this_trade_case": True}],
            "affected_sales": [{"sales_doc_id": 9, "document_no": "S-1", "customer_name": "مشتری الف", "outstanding_quantity": 4, "unreserved_quantity": 4}],
            "limitations": {"causality": "اثر، exposure است نه علیت قطعی."},
        }
        w = FakeWorker(planner=[K.FINISH_ACTION], tool_results={"shipment_commitment_impact": impact})
        j = job("اگر این محموله دیر برسد کدام تعهدهای فروش تحت تاثیرند؟", page=[{"type": "trade.case", "id": 51, "label": "TRD-X"}])
        text, meta = K.run_kernel(w, j, READ_TOOLS, "shipment.impact")
        self.assertEqual(w.tool_calls[0][1]["trade_case_id"], 51)
        self.assertIn("جمع‌بندی تصمیم", text)
        self.assertEqual(meta["capability"], "shipment.impact")

    def test_invalid_reasoning_model_falls_back_to_useful_decision_not_dump(self):
        invalid = {
            "decision": {"text": "سپهر با ۲ سند بهتر است", "evidence_ids": ["E1"]},
            "why": {"text": "ریسک کمتری دارد", "evidence_ids": ["E1"]},
            "tradeoff": {"text": "داده محدود است", "evidence_ids": ["E1"]},
            "next_action": "داده را کامل کن", "confidence": "medium", "limitation": "اطلاعات ناقص است",
        }
        w = FakeWorker(analyst=invalid, tool_results={"supplier_performance_summary": supplier_rows((11, 22))})
        text, meta = K.run_kernel(w, job("کدوم تامین کننده بهتره؟"), READ_TOOLS, "supplier.performance")
        self.assertEqual(meta["synthesis"], "deterministic_decision_fallback")
        self.assertIn("جمع‌بندی تصمیم", text)
        self.assertNotIn("مدل تحلیلی در این اجرا پاسخ قابل اعتبارسنجی تولید نکرد", text)
        self.assertNotIn("Evidence Pack بالا", text)
        self.assertLessEqual(sum(1 for x in text.splitlines() if x.startswith("• [E")), K.MAX_FINAL_EVIDENCE)

    def test_write_request_bypasses_installed_kernel(self):
        class W:
            def process_agent(self, j, t):
                return "BASE-WRITE", {"mode": "base"}
        K.install_agent_intelligence_kernel(W)
        w = W()
        text, meta = w.process_agent(job("برای این تامین کننده فاکتور ثبت کن"), READ_TOOLS)
        self.assertEqual(text, "BASE-WRITE")
        self.assertEqual(meta["mode"], "base")

    def test_kernel_wrapper_intercepts_supported_read(self):
        class W(FakeWorker):
            def process_agent(self, j, t):
                return "BASE", {"mode": "base"}
        K.install_agent_intelligence_kernel(W)
        w = W(tool_results={"supplier_performance_summary": supplier_rows((11, 22))})
        text, meta = w.process_agent(job("کدوم تامین کننده بهتره؟"), READ_TOOLS)
        self.assertEqual(meta["mode"], "agent_intelligence_kernel_read")
        self.assertNotEqual(text, "BASE")

    def test_proposal_descriptors_never_enter_available_action_set(self):
        tools = K._available_read_tools(READ_TOOLS)
        self.assertNotIn("create_trade_case", tools)
        self.assertIn("trade_risk_summary", tools)

    def test_model_cannot_supply_free_text_numbers_or_unissued_ids(self):
        brief = K.DB.build({"capability": "supplier.performance", "supplier_rows": supplier_rows()["rows"]})
        for payload in ({"finding_ids": ["F0"], "action_id": "A0", "text": "۲۰ درصد بهتر"},
                        {"finding_ids": ["F999"], "action_id": "A0"},
                        {"finding_ids": ["F0", "F0"], "action_id": "A0"}):
            with self.assertRaises(ValueError):
                K.DB.validate(payload, brief)

    def test_trace_exposes_kernel_loop_without_chain_of_thought(self):
        w = FakeWorker(tool_results={"supplier_performance_summary": supplier_rows((11, 22))})
        K.run_kernel(w, job("کدوم تامین کننده بهتره؟"), READ_TOOLS, "supplier.performance")
        stages = [x[0] for x in w.traces]
        for expected in ("kernel_goal", "kernel_capability_retrieval", "kernel_tool", "kernel_observation", "kernel_answer", "kernel_verify"):
            self.assertIn(expected, stages)
        self.assertFalse(any("chain" in json.dumps(x, ensure_ascii=False).lower() for x in w.traces))

    def test_tool_budget_is_bounded(self):
        w = FakeWorker(
            planner=["supplier.purchase_mix.11", "supplier.purchase_mix.22", K.FINISH_ACTION],
            tool_results={"supplier_performance_summary": supplier_rows((11, 22)), "document_analytics": {"groups": []}},
        )
        _, meta = K.run_kernel(w, job("کدوم تامین کننده بهتره؟"), READ_TOOLS, "supplier.performance")
        self.assertLessEqual(meta["tool_calls"], K.MAX_TOOL_CALLS)
        self.assertLessEqual(meta["planner_calls"], K.MAX_PLANNER_CALLS)

    def test_verified_decision_route_is_preferred_only_when_currently_configured(self):
        w = FakeWorker()
        with tempfile.TemporaryDirectory() as td:
            route = Path(td) / "agent_kernel_model_route.json"
            route.write_text(json.dumps({
                "analyst_model": "fallback-test",
                "mode": "model",
                "analyst_contract": K.ANALYST_CONTRACT,
                "kernel_version": K.PATCH_VERSION,
                "expires_epoch": time.time() + 120, "runtime_fingerprint": "offline",
                "configured_decision_models": ["agent-test", "fallback-test", "analysis-test"],
            }), encoding="utf-8")
            self.assertEqual(K._decision_models(w, route)[:3], ["fallback-test", "agent-test", "analysis-test"])

    def test_stale_decision_route_never_displaces_current_models(self):
        w = FakeWorker()
        with tempfile.TemporaryDirectory() as td:
            route = Path(td) / "agent_kernel_model_route.json"
            route.write_text(json.dumps({"analyst_model": "old-removed-model"}), encoding="utf-8")
            self.assertEqual(K._decision_models(w, route)[:3], ["agent-test", "fallback-test", "analysis-test"])

    def test_degraded_route_circuit_breaks_same_configured_analysts(self):
        w = FakeWorker()
        with tempfile.TemporaryDirectory() as td:
            route = Path(td) / "agent_kernel_model_route.json"
            route.write_text(json.dumps({
                "analyst_model": "",
                "mode": "deterministic_decision_fallback",
                "analyst_contract": K.ANALYST_CONTRACT,
                "kernel_version": K.PATCH_VERSION,
                "expires_epoch": time.time() + 120, "runtime_fingerprint": "offline",
                "configured_decision_models": ["agent-test", "fallback-test", "analysis-test"],
            }), encoding="utf-8")
            self.assertEqual(K._decision_models(w, route), [])

    def test_degraded_route_reopens_when_model_configuration_changes(self):
        class ChangedWorker(FakeWorker):
            def model_for(self, role):
                return {"agent": "agent-test", "analysis": "new-analysis", "fallback": "fallback-test"}.get(role, "fallback-test")

        w = ChangedWorker()
        with tempfile.TemporaryDirectory() as td:
            route = Path(td) / "agent_kernel_model_route.json"
            route.write_text(json.dumps({
                "analyst_model": "",
                "mode": "deterministic_decision_fallback",
                "analyst_contract": K.ANALYST_CONTRACT,
                "kernel_version": K.PATCH_VERSION,
                "expires_epoch": time.time() + 120, "runtime_fingerprint": "offline",
                "configured_decision_models": ["agent-test", "fallback-test", "analysis-test"],
            }), encoding="utf-8")
            self.assertEqual(K._decision_models(w, route), ["agent-test", "fallback-test", "new-analysis"])

    def test_safe_json_object_accepts_fenced_or_wrapped_complete_object(self):
        self.assertEqual(K._safe_json_object('```json\n{"decision":"ok"}\n```'), {"decision": "ok"})
        self.assertEqual(K._safe_json_object('prefix {"decision":"ok"} suffix'), {"decision": "ok"})

    def test_safe_json_object_rejects_truncated_json(self):
        with self.assertRaises((ValueError, json.JSONDecodeError)):
            K._safe_json_object('{"decision":{"text":"half"}')

    def test_compact_selection_uses_only_grounded_findings(self):
        brief = K.DB.build({"capability": "supplier.performance", "supplier_rows": supplier_rows()["rows"]})
        schema = K.DB.schema(brief)
        self.assertEqual(set(schema["properties"]), {"finding_ids", "action_id"})
        self.assertEqual(schema["properties"]["finding_ids"]["items"]["enum"], ["F0", "F1"])
        self.assertEqual(K.ANALYST_CONTRACT, "grounded_selection_v3")

    def test_kernel_semantic_benchmark(self):
        path = ROOT / "tests" / "fixtures" / "agent_intelligence_kernel_benchmark.json"
        rows = json.loads(path.read_text(encoding="utf-8"))
        self.assertGreaterEqual(len(rows), 50)
        for row in rows:
            entities = row.get("entities") or []
            got = K._capability(str(row["prompt"]), entities)
            self.assertEqual(got, row.get("capability"), msg=row["prompt"])
            if row.get("answer_contract") == "decision_first":
                self.assertTrue(row.get("must_not_dump_evidence"))


class SourceContractTests(unittest.TestCase):
    def test_worker_installs_kernel_after_legacy_supervisor_before_provider(self):
        s = (ROOT / "engine" / "worker.py").read_text(encoding="utf-8")
        self.assertLess(s.index("_install_business_supervisor(Worker)"), s.index("_install_agent_intelligence_kernel(Worker)"))
        self.assertLess(s.index("_install_agent_intelligence_kernel(Worker)"), s.index("_install_provider_gateway(Worker)"))
        self.assertLess(s.index("_install_agent_intelligence_kernel(Worker)"), s.index("_install_commercial_hardening(Worker)"))

    def test_docker_image_contains_kernel_and_real_smoke(self):
        s = (ROOT / "engine" / "Dockerfile").read_text(encoding="utf-8")
        self.assertIn("agent_intelligence_kernel.py", s)
        self.assertIn("agent_kernel_smoke.py", s)

    def test_smoke_rejects_shape_valid_but_semantically_wrong_model(self):
        import agent_kernel_smoke as smoke
        w = FakeWorker(analyst={"finding_ids": ["F0"], "action_id": "A0"})
        with self.assertRaisesRegex(ValueError, "semantic_priority"):
            smoke.qualify(w, "agent-test", time.monotonic() + 30)

    def test_canonical_docs_point_to_agent_kernel(self):
        required = [
            "docs/ai/00-START-HERE.md",
            "docs/ai/02-CURRENT-STATE.md",
            "docs/ai/03-ARCHITECTURE.md",
            "docs/ai/04-ROADMAP.md",
            "docs/ai/05-WORKFLOW-CONTRACTS.md",
            "docs/ai/19-ERPSMART-INTELLIGENCE-PLATFORM-MASTER-SPEC.md",
            "docs/ai/20-UNIVERSAL-BUSINESS-COPILOT-48H-MVP.md",
            "docs/ai/AI_DEVELOPMENT_HANDOFF_FA.md",
            "docs/ai/26-AGENT-INTELLIGENCE-KERNEL.md",
        ]
        for rel in required:
            text = (ROOT / rel).read_text(encoding="utf-8")
            self.assertIn("Agent Intelligence Kernel", text, msg=rel)
        self.assertIn("7415f1471573cce6e398317069567894a7209c4a", (ROOT / "docs/ai/00-START-HERE.md").read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
