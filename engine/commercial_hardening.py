#!/usr/bin/env python3
"""ERPSMART v9.3.0 commercial-MVP runtime guard.

This is the final cross-cutting guard around the existing accounting routes. It
does not add a financial feature and it never creates a mutation. It provides:

* end-to-end latency budgets and release metadata;
* actual tool-attempt observability (without arguments or results);
* blocked/fallback model-attempt observability;
* recursive secret redaction for persisted job metadata/trace;
* fail-closed invariants for read-only and Proposal-only routes;
* HTTPS validation for the remote control-plane URL.

The domain modules remain responsible for financial calculations and routing.
"""
from __future__ import annotations

import hashlib
import re
import time
from typing import Any
from urllib.parse import urlsplit


PATCH_VERSION = "v9.3.0"
RELEASE_CONTRACT = "commercial-mvp-v1"

PROPOSAL_TOOLS = frozenset({"create_sales_invoice_draft", "create_purchase_invoice_draft", "create_warehouse_receipt", "create_trade_case", "create_trade_shipment", "add_trade_cost", "create_check", "create_voucher_draft"})
READ_ONLY_MODES = frozenset({
    "deterministic_financial_report",
    "deep_financial_analysis",
    "deep_financial_analysis_fallback",
    "fast_read_analysis",
    "grounded_multi_read",
    "accounting_workflow_read",
    "accounting_workflow_partial",
    "accounting_workflow_blocked",
    "financial_intelligence",
    "financial_intelligence_blocked",
    "forecast_risk_anomaly",
    "forecast_risk_blocked",
    "proactive_accounting",
    "proactive_accounting_no_action",
    "proactive_accounting_blocked",
    "adaptive_cache_read",
    "adaptive_llm_read",
    "treasury_check_read",
    "inventory_warehouses_read",
    "inventory_position_read",
    "inventory_replenishment_read",
    "procurement_pipeline_read",
    "trade_case_read",
    "trade_landed_cost_read",
    "trade_risk_read",
})
PROPOSAL_MODES = frozenset({
    "guarded_sales_invoice_proposal",
    "guarded_purchase_invoice_proposal",
    "guarded_check_proposal",
    "guarded_inventory_receipt_proposal",
    "guarded_trade_case_proposal",
    "guarded_trade_shipment_proposal",
    "guarded_trade_cost_proposal",
    "accounting_action_proposal",
})

DEFAULT_LATENCY_BUDGETS_SECONDS: dict[str, float] = {
    "deterministic": 5.0,
    "read_model": 45.0,
    "action": 45.0,
    "deep": 240.0,
    "fallback": 90.0,
}

_SECRET_KEY = re.compile(
    r"(?:^|_)(?:token|password|passwd|secret|authorization|cookie|api_key|private_key|credential)(?:$|_)",
    re.IGNORECASE,
)
_WORKER_TOKEN = re.compile(r"aiw_[A-Fa-f0-9]{24,}")
_BEARER = re.compile(r"(?i)\bBearer\s+[A-Za-z0-9._~+/=-]{12,}")


class CommercialContractError(RuntimeError):
    """Raised when a route violates the commercial release contract."""


def validate_runtime_config(cfg: dict[str, Any]) -> None:
    """Fail closed on an insecure remote control-plane or placeholder token."""
    raw_url = str(cfg.get("server_url") or "").strip()
    parsed = urlsplit(raw_url)
    host = (parsed.hostname or "").lower()
    local_hosts = {"localhost", "127.0.0.1", "::1"}
    if parsed.scheme not in {"http", "https"} or not host:
        raise CommercialContractError("server_url_invalid")
    if parsed.scheme != "https" and host not in local_hosts:
        raise CommercialContractError("remote_control_plane_requires_https")

    token = str(cfg.get("worker_token") or "").strip()
    if token == "PASTE_TOKEN_FROM_WEB_PANEL" or not re.fullmatch(r"aiw_[A-Fa-f0-9]{48}", token):
        raise CommercialContractError("worker_token_format_invalid")


def _redact(value: Any, key_hint: str = "") -> tuple[Any, int]:
    """Return a recursively redacted copy plus the number of redactions."""
    if key_hint and _SECRET_KEY.search(key_hint):
        return "[REDACTED]", 1

    if isinstance(value, dict):
        out: dict[str, Any] = {}
        count = 0
        for raw_key, raw_value in value.items():
            key = str(raw_key)
            safe, found = _redact(raw_value, key)
            out[key] = safe
            count += found
        return out, count

    if isinstance(value, list):
        out_list = []
        count = 0
        for item in value:
            safe, found = _redact(item)
            out_list.append(safe)
            count += found
        return out_list, count

    if isinstance(value, tuple):
        safe, count = _redact(list(value))
        return safe, count

    if isinstance(value, str):
        count = 0
        safe, n = _WORKER_TOKEN.subn("[REDACTED_WORKER_TOKEN]", value)
        count += n
        safe, n = _BEARER.subn("Bearer [REDACTED]", safe)
        count += n
        return safe, count

    return value, 0


def redact_metadata(meta: dict[str, Any]) -> tuple[dict[str, Any], int]:
    safe, count = _redact(meta)
    if not isinstance(safe, dict):
        raise CommercialContractError("metadata_root_must_be_object")
    return safe, count


def _observed_model(trace: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
    for event in reversed(trace):
        if not isinstance(event, dict):
            continue
        details = event.get("details")
        if not isinstance(details, dict):
            continue
        model = str(details.get("model") or "").strip()
        if model and model != "none" and str(event.get("stage") or "") == "llm_done":
            metrics = {
                key: details.get(key)
                for key in (
                    "elapsed_seconds", "first_chunk_seconds", "prompt_eval_count",
                    "prompt_eval_duration", "eval_count", "eval_duration",
                )
                if details.get(key) is not None
            }
            return model, metrics
    for event in reversed(trace):
        details = event.get("details") if isinstance(event, dict) else None
        model = str(details.get("model") or "").strip() if isinstance(details, dict) else ""
        if model and model != "none":
            return model, {}
    return "none", {}


def _budget_class(mode: str) -> str:
    if mode in {"deep_financial_analysis", "deep_financial_analysis_fallback"}:
        return "deep"
    if mode in PROPOSAL_MODES or mode.startswith("accounting_action_") or mode.startswith("guarded_sales_invoice_"):
        return "action"
    if mode in {
        "financial_intelligence", "forecast_risk_anomaly", "proactive_accounting",
        "accounting_workflow_read", "accounting_workflow_partial", "adaptive_llm_read",
    }:
        return "read_model"
    if mode.endswith("_blocked") or mode.endswith("_rejected"):
        return "fallback"
    return "deterministic"


def _latency_budget(cfg: dict[str, Any], mode: str) -> tuple[str, float]:
    budget_class = _budget_class(mode)
    budgets = dict(DEFAULT_LATENCY_BUDGETS_SECONDS)
    configured = cfg.get("latency_budgets_seconds")
    if isinstance(configured, dict):
        for key in budgets:
            value = configured.get(key)
            if isinstance(value, (int, float)) and 0 < float(value) <= 3600:
                budgets[key] = float(value)
    return budget_class, budgets[budget_class]


def _policy(mode: str, successful_tools: list[str], proposal_created: bool) -> dict[str, Any]:
    seen = set(successful_tools)
    if "create_voucher_draft" in seen or "create_check" in seen or "create_warehouse_receipt" in seen or "add_trade_cost" in seen or mode.startswith("accounting_action_") or mode.startswith("guarded_check_") or mode.startswith("guarded_inventory_receipt_") or mode.startswith("guarded_trade_cost_"):
        risk = "high"
    elif "create_sales_invoice_draft" in seen or "create_purchase_invoice_draft" in seen or "create_trade_case" in seen or "create_trade_shipment" in seen or mode.startswith("guarded_sales_invoice_") or mode.startswith("guarded_purchase_invoice_") or mode.startswith("guarded_trade_case_") or mode.startswith("guarded_trade_shipment_"):
        risk = "medium"
    else:
        risk = "low"

    if mode in PROPOSAL_MODES:
        operation = "proposal"
        boundary = "proposal_only"
    elif mode.endswith("_blocked") or mode.endswith("_rejected") or mode.endswith("_noop"):
        operation = "blocked_or_noop"
        boundary = "blocked_before_mutation"
    else:
        operation = "read_or_analysis"
        boundary = "read_only"

    return {
        "risk_class": risk,
        "operation_class": operation,
        "mutation_boundary": boundary,
        "proposal_created": bool(proposal_created),
        "human_approval_required": bool(proposal_created),
        "automatic_financial_execution": False,
    }


def _is_read_only_mode(mode: str) -> bool:
    return mode in READ_ONLY_MODES or mode.startswith((
        "grounded_", "adaptive_", "accounting_workflow_", "financial_intelligence",
        "forecast_risk_", "proactive_accounting", "deterministic_", "deep_", "fast_read_", "treasury_", "inventory_", "procurement_", "trade_",
    ))


def _validate_result_contract(mode: str, meta: dict[str, Any], successful_tools: list[str]) -> None:
    proposal_tools = sorted(set(successful_tools).intersection(PROPOSAL_TOOLS))
    proposal_id = int(meta.get("proposal_id") or 0)

    if (_is_read_only_mode(mode) or mode == "tool_agent") and (proposal_tools or proposal_id > 0):
        raise CommercialContractError(f"read_only_route_created_proposal:{mode}")

    if mode in PROPOSAL_MODES:
        awaiting = (
            str(meta.get("proposal_status") or "") == "awaiting_human_approval"
            or meta.get("awaiting_human_approval") is True
        )
        if proposal_id <= 0 or not awaiting or not proposal_tools:
            raise CommercialContractError(f"proposal_contract_invalid:{mode}")

    if mode.endswith(("_blocked", "_rejected", "_noop")):
        if proposal_tools or proposal_id > 0:
            raise CommercialContractError(f"blocked_action_created_proposal:{mode}")


def install_commercial_hardening(worker_cls: type) -> None:
    """Install the last runtime wrapper once."""
    if getattr(worker_cls, "_commercial_hardening_v1_installed", False):
        return

    original_tool = worker_cls.tool
    original_process_agent = worker_cls.process_agent

    def hardened_tool(self: Any, job: dict[str, Any], name: str, arguments: Any, call_id: str) -> Any:
        started = time.monotonic()
        event = {
            "name": str(name)[:80],
            "call_id_fingerprint": hashlib.sha256(str(call_id).encode("utf-8")).hexdigest()[:12],
            "job_id": int(job.get("id") or 0),
        }
        try:
            result = original_tool(self, job, name, arguments, call_id)
            event["status"] = "succeeded"
            return result
        except Exception as exc:
            event["status"] = "failed"
            event["error_type"] = type(exc).__name__
            raise
        finally:
            event["elapsed_seconds"] = round(time.monotonic() - started, 3)
            ledger = getattr(self, "_commercial_tool_events", None)
            if isinstance(ledger, list):
                ledger.append(event)

    def hardened_process_agent(self: Any, job: dict[str, Any], tools_desc: list[dict[str, Any]]):
        started = time.monotonic()
        self._commercial_tool_events = []
        try:
            text, raw_meta = original_process_agent(self, job, tools_desc)
            if not isinstance(text, str) or not isinstance(raw_meta, dict):
                raise CommercialContractError("agent_result_shape_invalid")

            elapsed = round(time.monotonic() - started, 3)
            tool_events = list(getattr(self, "_commercial_tool_events", []) or [])
            successful = list(dict.fromkeys(
                str(event.get("name")) for event in tool_events
                if event.get("status") == "succeeded" and event.get("name")
            ))
            attempted = list(dict.fromkeys(
                str(event.get("name")) for event in tool_events if event.get("name")
            ))

            meta = dict(raw_meta)
            declared = [str(x) for x in (meta.get("tools_used") or []) if str(x)]
            meta["tools_used"] = list(dict.fromkeys(declared + successful))
            meta["tools_attempted"] = attempted
            mode = str(meta.get("mode") or "unknown")

            trace = list(getattr(self, "current_trace", []) or [])
            observed_model, observed_metrics = _observed_model(trace)
            if observed_model != "none":
                meta["model_attempted"] = observed_model
                if str(meta.get("model") or "none") == "none" and (
                    mode.endswith("_blocked") or mode.endswith("_rejected") or mode.endswith("_fallback")
                ):
                    meta["model"] = observed_model
                    meta["model_usage"] = "attempted_before_guard_or_fallback"
                if observed_metrics and not meta.get("attempted_metrics"):
                    meta["attempted_metrics"] = observed_metrics

            _validate_result_contract(mode, meta, successful)
            budget_class, budget = _latency_budget(getattr(self, "cfg", {}) or {}, mode)
            policy = _policy(mode, successful, int(meta.get("proposal_id") or 0) > 0)
            latency_status = "within_budget" if elapsed <= budget else "exceeded"

            self.trace(job, "commercial_hardening_complete", "Commercial MVP runtime contract verified", {
                "release_contract": RELEASE_CONTRACT,
                "mode": mode,
                "risk_class": policy["risk_class"],
                "latency_status": latency_status,
                "elapsed_seconds": elapsed,
                "budget_seconds": budget,
                "tools_attempted": attempted,
            })
            meta["trace"] = list(getattr(self, "current_trace", []) or [])[-60:]
            safe_meta, redaction_count = redact_metadata(meta)
            safe_meta["commercial_hardening"] = {
                "version": PATCH_VERSION,
                "release_contract": RELEASE_CONTRACT,
                **policy,
                "latency_budget_class": budget_class,
                "latency_budget_seconds": budget,
                "end_to_end_seconds": elapsed,
                "latency_status": latency_status,
                "tool_calls_succeeded": len(successful),
                "tool_calls_failed": sum(1 for x in tool_events if x.get("status") == "failed"),
                "metadata_redactions": redaction_count,
            }
            return text, safe_meta
        except Exception as exc:
            reason, _ = _redact(f"{type(exc).__name__}: {exc}")
            self.trace(job, "commercial_hardening_failed", "Commercial MVP runtime contract failed closed", {
                "reason": str(reason)[:300],
                "release_contract": RELEASE_CONTRACT,
            })
            raise

    worker_cls.tool = hardened_tool
    worker_cls.process_agent = hardened_process_agent
    worker_cls._commercial_hardening_v1_installed = True
    worker_cls._commercial_hardening_original_tool = original_tool
    worker_cls._commercial_hardening_original_process_agent = original_process_agent
