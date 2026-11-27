#!/usr/bin/env python3
"""Pure decision helpers for staging-deploy apply / CI-gate policy.

Kept free of SSH/network so unit tests can prove GitHub Actions expression
semantics without a runner. Mirrors the fixed workflow rules:

  APPLY = (event == push) OR (event == workflow_dispatch AND inputs.apply)
  Real apply requires: APPLY + ref == refs/heads/main + SSH ready + identity
  skip_await_ci is NEVER allowed when APPLY is true
"""
from __future__ import annotations

from typing import Any


def resolve_apply(*, event_name: str, inputs_apply: bool | None) -> bool:
    """Correct semantics for the former broken expression:

        github.event_name == 'workflow_dispatch' && inputs.apply || true

    which coerced false → true because `false || true` is true in JS/GH expressions.
    """
    name = (event_name or "").strip()
    if name == "push":
        return True
    if name == "workflow_dispatch":
        return bool(inputs_apply)
    # Unknown event: never apply by default.
    return False


def allow_skip_await_ci(*, apply: bool, skip_requested: bool) -> bool:
    """CI gates may be skipped only on dry-run (apply=false)."""
    if apply:
        return False
    return bool(skip_requested)


def allow_real_apply(
    *,
    apply: bool,
    git_ref: str,
    ssh_ready: bool,
    identity_ok: bool,
    await_ci_result: str,
) -> tuple[bool, str]:
    """Gate real remote mutation. Returns (allowed, reason_code)."""
    if not apply:
        return False, "dry_run"
    if (git_ref or "").strip() != "refs/heads/main":
        return False, "ref_not_main"
    if not ssh_ready:
        return False, "ssh_missing"
    if not identity_ok:
        return False, "identity_required"
    if (await_ci_result or "").strip() != "success":
        return False, "ci_gates_required"
    return True, "ok"


def workflow_apply_env_value(event_name: str, inputs_apply: Any) -> str:
    """Serialize boolean the same way the Actions step compares (`true`/`false`)."""
    return "true" if resolve_apply(event_name=event_name, inputs_apply=bool(inputs_apply)) else "false"


def selftest() -> dict[str, Any]:
    checks = {
        "push_applies": resolve_apply(event_name="push", inputs_apply=False) is True,
        "dispatch_true": resolve_apply(event_name="workflow_dispatch", inputs_apply=True) is True,
        "dispatch_false_stays_false": resolve_apply(event_name="workflow_dispatch", inputs_apply=False) is False,
        "unknown_refuses": resolve_apply(event_name="pull_request", inputs_apply=True) is False,
        "skip_blocked_on_apply": allow_skip_await_ci(apply=True, skip_requested=True) is False,
        "skip_ok_on_dry_run": allow_skip_await_ci(apply=False, skip_requested=True) is True,
        "apply_requires_main": allow_real_apply(
            apply=True,
            git_ref="refs/heads/feat/x",
            ssh_ready=True,
            identity_ok=True,
            await_ci_result="success",
        )[0]
        is False,
        "apply_ok_main": allow_real_apply(
            apply=True,
            git_ref="refs/heads/main",
            ssh_ready=True,
            identity_ok=True,
            await_ci_result="success",
        )[0]
        is True,
        "apply_blocked_when_ci_skipped": allow_real_apply(
            apply=True,
            git_ref="refs/heads/main",
            ssh_ready=True,
            identity_ok=True,
            await_ci_result="skipped",
        )[0]
        is False,
        "env_false_string": workflow_apply_env_value("workflow_dispatch", False) == "false",
        "env_true_string": workflow_apply_env_value("push", False) == "true",
    }
    return {"ok": all(checks.values()), "checks": checks}


def main() -> int:
    import json
    import sys

    result = selftest()
    print(json.dumps(result, indent=2))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
