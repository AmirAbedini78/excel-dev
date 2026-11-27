#!/usr/bin/env python3
"""Independent unit tests for staging apply boolean semantics.

Proves the historical bug:
  `${{ github.event_name == 'workflow_dispatch' && inputs.apply || true }}`
coerces workflow_dispatch+apply=false → true.
"""
from __future__ import annotations

import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

from staging_apply_decision import (  # noqa: E402
    allow_real_apply,
    allow_skip_await_ci,
    resolve_apply,
    selftest,
    workflow_apply_env_value,
)


def broken_github_expression(event_name: str, inputs_apply: bool) -> bool:
    """Simulate the previous Actions expression evaluated left-to-right like JS."""
    # github.event_name == 'workflow_dispatch' && inputs.apply || true
    return bool((event_name == "workflow_dispatch" and inputs_apply) or True)


def fixed_github_expression(event_name: str, inputs_apply: bool) -> bool:
    """Fixed form used by the workflow + helper."""
    return resolve_apply(event_name=event_name, inputs_apply=inputs_apply)


class StagingApplyDecisionTests(unittest.TestCase):
    def test_selftest_ok(self) -> None:
        self.assertTrue(selftest()["ok"])

    def test_broken_expression_coerces_false_to_true(self) -> None:
        # Document the bug we fixed.
        self.assertTrue(broken_github_expression("workflow_dispatch", False))

    def test_fixed_expression_preserves_false(self) -> None:
        self.assertFalse(fixed_github_expression("workflow_dispatch", False))
        self.assertEqual(workflow_apply_env_value("workflow_dispatch", False), "false")

    def test_push_always_apply(self) -> None:
        self.assertTrue(resolve_apply(event_name="push", inputs_apply=False))
        self.assertEqual(workflow_apply_env_value("push", False), "true")

    def test_dispatch_true_applies(self) -> None:
        self.assertTrue(resolve_apply(event_name="workflow_dispatch", inputs_apply=True))

    def test_skip_await_never_on_apply(self) -> None:
        self.assertFalse(allow_skip_await_ci(apply=True, skip_requested=True))
        self.assertTrue(allow_skip_await_ci(apply=False, skip_requested=True))

    def test_real_apply_main_only(self) -> None:
        ok, reason = allow_real_apply(
            apply=True,
            git_ref="refs/heads/feature/x",
            ssh_ready=True,
            identity_ok=True,
            await_ci_result="success",
        )
        self.assertFalse(ok)
        self.assertEqual(reason, "ref_not_main")

    def test_real_apply_requires_ci_success(self) -> None:
        ok, reason = allow_real_apply(
            apply=True,
            git_ref="refs/heads/main",
            ssh_ready=True,
            identity_ok=True,
            await_ci_result="skipped",
        )
        self.assertFalse(ok)
        self.assertEqual(reason, "ci_gates_required")


if __name__ == "__main__":
    unittest.main(verbosity=2)
