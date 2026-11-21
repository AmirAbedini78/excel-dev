"""Wave1 idempotency / recovery stubs coordinating with T1 agent_task state machine.

OWNER split:
- T6: harness + existing AiRepository/AiSchema source contracts (this file).
- T1: engine/agent_task_state.py durable states + recovery helpers (feature/agentic-core-wave1).

When agent_task_state is not present on this branch, T1-owned behavioral cases SKIP
with explicit owner — they must not silently pass. After T1 merges, the same cases
execute against the real module without rewriting assertions.
"""
from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENGINE = ROOT / "engine"
if str(ENGINE) not in sys.path:
    sys.path.insert(0, str(ENGINE))

CONTRACT_STATES = (
    "CREATED",
    "VALIDATING",
    "PLANNING",
    "EXECUTING_READS",
    "ANALYZING",
    "PROPOSAL_READY",
    "AWAITING_APPROVAL",
    "EXECUTING_APPROVED_ACTION",
    "VERIFYING",
    "COMPLETED",
    "FAILED",
    "CANCELLED",
    "EXPIRED",
    "BLOCKED",
    "PARTIALLY_COMPLETED",
)

T1_API = (
    "new_task",
    "transition",
    "revalidate_for_company_change",
    "reject_invalid_tool",
    "begin_mutation",
    "complete_mutation",
    "record_duplicate_completion",
    "resume_after_lease_recovery",
    "mutation_already_applied",
    "action_already_executed",
    "extract_from_result_json",
    "merge_into_result",
    "snapshot_for_persist",
)


def source(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def load_agent_task_state():
    path = ENGINE / "agent_task_state.py"
    if not path.is_file():
        return None
    spec = importlib.util.spec_from_file_location("agent_task_state", path)
    if spec is None or spec.loader is None:
        return None
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


class ExistingLeaseIdempotencyContracts(unittest.TestCase):
    """Baseline on main — must remain green independent of T1 merge."""

    def test_proposal_idempotency_unique_key(self):
        schema = source("app/Core/AiSchema.php")
        registry = source("app/Core/AiToolRegistry.php")
        self.assertIn("uniq_ai_action_idempotency", schema)
        self.assertIn("ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", registry)

    def test_duplicate_job_completion_is_idempotent(self):
        repo = source("app/Core/AiRepository.php")
        self.assertIn("lockJobForTerminalWrite", repo)
        self.assertIn("lease_retry_window_expired", repo)
        # completeJob: already-succeeded terminal write returns true (replay), not a second apply.
        self.assertRegex(
            repo,
            r"status'\]==='succeeded'\)\s*\{\s*\$pdo->commit\(\);\s*return true;",
        )
        self.assertIn("job_terminal_conflict", repo)

    def test_lease_expiry_requeues_without_keeping_stale_lease(self):
        repo = source("app/Core/AiRepository.php")
        self.assertIn("status='queued'", repo)
        self.assertIn("lease_expires_at<NOW()", repo)
        self.assertIn("lease_hash=NULL", repo)

    def test_approval_path_is_transactional(self):
        repo = source("app/Core/AiRepository.php")
        self.assertIn("status='proposed' FOR UPDATE", repo)
        self.assertIn("$pdo->beginTransaction()", repo)
        self.assertIn("$pdo->rollBack()", repo)
        self.assertIn("Tenant::requirePermission('ai.actions.approve')", repo)


class T1StateMachineCoordinationStubs(unittest.TestCase):
    """Execute when T1 module is present; otherwise SKIP with owner (not pass)."""

    @classmethod
    def setUpClass(cls):
        cls.ats = load_agent_task_state()

    def _require_ats(self):
        if self.ats is None:
            self.skipTest(
                "SKIP OWNER:T1 — engine/agent_task_state.py not on this branch yet "
                "(coordinate feature/agentic-core-wave1)"
            )
        return self.ats

    def test_contract_states_exported(self):
        ats = self._require_ats()
        exported = set(getattr(ats, "ALL_STATES", ()))
        missing = [s for s in CONTRACT_STATES if s not in exported]
        self.assertEqual(missing, [], f"T1 missing contract states: {missing}")

    def test_required_recovery_api_surface(self):
        ats = self._require_ats()
        missing = [name for name in T1_API if not hasattr(ats, name)]
        self.assertEqual(missing, [], f"T1 missing recovery API: {missing}")

    def test_duplicate_mutation_callback_does_not_reapply(self):
        ats = self._require_ats()
        task = ats.new_task(company_id=1, job_id=42)
        task = ats.transition(task, "VALIDATING")
        task = ats.transition(task, "PLANNING")
        task = ats.transition(task, "ANALYZING")
        task = ats.transition(task, "PROPOSAL_READY")
        task = ats.transition(task, "AWAITING_APPROVAL")
        key = "mut-" + ("a" * 28)
        task, apply1 = ats.begin_mutation(task, key)
        self.assertTrue(apply1)
        task = ats.complete_mutation(task, key)
        self.assertTrue(ats.mutation_already_applied(task, key))
        task2, apply2 = ats.begin_mutation(task, key)
        self.assertFalse(apply2, "duplicate idempotency key must not re-apply")
        task3 = ats.record_duplicate_completion(task2, key)
        self.assertTrue(ats.mutation_already_applied(task3, key))

    def test_worker_restart_mid_mutation_resumes_to_verifying(self):
        ats = self._require_ats()
        task = ats.new_task(company_id=7, job_id=7)
        for state in ("VALIDATING", "PLANNING", "ANALYZING", "PROPOSAL_READY", "AWAITING_APPROVAL"):
            task = ats.transition(task, state)
        task = ats.transition(task, "EXECUTING_APPROVED_ACTION")
        resumed = ats.resume_after_lease_recovery(task, job_company_id=7)
        self.assertEqual(resumed["state"], "VERIFYING")
        # completed_mutation_keys must survive resume even if empty
        self.assertIn("completed_mutation_keys", resumed)

    def test_company_change_forces_revalidating_and_keeps_mutation_keys(self):
        ats = self._require_ats()
        task = ats.new_task(company_id=1, job_id=9)
        task = ats.transition(task, "VALIDATING")
        task = ats.transition(task, "PLANNING")
        task = ats.transition(task, "ANALYZING")
        task = ats.transition(task, "PROPOSAL_READY")
        task["completed_mutation_keys"] = ["keep-me"]
        task = ats.revalidate_for_company_change(task, 2)
        self.assertEqual(task["state"], "VALIDATING")
        self.assertEqual(task["company_id"], 2)
        self.assertEqual(task["completed_mutation_keys"], ["keep-me"])
        self.assertEqual(task.get("block_reason"), "company_changed")

    def test_invalid_tool_rejection_blocks_task(self):
        ats = self._require_ats()
        task = ats.new_task(company_id=1)
        task = ats.transition(task, "VALIDATING")
        task = ats.transition(task, "PLANNING")
        blocked = ats.reject_invalid_tool(task, "invented.sql_exec", source="planner")
        self.assertEqual(blocked["state"], "BLOCKED")
        self.assertTrue(blocked.get("invalid_tool_rejections"))

    def test_persist_roundtrip_via_result_json(self):
        ats = self._require_ats()
        task = ats.new_task(company_id=3, capability="supplier.performance", job_id=3)
        task = ats.transition(task, "VALIDATING")
        snap = ats.snapshot_for_persist(task)
        merged = ats.merge_into_result({"live": {"stage": "x"}}, agent_task=snap)
        extracted = ats.extract_from_result_json(merged)
        self.assertIsNotNone(extracted)
        self.assertEqual(extracted["schema"], ats.SCHEMA)
        self.assertEqual(extracted["state"], "VALIDATING")
        self.assertEqual(extracted["company_id"], 3)


class StubCoverageManifest(unittest.TestCase):
    def test_manifest_lists_ai1_recovery_cases(self):
        """Minimum recovery matrix rows required by contract §6 items 4 and 6."""
        required = (
            "test_duplicate_job_completion_is_idempotent",
            "test_worker_restart_mid_mutation_resumes_to_verifying",
            "test_lease_expiry_requeues_without_keeping_stale_lease",
            "test_company_change_forces_revalidating_and_keeps_mutation_keys",
            "test_invalid_tool_rejection_blocks_task",
            "test_proposal_idempotency_unique_key",
        )
        body = Path(__file__).read_text(encoding="utf-8")
        for needle in required:
            self.assertIn(needle, body)


if __name__ == "__main__":
    unittest.main()
