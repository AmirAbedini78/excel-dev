from __future__ import annotations
import json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

class Cycle9UniversalCopilotSpecContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.north=(ROOT/"docs/ai/01-NORTH-STAR.md").read_text(encoding="utf-8")
        cls.arch=(ROOT/"docs/ai/03-ARCHITECTURE.md").read_text(encoding="utf-8")
        cls.roadmap=(ROOT/"docs/ai/04-ROADMAP.md").read_text(encoding="utf-8")
        cls.master=(ROOT/"docs/ai/19-ERPSMART-INTELLIGENCE-PLATFORM-MASTER-SPEC.md").read_text(encoding="utf-8")
        cls.mvp=(ROOT/"docs/ai/20-UNIVERSAL-BUSINESS-COPILOT-48H-MVP.md").read_text(encoding="utf-8")
        cls.state=json.loads((ROOT/"docs/ai/04-docops/task_state.json").read_text(encoding="utf-8"))

    def test_north_star_is_intelligence_platform_with_copilot(self):
        self.assertIn("ERPSMART Intelligence Platform",self.north)
        self.assertIn("ERPSMART Business Copilot",self.north)
        self.assertIn("Source of Truth",self.north)
        self.assertIn("Role-Adaptive",self.north)

    def test_master_spec_has_universal_context_and_entity_contracts(self):
        for token in [
            "Universal Entity Registry",
            "Context Envelope v2",
            "Universal Quick Preview",
            "@   Business Entity mention",
            "Current Page Context",
            "Selection Context",
        ]:
            self.assertIn(token,self.master)

    def test_master_spec_has_composable_skill_planner_and_learning_contracts(self):
        for token in [
            "Skill Registry contract",
            "Workflow Grammar",
            "Capability Retrieval",
            "Supervisor/Manager",
            "Experience Store",
            "Offline Eval",
            "Action Risk Engine",
        ]:
            self.assertIn(token,self.master)

    def test_architecture_preserves_trust_boundaries_and_single_supervisor_first(self):
        self.assertIn("Context is not authority",self.arch)
        self.assertIn("P0 maximizes one Supervisor/Manager",self.arch)
        self.assertIn("Multi-agent execution is deferred",self.arch)

    def test_48h_plan_is_demoable_and_incremental(self):
        for token in [
            "D0–D2 — MVP A",
            "Global Copilot shell",
            "@` mention dropdown",
            "Customer Business Review",
            "D2–D4 — MVP B",
            "D4–D6 — MVP C",
            "D6–D8 — MVP D",
        ]:
            self.assertIn(token,self.mvp)
        self.assertIn("No increment is allowed to trade structural quality",self.mvp)

    def test_task_state_moves_to_cycle9_without_reopening_cycle7(self):
        self.assertEqual(self.state["baseline_commit"],"338e13419d091e6e1d3a5e7fd836ac7296e88e6b")
        self.assertEqual(self.state["baseline_status"],"PARTIAL")
        self.assertEqual(self.state["current_milestone"],"v10.6 Cycle 9 Universal Business Copilot Foundation")
        self.assertEqual(self.state["milestone_status"],"PLANNED")
        self.assertIn("Cycle 7",(ROOT/"docs/ai/02-CURRENT-STATE.md").read_text(encoding="utf-8"))

    def test_roadmap_has_two_day_cadence_and_deferred_heavy_infrastructure(self):
        self.assertIn("Immediate two-day cadence",self.roadmap)
        self.assertIn("D0–D2",self.roadmap)
        self.assertIn("Graph DB",self.roadmap)
        self.assertIn("multi-agent",self.roadmap.lower())

if __name__=="__main__":unittest.main()
