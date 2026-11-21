#!/usr/bin/env python3
"""Durable agent task state machine for ERPSMART Agentic Next Wave1.

Stores progress as additive JSON under ai_jobs.result_json.agent_task.
Recovery helpers ensure lease expiry / restart / duplicate completion never
duplicate mutations. No parallel orchestration engine.
"""
from __future__ import annotations

import copy
import time
from typing import Any

SCHEMA = "erpsmart.agent_task.v1"

ACTIVE_STATES = (
    "CREATED",
    "VALIDATING",
    "PLANNING",
    "EXECUTING_READS",
    "ANALYZING",
    "PROPOSAL_READY",
    "AWAITING_APPROVAL",
    "EXECUTING_APPROVED_ACTION",
    "VERIFYING",
)

TERMINAL_STATES = (
    "COMPLETED",
    "FAILED",
    "CANCELLED",
    "EXPIRED",
    "BLOCKED",
    "PARTIALLY_COMPLETED",
)

ALL_STATES = ACTIVE_STATES + TERMINAL_STATES

# Allowed forward transitions (plus self-stay and terminal sinks).
_TRANSITIONS: dict[str, frozenset[str]] = {
    "CREATED": frozenset({"VALIDATING", "FAILED", "CANCELLED", "EXPIRED", "BLOCKED"}),
    "VALIDATING": frozenset({"PLANNING", "FAILED", "CANCELLED", "EXPIRED", "BLOCKED"}),
    "PLANNING": frozenset({"EXECUTING_READS", "ANALYZING", "FAILED", "CANCELLED", "EXPIRED", "BLOCKED"}),
    "EXECUTING_READS": frozenset({"PLANNING", "ANALYZING", "FAILED", "CANCELLED", "EXPIRED", "BLOCKED", "PARTIALLY_COMPLETED"}),
    "ANALYZING": frozenset({
        "PROPOSAL_READY", "COMPLETED", "PARTIALLY_COMPLETED",
        "FAILED", "CANCELLED", "EXPIRED", "BLOCKED",
    }),
    "PROPOSAL_READY": frozenset({"AWAITING_APPROVAL", "FAILED", "CANCELLED", "EXPIRED", "BLOCKED"}),
    "AWAITING_APPROVAL": frozenset({
        "EXECUTING_APPROVED_ACTION", "CANCELLED", "EXPIRED", "FAILED", "BLOCKED",
    }),
    "EXECUTING_APPROVED_ACTION": frozenset({
        "VERIFYING", "FAILED", "CANCELLED", "EXPIRED", "BLOCKED", "PARTIALLY_COMPLETED",
    }),
    "VERIFYING": frozenset({"COMPLETED", "FAILED", "PARTIALLY_COMPLETED", "BLOCKED"}),
    "COMPLETED": frozenset({"COMPLETED"}),
    "FAILED": frozenset({"FAILED"}),
    "CANCELLED": frozenset({"CANCELLED"}),
    "EXPIRED": frozenset({"EXPIRED"}),
    "BLOCKED": frozenset({"BLOCKED", "VALIDATING"}),  # company-change reopen only via revalidate()
    "PARTIALLY_COMPLETED": frozenset({"PARTIALLY_COMPLETED"}),
}


def _now() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def new_task(*, company_id: int, capability: str | None = None, job_id: int | None = None) -> dict[str, Any]:
    return {
        "schema": SCHEMA,
        "state": "CREATED",
        "company_id": int(company_id or 0),
        "capability": capability,
        "job_id": int(job_id or 0) or None,
        "executed_action_ids": [],
        "tool_sequence": [],
        "planner_calls": 0,
        "evidence_count": 0,
        "completed_mutation_keys": [],
        "mutation_attempts": 0,
        "block_reason": None,
        "invalid_tool_rejections": [],
        "transition_log": [],
        "updated_at": _now(),
    }


def extract_from_result_json(result_json: Any) -> dict[str, Any] | None:
    """Pull agent_task from result_json object/string or live.details fallback."""
    meta: dict[str, Any]
    if isinstance(result_json, str):
        if not result_json.strip():
            return None
        try:
            import json
            parsed = json.loads(result_json)
        except Exception:
            return None
        if not isinstance(parsed, dict):
            return None
        meta = parsed
    elif isinstance(result_json, dict):
        meta = result_json
    else:
        return None
    task = meta.get("agent_task")
    if isinstance(task, dict) and task.get("schema") == SCHEMA:
        return copy.deepcopy(task)
    live = meta.get("live") if isinstance(meta.get("live"), dict) else {}
    details = live.get("details") if isinstance(live.get("details"), dict) else {}
    nested = details.get("agent_task")
    if isinstance(nested, dict) and nested.get("schema") == SCHEMA:
        return copy.deepcopy(nested)
    return None


def merge_into_result(existing: Any, *, live: dict[str, Any] | None = None, agent_task: dict[str, Any] | None = None) -> dict[str, Any]:
    """Merge live progress + agent_task without dropping durable task state."""
    base: dict[str, Any] = {}
    if isinstance(existing, dict):
        base = copy.deepcopy(existing)
    elif isinstance(existing, str) and existing.strip():
        try:
            import json
            parsed = json.loads(existing)
            if isinstance(parsed, dict):
                base = parsed
        except Exception:
            base = {}
    prior_task = extract_from_result_json(base)
    if live is not None:
        base["live"] = live
    if isinstance(agent_task, dict) and agent_task.get("schema") == SCHEMA:
        base["agent_task"] = copy.deepcopy(agent_task)
    elif prior_task is not None:
        base["agent_task"] = prior_task
    return base


def can_transition(from_state: str, to_state: str) -> bool:
    allowed = _TRANSITIONS.get(str(from_state or ""), frozenset())
    return str(to_state) in allowed


def transition(task: dict[str, Any], to_state: str, *, reason: str | None = None) -> dict[str, Any]:
    out = copy.deepcopy(task) if isinstance(task, dict) else new_task(company_id=0)
    current = str(out.get("state") or "CREATED")
    target = str(to_state)
    if target not in ALL_STATES:
        raise ValueError(f"unknown_agent_task_state:{target}")
    if current == target:
        out["updated_at"] = _now()
        return out
    if not can_transition(current, target):
        raise ValueError(f"illegal_agent_task_transition:{current}->{target}")
    log = list(out.get("transition_log") or [])
    entry = {"from": current, "to": target, "at": _now()}
    if reason:
        entry["reason"] = str(reason)[:200]
    log.append(entry)
    out["transition_log"] = log[-40:]
    out["state"] = target
    out["updated_at"] = _now()
    if target in ("FAILED", "BLOCKED", "CANCELLED", "EXPIRED") and reason:
        out["block_reason"] = str(reason)[:300]
    return out


def require_company_revalidation(task: dict[str, Any], job_company_id: int) -> bool:
    prior = int(task.get("company_id") or 0)
    current = int(job_company_id or 0)
    return prior > 0 and current > 0 and prior != current


def revalidate_for_company_change(task: dict[str, Any], job_company_id: int) -> dict[str, Any]:
    """Company change forces re-VALIDATING (permissions + entity re-check)."""
    out = copy.deepcopy(task) if isinstance(task, dict) else new_task(company_id=job_company_id)
    if not require_company_revalidation(out, job_company_id):
        return out
    # Allowed escape from any non-COMPLETED sink back into VALIDATING for company change.
    out["company_id"] = int(job_company_id)
    out["block_reason"] = "company_changed"
    out["executed_action_ids"] = []
    out["tool_sequence"] = []
    out["planner_calls"] = 0
    out["evidence_count"] = 0
    # Keep completed_mutation_keys — mutations already applied must not replay.
    current = str(out.get("state") or "CREATED")
    if current in TERMINAL_STATES and current != "BLOCKED":
        # Soft-reopen via BLOCKED then VALIDATING for audit clarity.
        out["state"] = "BLOCKED"
        out = transition(out, "VALIDATING", reason="company_changed")
    elif current == "BLOCKED":
        out = transition(out, "VALIDATING", reason="company_changed")
    elif current == "CREATED":
        out = transition(out, "VALIDATING", reason="company_changed")
    else:
        # Jump: record synthetic log then set VALIDATING (contract-mandated reopen).
        log = list(out.get("transition_log") or [])
        log.append({"from": current, "to": "VALIDATING", "at": _now(), "reason": "company_changed"})
        out["transition_log"] = log[-40:]
        out["state"] = "VALIDATING"
        out["updated_at"] = _now()
    return out


def reject_invalid_tool(task: dict[str, Any], tool_id: str, *, source: str = "planner") -> dict[str, Any]:
    """Fail-closed coordination when model invents a tool ID."""
    out = copy.deepcopy(task)
    rejections = list(out.get("invalid_tool_rejections") or [])
    rejections.append({
        "tool_id": str(tool_id)[:120],
        "source": str(source)[:40],
        "at": _now(),
    })
    out["invalid_tool_rejections"] = rejections[-20:]
    state = str(out.get("state") or "CREATED")
    if state not in TERMINAL_STATES:
        try:
            out = transition(out, "BLOCKED", reason=f"invalid_tool:{tool_id}")
        except ValueError:
            out["state"] = "BLOCKED"
            out["block_reason"] = f"invalid_tool:{tool_id}"
            out["updated_at"] = _now()
    else:
        out["block_reason"] = f"invalid_tool:{tool_id}"
    return out


def mutation_already_applied(task: dict[str, Any], idempotency_key: str) -> bool:
    key = str(idempotency_key or "").strip()
    if not key:
        return False
    keys = {str(x) for x in (task.get("completed_mutation_keys") or [])}
    return key in keys


def begin_mutation(task: dict[str, Any], idempotency_key: str) -> tuple[dict[str, Any], bool]:
    """Return (task, should_apply). Duplicate keys must not apply again."""
    key = str(idempotency_key or "").strip()
    if not key:
        raise ValueError("mutation_idempotency_key_required")
    out = copy.deepcopy(task)
    out["mutation_attempts"] = int(out.get("mutation_attempts") or 0) + 1
    if mutation_already_applied(out, key):
        return out, False
    if str(out.get("state") or "") not in ("AWAITING_APPROVAL", "EXECUTING_APPROVED_ACTION", "PROPOSAL_READY"):
        # Allow transition into executing when approval just landed.
        if str(out.get("state") or "") == "PROPOSAL_READY":
            out = transition(out, "AWAITING_APPROVAL", reason="mutation_begin")
        if str(out.get("state") or "") == "AWAITING_APPROVAL":
            out = transition(out, "EXECUTING_APPROVED_ACTION", reason="mutation_begin")
    elif str(out.get("state") or "") == "AWAITING_APPROVAL":
        out = transition(out, "EXECUTING_APPROVED_ACTION", reason="mutation_begin")
    return out, True


def complete_mutation(task: dict[str, Any], idempotency_key: str) -> dict[str, Any]:
    key = str(idempotency_key or "").strip()
    if not key:
        raise ValueError("mutation_idempotency_key_required")
    out = copy.deepcopy(task)
    keys = [str(x) for x in (out.get("completed_mutation_keys") or [])]
    if key not in keys:
        keys.append(key)
    out["completed_mutation_keys"] = keys[-64:]
    state = str(out.get("state") or "")
    if state == "EXECUTING_APPROVED_ACTION":
        out = transition(out, "VERIFYING", reason="mutation_applied")
    return out


def record_duplicate_completion(task: dict[str, Any], idempotency_key: str) -> dict[str, Any]:
    """Idempotent resume after duplicate callback / worker restart."""
    out = copy.deepcopy(task)
    if mutation_already_applied(out, idempotency_key):
        out["updated_at"] = _now()
        return out
    # Treat as already-done if terminal completed — still do not apply.
    if str(out.get("state") or "") in ("COMPLETED", "PARTIALLY_COMPLETED", "VERIFYING"):
        return complete_mutation(out, idempotency_key) if not mutation_already_applied(out, idempotency_key) else out
    return complete_mutation(out, idempotency_key)


def resume_after_lease_recovery(task: dict[str, Any], *, job_company_id: int) -> dict[str, Any]:
    """Rebuild safe resume point after lease expiry / worker restart.

    Read loops may continue from recorded executed_action_ids.
    Mutations already in completed_mutation_keys must never re-apply.
    """
    out = copy.deepcopy(task) if isinstance(task, dict) else new_task(company_id=job_company_id)
    if require_company_revalidation(out, job_company_id):
        return revalidate_for_company_change(out, job_company_id)
    state = str(out.get("state") or "CREATED")
    if state in ("COMPLETED", "CANCELLED", "FAILED", "EXPIRED"):
        return out
    if state == "EXECUTING_APPROVED_ACTION":
        # Mid-mutation at crash: do not re-enter apply; verify / await duplicate callback.
        out = transition(out, "VERIFYING", reason="lease_recovery_mid_mutation")
        return out
    if state in ("CREATED",):
        return transition(out, "VALIDATING", reason="lease_recovery")
    return out


def mark_read_action(task: dict[str, Any], action_id: str, tool: str) -> dict[str, Any]:
    out = copy.deepcopy(task)
    aids = [str(x) for x in (out.get("executed_action_ids") or [])]
    aid = str(action_id)
    if aid and aid not in aids:
        aids.append(aid)
    out["executed_action_ids"] = aids[-64:]
    tools = [str(x) for x in (out.get("tool_sequence") or [])]
    if tool:
        tools.append(str(tool))
    out["tool_sequence"] = tools[-32:]
    state = str(out.get("state") or "")
    if state in ("PLANNING", "VALIDATING"):
        out = transition(out, "EXECUTING_READS", reason="read_action")
    out["updated_at"] = _now()
    return out


def action_already_executed(task: dict[str, Any], action_id: str) -> bool:
    return str(action_id) in {str(x) for x in (task.get("executed_action_ids") or [])}


def snapshot_for_persist(task: dict[str, Any]) -> dict[str, Any]:
    out = copy.deepcopy(task)
    # sets are not used; ensure JSON-safe
    out["executed_action_ids"] = list(out.get("executed_action_ids") or [])
    out["tool_sequence"] = list(out.get("tool_sequence") or [])
    out["completed_mutation_keys"] = list(out.get("completed_mutation_keys") or [])
    out["schema"] = SCHEMA
    out["updated_at"] = _now()
    return out
