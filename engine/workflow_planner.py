#!/usr/bin/env python3
"""ERPSMART v8.8 Accounting Constrained Workflow Planner.

Purpose:
- Plan complex READ-ONLY accounting requests into a small validated workflow.
- Execute every financial fact through existing server-approved tools.
- Allow later steps to consume IDs that came from earlier tool results.
- Never allow model-created DB IDs, SQL, arbitrary identifiers, or hidden writes.

This layer is intentionally narrow. It does not replace Safe Deep, write guards,
the v8.6 grounded reader, or the v8.7 adaptive single-plan cache.
"""
from __future__ import annotations

import json
import re
import time
from typing import Any

import read_guard as rg

PATCH_VERSION = "v8.8.0.4"
PLANNER_VERSION = "accounting-workflow-read-v1"
MAX_STEPS = 8

ALLOWED_OPS = {"document_analytics", "compare", "party_ledger"}
ALLOWED_PERIODS = {
    "all",
    "current_jalali_month",
    "previous_jalali_month",
    "current_jalali_year",
    "previous_jalali_year",
    "rolling_jalali_months",
    "custom",
    "custom_jalali_month",
}
ALLOWED_SCOPES = {"all", "confirmed", "draft", "approved", "final"}
ALLOWED_GROUPS = {"none", "party", "item", "jalali_month", "status"}

DEEP_TERMS = (
    "تحلیل عمیق", "ریسک", "سناریو", "پیش بینی", "پیش‌بینی", "forecast", "deep analysis"
)

ACCOUNTING_TERMS = (
    "فروش", "خرید", "مشتری", "طرف حساب", "طرف‌حساب", "کالا", "محصول", "آیتم",
    "تراز", "مانده", "گردش", "فاکتور", "مالی", "صورتحساب", "سند"
)

DEPENDENCY_TERMS = (
    "همان مشتری", "همون مشتری", "همان طرف حساب", "همان طرف‌حساب", "همون طرف حساب",
    "همان کالا", "همون کالا", "همان محصول", "همون محصول",
    "مانده همان", "مانده همون", "گردش همان", "گردش همون",
    "بعد مانده", "سپس مانده", "و مانده", "بعدش مانده",
)

COMPARE_TERMS = ("مقایسه", "نسبت به", "در برابر")
RANK_PARTY_TERMS = (
    "مشتری برتر", "برترین مشتری", "بیشترین مشتری", "پرفروش ترین مشتری", "پرفروش‌ترین مشتری"
)
RANK_ITEM_TERMS = (
    "کالای برتر", "برترین کالا", "بیشترین کالا", "پرفروش ترین کالا", "پرفروش‌ترین کالا",
    "محصول برتر", "برترین محصول"
)
LEDGER_TERMS = ("مانده حساب", "مانده مشتری", "گردش حساب", "گردش مشتری", "دفتر مشتری", "مانده همان", "گردش همان")


class WorkflowPlanError(ValueError):
    pass


class WorkflowBlocked(RuntimeError):
    """A safe, user-displayable block caused by unresolved real ERP data."""


def n(prompt: str) -> str:
    return rg.norm(prompt)


def has_any(text: str, terms: tuple[str, ...]) -> bool:
    return any(x in text for x in terms)


def is_write(prompt: str) -> bool:
    text = n(prompt)
    return any(x in text for x in rg.WRITE)


def is_deep(prompt: str) -> bool:
    text = n(prompt)
    return has_any(text, DEEP_TERMS)


def existing_fast_path_is_sufficient(prompt: str) -> bool:
    """Do not steal already-safe deterministic reads.

    A prompt with cross-step pronouns such as "همان مشتری" is intentionally not
    considered resolved even if an old single-step route can superficially match it.
    """
    text = n(prompt)
    if has_any(text, DEPENDENCY_TERMS):
        return False

    parts = rg.split_multi(prompt)
    if len(parts) > 1:
        contextual = rg.contextualize_parts(parts)
        if not contextual:
            return False
        for part in contextual:
            plan = rg.route(part)
            if plan is None:
                return False
            if plan.get("intent") == "party_ledger" and not str(plan.get("query") or "").strip():
                return False
        return True

    # A single sentence can still contain several goals. The old router may
    # match only the first one (for example compare) and silently ignore ranking
    # or ledger follow-up. Such prompts belong to the workflow planner.
    if intent_score(prompt) >= 3:
        return False

    return rg.route(prompt) is not None


def intent_score(prompt: str) -> int:
    text = n(prompt)
    score = 0
    if has_any(text, COMPARE_TERMS):
        score += 1
    if has_any(text, RANK_PARTY_TERMS) or has_any(text, RANK_ITEM_TERMS):
        score += 1
    if has_any(text, LEDGER_TERMS):
        score += 1
    if "فروش" in text or "خرید" in text:
        score += 1
    if "همان" in text or "همون" in text:
        score += 1
    return score


def is_workflow_candidate(prompt: str) -> bool:
    text = n(prompt)
    if not text or is_write(prompt) or is_deep(prompt):
        return False
    if not has_any(text, ACCOUNTING_TERMS):
        return False
    if existing_fast_path_is_sufficient(prompt):
        return False
    if has_any(text, DEPENDENCY_TERMS):
        return True
    # Complex read requests need at least three signals to avoid hijacking a
    # normal single read that v8.7 can already handle.
    return intent_score(prompt) >= 3


def parse_json_object(text: Any) -> dict[str, Any]:
    s = str(text or "").strip()
    a = s.find("{")
    b = s.rfind("}")
    if a < 0 or b < a:
        raise WorkflowPlanError("workflow_json_missing")
    try:
        out = json.loads(s[a:b+1])
    except json.JSONDecodeError as e:
        raise WorkflowPlanError("workflow_json_invalid") from e
    if not isinstance(out, dict):
        raise WorkflowPlanError("workflow_json_root_not_object")
    return out


def _explicit_periods(prompt: str) -> set[str]:
    text = n(prompt)
    out: set[str] = set()

    if "این ماه" in text or "ماه جاری" in text:
        out.add("current_jalali_month")
    if "ماه قبل" in text or "ماه گذشته" in text:
        out.add("previous_jalali_month")
    if "این سال" in text or "سال جاری" in text:
        out.add("current_jalali_year")
    if "سال قبل" in text or "سال گذشته" in text:
        out.add("previous_jalali_year")

    # rolling N months
    if re.search(r"(?:\d+|یک|دو|سه|چهار|پنج|شش|هفت|هشت|نه|ده|یازده|دوازده)\s*ماه", text):
        # If the phrase is specifically "ماه قبل/گذشته" above, do not add rolling.
        if not ("ماه قبل" in text or text.strip() == "ماه گذشته"):
            out.add("rolling_jalali_months")

    # explicit dates / named month are validated by the existing parser.
    det = rg.period_of(prompt)
    if str(det.get("period") or "all") not in {"all"}:
        out.add(str(det["period"]))

    if not out:
        out.add("all")
    return out


def _allowed_kinds(prompt: str) -> set[str]:
    text = n(prompt)
    out = set()
    if "فروش" in text:
        out.add("sales")
    if "خرید" in text:
        out.add("purchases")
    return out or {"sales", "purchases"}


def _allowed_groups(prompt: str) -> set[str]:
    text = n(prompt)
    out = {"none"}
    if rg.group_of(prompt) == "party" or has_any(text, RANK_PARTY_TERMS):
        out.add("party")
    if rg.group_of(prompt) == "item" or has_any(text, RANK_ITEM_TERMS):
        out.add("item")
    if rg.group_of(prompt) == "jalali_month":
        out.add("jalali_month")
    if rg.group_of(prompt) == "status":
        out.add("status")
    return out


def _expected_scope(prompt: str) -> str:
    return rg.semantic_scope_of(prompt)


def _explicit_limit(prompt: str) -> int:
    return rg.limit_of(prompt, 0)


def _grounded(value: str, prompt: str) -> bool:
    return bool(value) and n(value) in n(prompt)


def _sanitize_analytics_args(args: Any, prompt: str, previous: dict[str, dict[str, Any]]) -> dict[str, Any]:
    if not isinstance(args, dict):
        raise WorkflowPlanError("workflow_analytics_args_not_object")

    allowed = {
        "kind", "period", "months", "date_from", "date_to", "jalali_year", "jalali_month",
        "status_scope", "group_by", "limit", "party_query", "item_query",
        "party_from", "item_from",
    }
    unknown = set(args) - allowed
    if unknown:
        raise WorkflowPlanError("workflow_analytics_unknown_args:" + ",".join(sorted(unknown)))
    if any(str(k).endswith("_id") for k in args):
        raise WorkflowPlanError("workflow_analytics_ids_forbidden")

    kind = str(args.get("kind") or "").strip()
    if kind not in {"sales", "purchases"}:
        raise WorkflowPlanError("workflow_analytics_kind_invalid")
    if kind not in _allowed_kinds(prompt):
        raise WorkflowPlanError("workflow_analytics_kind_not_grounded")

    period = str(args.get("period") or "all").strip()
    if period not in ALLOWED_PERIODS:
        raise WorkflowPlanError("workflow_analytics_period_invalid")
    if period not in _explicit_periods(prompt):
        raise WorkflowPlanError("workflow_analytics_period_not_grounded")

    scope = str(args.get("status_scope") or "all").strip()
    if scope not in ALLOWED_SCOPES:
        raise WorkflowPlanError("workflow_analytics_scope_invalid")
    expected_scope = _expected_scope(prompt)
    group = str(args.get("group_by") or "none").strip()
    if group not in ALLOWED_GROUPS:
        raise WorkflowPlanError("workflow_analytics_group_invalid")
    if group not in _allowed_groups(prompt):
        raise WorkflowPlanError("workflow_analytics_group_not_grounded")

    # When the prompt explicitly declares semantic scope, every ordinary analytics
    # step must preserve it. A status-breakdown step is allowed to inspect all states.
    if group == "status":
        if scope != "all":
            raise WorkflowPlanError("workflow_status_group_must_use_all")
    elif expected_scope != "all" and scope != expected_scope:
        raise WorkflowPlanError("workflow_analytics_scope_conflict")
    elif expected_scope == "all" and scope != "all":
        raise WorkflowPlanError("workflow_analytics_scope_not_grounded")

    out: dict[str, Any] = {
        "kind": kind,
        "period": period,
        "status_scope": scope,
        "group_by": group,
    }

    explicit_limit = _explicit_limit(prompt)
    raw_limit = int(args.get("limit") or (1 if group in {"party", "item"} and (has_any(n(prompt), RANK_PARTY_TERMS) or has_any(n(prompt), RANK_ITEM_TERMS)) else 5))
    raw_limit = max(1, min(50, raw_limit))
    if group in {"party", "item"} and explicit_limit > 0:
        # The count is a deterministic user constraint; normalize harmless LLM drift.
        raw_limit = explicit_limit
    if group in {"party", "item"} and explicit_limit == 0 and (has_any(n(prompt), RANK_PARTY_TERMS) or has_any(n(prompt), RANK_ITEM_TERMS)):
        # "مشتری برتر/پرفروش‌ترین کالا" without a number means top 1.
        raw_limit = 1
    out["limit"] = raw_limit

    if period == "rolling_jalali_months":
        det_months = rg.month_count_of(prompt, 0)
        months = int(args.get("months") or det_months or 0)
        if not det_months or months != det_months:
            raise WorkflowPlanError("workflow_analytics_month_count_conflict")
        out["months"] = max(1, min(24, months))
    elif period == "custom":
        for key in ("date_from", "date_to"):
            value = str(args.get(key) or "").strip()
            if not _grounded(value, prompt):
                raise WorkflowPlanError("workflow_analytics_custom_date_not_grounded:" + key)
            out[key] = value
    elif period == "custom_jalali_month":
        det = rg.period_of(prompt)
        year = int(args.get("jalali_year") or 0)
        month = int(args.get("jalali_month") or 0)
        if year != int(det.get("jalali_year") or 0) or month != int(det.get("jalali_month") or 0):
            raise WorkflowPlanError("workflow_analytics_jalali_month_conflict")
        out["jalali_year"] = year
        out["jalali_month"] = month

    for key in ("party_query", "item_query"):
        value = str(args.get(key) or "").strip()
        if value:
            pronoun_kind = _pronoun_entity_kind(value)
            if pronoun_kind:
                expected_key = "party_from" if pronoun_kind == "party" else "item_from"
                expected_group = "party" if pronoun_kind == "party" else "item"
                ref = _latest_prior_group(previous, expected_group)
                if not ref:
                    raise WorkflowPlanError("workflow_pronoun_dependency_missing:" + key)
                out[expected_key] = ref
            else:
                if not _grounded(value, prompt):
                    raise WorkflowPlanError("workflow_entity_query_not_grounded:" + key)
                out[key] = value

    for key, expected_group in (("party_from", "party"), ("item_from", "item")):
        ref = str(args.get(key) or "").strip()
        if ref:
            if ref not in previous:
                raise WorkflowPlanError("workflow_dependency_not_prior:" + key)
            parent = previous[ref]
            if parent.get("op") != "document_analytics":
                raise WorkflowPlanError("workflow_dependency_not_analytics:" + key)
            parent_args = parent.get("args") if isinstance(parent.get("args"), dict) else {}
            if parent_args.get("group_by") != expected_group:
                raise WorkflowPlanError("workflow_dependency_wrong_group:" + key)
            out[key] = ref

    if out.get("party_query") and out.get("party_from"):
        raise WorkflowPlanError("workflow_party_source_conflict")
    if out.get("item_query") and out.get("item_from"):
        raise WorkflowPlanError("workflow_item_source_conflict")

    return out



def _canonicalize_step_ids(raw_steps: list[Any]) -> list[Any]:
    """Normalize harmless step-id variations while preserving dependency meaning.

    LLMs may emit step1/a/b instead of s1/s2. Step IDs are internal bookkeeping,
    not financial facts, so canonicalizing them is safe as long as every reference
    points to an existing unique step and order remains unchanged.
    """
    ids: list[str] = []
    for idx, raw in enumerate(raw_steps, 1):
        if not isinstance(raw, dict):
            raise WorkflowPlanError("workflow_step_not_object")
        rid = str(raw.get("id") or f"s{idx}").strip()
        if not rid or rid in ids:
            raise WorkflowPlanError("workflow_step_id_invalid_or_duplicate")
        ids.append(rid)

    mapping = {old: f"s{i+1}" for i, old in enumerate(ids)}
    out: list[Any] = []
    for raw in raw_steps:
        row = dict(raw)
        row["id"] = mapping[str(raw.get("id") or ids[len(out)])]
        for key in ("left", "right", "party_from", "item_from"):
            if key in row and str(row.get(key) or "").strip():
                ref = str(row[key]).strip()
                if ref not in mapping:
                    raise WorkflowPlanError("workflow_dependency_reference_unknown:" + key)
                row[key] = mapping[ref]
        args = row.get("args")
        if isinstance(args, dict):
            args = dict(args)
            for key in ("party_from", "item_from"):
                if key in args and str(args.get(key) or "").strip():
                    ref = str(args[key]).strip()
                    if ref not in mapping:
                        raise WorkflowPlanError("workflow_dependency_reference_unknown:" + key)
                    args[key] = mapping[ref]
            row["args"] = args
        out.append(row)
    return out


def _latest_prior_group(previous: dict[str, dict[str, Any]], group: str) -> str:
    for step_id in reversed(list(previous.keys())):
        step = previous[step_id]
        if step.get("op") != "document_analytics":
            continue
        args = step.get("args") if isinstance(step.get("args"), dict) else {}
        if args.get("group_by") == group:
            return step_id
    return ""


def _pronoun_entity_kind(value: str) -> str:
    text = n(value)
    if text in {"همان مشتری", "همون مشتری", "همان طرف حساب", "همان طرف‌حساب", "همون طرف حساب", "همون طرف‌حساب"}:
        return "party"
    if text in {"همان کالا", "همون کالا", "همان محصول", "همون محصول", "همان آیتم", "همون آیتم"}:
        return "item"
    return ""


def sanitize_workflow_plan(plan: Any, prompt: str) -> dict[str, Any]:
    if not isinstance(plan, dict):
        raise WorkflowPlanError("workflow_plan_not_object")

    unknown_top = set(plan) - {"version", "steps"}
    if unknown_top:
        raise WorkflowPlanError("workflow_plan_unknown_top_keys:" + ",".join(sorted(unknown_top)))

    version = str(plan.get("version") or PLANNER_VERSION).strip()
    if version != PLANNER_VERSION:
        raise WorkflowPlanError("workflow_plan_version_invalid")

    raw_steps = plan.get("steps")
    if not isinstance(raw_steps, list) or not raw_steps or len(raw_steps) > MAX_STEPS:
        raise WorkflowPlanError("workflow_steps_invalid")
    raw_steps = _canonicalize_step_ids(raw_steps)

    cleaned: list[dict[str, Any]] = []
    previous: dict[str, dict[str, Any]] = {}

    for index, raw in enumerate(raw_steps, 1):
        if not isinstance(raw, dict):
            raise WorkflowPlanError("workflow_step_not_object")

        unknown = set(raw) - {"id", "op", "args", "left", "right", "party_from", "party_query", "emit"}
        if unknown:
            raise WorkflowPlanError("workflow_step_unknown_keys:" + ",".join(sorted(unknown)))

        step_id = str(raw.get("id") or "").strip()
        expected_id = f"s{index}"
        if step_id != expected_id:
            raise WorkflowPlanError("workflow_step_id_must_be_sequential")

        op = str(raw.get("op") or "").strip()
        if op not in ALLOWED_OPS:
            raise WorkflowPlanError("workflow_step_op_invalid")

        emit = raw.get("emit", True)
        if not isinstance(emit, bool):
            raise WorkflowPlanError("workflow_emit_not_boolean")

        step: dict[str, Any] = {"id": step_id, "op": op, "emit": emit}

        if op == "document_analytics":
            step["args"] = _sanitize_analytics_args(raw.get("args") or {}, prompt, previous)

        elif op == "compare":
            left = str(raw.get("left") or "").strip()
            right = str(raw.get("right") or "").strip()
            if left not in previous or right not in previous or left == right:
                raise WorkflowPlanError("workflow_compare_dependency_invalid")
            lstep, rstep = previous[left], previous[right]
            if lstep.get("op") != "document_analytics" or rstep.get("op") != "document_analytics":
                raise WorkflowPlanError("workflow_compare_requires_analytics")
            la = lstep.get("args") or {}
            ra = rstep.get("args") or {}
            if la.get("kind") != ra.get("kind"):
                raise WorkflowPlanError("workflow_compare_kind_conflict")
            if la.get("status_scope") != ra.get("status_scope"):
                raise WorkflowPlanError("workflow_compare_scope_conflict")
            if la.get("party_from") != ra.get("party_from") or la.get("item_from") != ra.get("item_from"):
                raise WorkflowPlanError("workflow_compare_entity_ref_conflict")
            if la.get("party_query") != ra.get("party_query") or la.get("item_query") != ra.get("item_query"):
                raise WorkflowPlanError("workflow_compare_entity_query_conflict")
            if la.get("period") == ra.get("period"):
                raise WorkflowPlanError("workflow_compare_periods_same")
            if not has_any(n(prompt), COMPARE_TERMS):
                raise WorkflowPlanError("workflow_compare_not_grounded")
            step["left"] = left
            step["right"] = right

        elif op == "party_ledger":
            party_from = str(raw.get("party_from") or "").strip()
            party_query = str(raw.get("party_query") or "").strip()

            if party_query and _pronoun_entity_kind(party_query) == "party" and not party_from:
                party_from = _latest_prior_group(previous, "party")
                party_query = ""
                if not party_from:
                    raise WorkflowPlanError("workflow_ledger_pronoun_dependency_missing")

            if bool(party_from) == bool(party_query):
                raise WorkflowPlanError("workflow_ledger_requires_one_party_source")
            if party_from:
                if party_from not in previous:
                    raise WorkflowPlanError("workflow_ledger_dependency_not_prior")
                parent = previous[party_from]
                if parent.get("op") != "document_analytics":
                    raise WorkflowPlanError("workflow_ledger_dependency_not_analytics")
                pargs = parent.get("args") or {}
                if pargs.get("group_by") != "party":
                    raise WorkflowPlanError("workflow_ledger_dependency_not_party_group")
                step["party_from"] = party_from
            else:
                if not _grounded(party_query, prompt):
                    raise WorkflowPlanError("workflow_ledger_query_not_grounded")
                step["party_query"] = party_query

        previous[step_id] = step
        cleaned.append(step)

    # Must actually be a workflow, not a disguised single read.
    if len(cleaned) < 2:
        raise WorkflowPlanError("workflow_requires_multiple_steps")
    if not any(
        s["op"] in {"compare", "party_ledger"} or
        (s["op"] == "document_analytics" and ((s.get("args") or {}).get("party_from") or (s.get("args") or {}).get("item_from")))
        for s in cleaned
    ):
        raise WorkflowPlanError("workflow_has_no_dependency_or_derivation")

    return {"version": PLANNER_VERSION, "steps": cleaned}



def _period_near_terms(prompt: str, terms: tuple[str, ...]) -> str | None:
    """Resolve the period attached to a local goal phrase, not the whole prompt."""
    text = n(prompt)
    hits: list[tuple[int, str]] = []
    for term in terms:
        nt = n(term)
        pos = text.find(nt)
        if pos >= 0:
            hits.append((pos, nt))
    for pos, nt in sorted(hits):
        # Period qualifiers normally follow the ranked entity phrase in Persian.
        window = text[pos:min(len(text), pos + len(nt) + 90)]
        det = rg.period_of(window)
        period = str(det.get("period") or "all")
        if period != "all" and period in _explicit_periods(prompt):
            return period

    periods = _explicit_periods(prompt)
    if len(periods) == 1:
        return next(iter(periods))
    return None


def _ordered_compare_periods(prompt: str) -> list[str]:
    periods = _explicit_periods(prompt)
    preferred = [
        "current_jalali_month",
        "previous_jalali_month",
        "current_jalali_year",
        "previous_jalali_year",
        "rolling_jalali_months",
        "custom_jalali_month",
        "custom",
        "all",
    ]
    ordered = [x for x in preferred if x in periods]
    return ordered


def _period_runtime_args(prompt: str, period: str) -> dict[str, Any]:
    """Only server-derived temporal parameters; the LLM never creates them."""
    out: dict[str, Any] = {"period": period}
    det = rg.period_of(prompt)
    if period == "rolling_jalali_months":
        months = rg.month_count_of(prompt, 0)
        if months:
            out["months"] = months
    elif period == "custom":
        if str(det.get("period") or "") == "custom":
            for key in ("date_from", "date_to"):
                if det.get(key):
                    out[key] = det[key]
    elif period == "custom_jalali_month":
        if str(det.get("period") or "") == "custom_jalali_month":
            for key in ("jalali_year", "jalali_month"):
                if det.get(key) is not None:
                    out[key] = det[key]
    return out


def _candidate_id(prefix: str, kind: str, *parts: str) -> str:
    clean = [re.sub(r"[^a-z0-9_]+", "_", str(x).lower()).strip("_") for x in parts]
    return "_".join([prefix, kind] + [x for x in clean if x])


def build_goal_candidates(prompt: str) -> dict[str, Any]:
    """Build a bounded, server-grounded set of high-level accounting goals.

    The model may select only these IDs. It never emits tool names, periods,
    dates, DB IDs, financial values, or tool arguments.
    """
    text = n(prompt)
    kinds = _allowed_kinds(prompt)
    if len(kinds) != 1:
        raise WorkflowPlanError("workflow_candidate_kind_ambiguous")
    kind = next(iter(kinds))
    scope = _expected_scope(prompt)
    periods = _explicit_periods(prompt)
    explicit_limit = _explicit_limit(prompt) or 1

    candidates: list[dict[str, Any]] = []
    required_goal_types: list[str] = []

    def add(candidate: dict[str, Any]) -> None:
        if not any(x["id"] == candidate["id"] for x in candidates):
            candidates.append(candidate)

    # Comparison is represented as one high-level goal. The server expands it
    # into the two grounded analytics prerequisites plus compare.
    if has_any(text, COMPARE_TERMS):
        ordered = _ordered_compare_periods(prompt)
        if len(ordered) != 2:
            raise WorkflowPlanError("workflow_compare_periods_ambiguous")
        left, right = ordered[0], ordered[1]
        cid = _candidate_id("compare", kind, left, right)
        add({
            "id": cid,
            "goal_type": "compare",
            "meaning": f"compare {kind} {left} against {right} with scope {scope}",
            "kind": kind,
            "scope": scope,
            "left_period": left,
            "right_period": right,
            "required": True,
        })
        required_goal_types.append("compare")

    rank_party_ids: list[str] = []
    if has_any(text, RANK_PARTY_TERMS):
        local = _period_near_terms(prompt, RANK_PARTY_TERMS)
        if local is None and len(periods) > 1:
            raise WorkflowPlanError("workflow_rank_party_period_ambiguous")
        party_periods = [local] if local else list(_ordered_compare_periods(prompt) or periods)
        party_periods = [p for p in party_periods if p]
        for period in party_periods:
            cid = _candidate_id("rank_party", kind, period)
            add({
                "id": cid,
                "goal_type": "rank_party",
                "meaning": f"top customer by {kind} in {period} with scope {scope}",
                "kind": kind,
                "scope": scope,
                "period": period,
                "limit": explicit_limit,
                "required": local == period or len(party_periods) == 1,
            })
            rank_party_ids.append(cid)
        required_goal_types.append("rank_party")

    same_party = any(
        x in text for x in (
            "همان مشتری", "همون مشتری", "همان طرف حساب", "همان طرف‌حساب",
            "همون طرف حساب", "همون طرف‌حساب"
        )
    )
    if has_any(text, LEDGER_TERMS) and same_party:
        if not rank_party_ids:
            raise WorkflowPlanError("workflow_ledger_same_party_without_rank_candidate")
        for rid in rank_party_ids:
            rank = next(x for x in candidates if x["id"] == rid)
            cid = _candidate_id("ledger_same_party", kind, str(rank["period"]))
            add({
                "id": cid,
                "goal_type": "ledger_same_party",
                "meaning": f"ledger/balance of the same customer returned by {rid}",
                "depends_on": rid,
                "kind": kind,
                "period": rank["period"],
                "required": bool(rank.get("required")),
            })
        required_goal_types.append("ledger_same_party")

    rank_item_ids: list[str] = []
    if has_any(text, RANK_ITEM_TERMS):
        local = _period_near_terms(prompt, RANK_ITEM_TERMS)
        if local is None and len(periods) > 1:
            raise WorkflowPlanError("workflow_rank_item_period_ambiguous")
        item_periods = [local] if local else list(_ordered_compare_periods(prompt) or periods)
        item_periods = [p for p in item_periods if p]
        for period in item_periods:
            cid = _candidate_id("rank_item", kind, period)
            add({
                "id": cid,
                "goal_type": "rank_item",
                "meaning": f"top item by {kind} in {period} with scope {scope}",
                "kind": kind,
                "scope": scope,
                "period": period,
                "limit": explicit_limit,
                "required": local == period or len(item_periods) == 1,
            })
            rank_item_ids.append(cid)
        required_goal_types.append("rank_item")

    # Cross-period "same item" analysis: source is the locally-ranked period;
    # target is another grounded period. The server owns the dependency and args.
    same_item = any(
        x in text for x in (
            "همان کالا", "همون کالا", "همان محصول", "همون محصول",
            "همان آیتم", "همون آیتم"
        )
    )
    if rank_item_ids and same_item and len(periods) >= 2:
        for rid in rank_item_ids:
            rank = next(x for x in candidates if x["id"] == rid)
            source = str(rank["period"])
            targets = [p for p in _ordered_compare_periods(prompt) if p != source]
            for target in targets[:1]:
                cid = _candidate_id("analyze_same_item", kind, source, target)
                add({
                    "id": cid,
                    "goal_type": "analyze_same_item",
                    "meaning": f"analyze the same item returned by {rid} in {target}",
                    "depends_on": rid,
                    "kind": kind,
                    "scope": scope,
                    "source_period": source,
                    "target_period": target,
                    "required": True,
                })
                required_goal_types.append("analyze_same_item")

    if not candidates or not required_goal_types:
        raise WorkflowPlanError("workflow_no_grounded_goal_candidates")

    return {
        "kind": kind,
        "scope": scope,
        "candidates": candidates,
        "required_goal_types": list(dict.fromkeys(required_goal_types)),
    }


def goal_selection_schema(contract: dict[str, Any]) -> dict[str, Any]:
    ids = [str(x["id"]) for x in contract["candidates"]]
    max_goals = max(1, len(contract["required_goal_types"]))
    return {
        "type": "object",
        "properties": {
            "goals": {
                "type": "array",
                "items": {"type": "string", "enum": ids},
                # The server already knows how many distinct user goal types
                # must be represented. The model may choose alternatives, but
                # it may not silently omit a requested goal.
                "minItems": max_goals,
                "maxItems": max_goals,
                "uniqueItems": True,
            }
        },
        "required": ["goals"],
        "additionalProperties": False,
    }


def goal_selector_prompt(contract: dict[str, Any]) -> str:
    public = [
        {
            "id": x["id"],
            "goal_type": x["goal_type"],
            "meaning": x["meaning"],
            **({"depends_on": x["depends_on"]} if x.get("depends_on") else {}),
        }
        for x in contract["candidates"]
    ]
    return (
        "Select the MINIMAL ordered list of grounded accounting goal IDs that directly satisfies the user. "
        "Use only candidate IDs. Do not invent tools, parameters, periods, dates, entities, IDs, values, or new goal names. "
        "Choose exactly one candidate for each requested goal type. "
        "Pay strict attention to current versus previous periods. "
        "Dependencies and tool arguments are expanded by the server after selection. "
        "Return only the JSON required by the supplied schema. "
        "CANDIDATES=" + json.dumps(public, ensure_ascii=False, separators=(",", ":"))
    )


def validate_goal_selection(selection: Any, contract: dict[str, Any]) -> list[str]:
    if not isinstance(selection, dict) or set(selection) != {"goals"}:
        raise WorkflowPlanError("workflow_goal_selection_shape_invalid")
    raw = selection.get("goals")
    if not isinstance(raw, list) or not raw:
        raise WorkflowPlanError("workflow_goal_selection_empty")

    goals = [str(x).strip() for x in raw]
    if len(goals) != len(set(goals)):
        raise WorkflowPlanError("workflow_goal_selection_duplicate")

    by_id = {str(x["id"]): x for x in contract["candidates"]}
    if any(x not in by_id for x in goals):
        raise WorkflowPlanError("workflow_goal_selection_unknown")

    required_types = list(contract["required_goal_types"])
    selected_by_type: dict[str, list[str]] = {}
    for gid in goals:
        gt = str(by_id[gid]["goal_type"])
        selected_by_type.setdefault(gt, []).append(gid)

    # One selected goal per deterministic user-requested goal type. This blocks
    # silent dropping of compare/rank/ledger constraints and rejects extras.
    if set(selected_by_type) != set(required_types):
        raise WorkflowPlanError("workflow_goal_selection_constraint_mismatch")
    for gt in required_types:
        if len(selected_by_type.get(gt, [])) != 1:
            raise WorkflowPlanError("workflow_goal_selection_ambiguous:" + gt)

    # If local grounding marked a specific alternative as required, selection
    # must use it. This is what prevents "ماه قبل" → current-month drift.
    for gt in required_types:
        required = [
            str(x["id"]) for x in contract["candidates"]
            if x["goal_type"] == gt and bool(x.get("required"))
        ]
        if len(required) == 1 and selected_by_type[gt][0] != required[0]:
            raise WorkflowPlanError("workflow_goal_selection_grounding_conflict:" + gt)

    # Dependency-bearing goals must point at the selected source alternative.
    selected = set(goals)
    for gid in goals:
        dep = by_id[gid].get("depends_on")
        if dep and dep not in selected:
            # Server may expand structural prerequisites, but when the dependency
            # is itself a user-requested goal (rank + same entity), it must be selected.
            dep_type = str(by_id[str(dep)]["goal_type"]) if str(dep) in by_id else ""
            if dep_type in required_types:
                raise WorkflowPlanError("workflow_goal_selection_dependency_missing")

    # Canonicalize to server candidate order. The LLM selects the safe goal
    # set; it does not control dependency ordering or final Tool execution order.
    selected_set = set(goals)
    return [str(x["id"]) for x in contract["candidates"] if str(x["id"]) in selected_set]


def compile_goal_selection(prompt: str, contract: dict[str, Any], goals: list[str]) -> dict[str, Any]:
    by_id = {str(x["id"]): x for x in contract["candidates"]}
    steps: list[dict[str, Any]] = []
    compiled_goals: set[str] = set()
    goal_step: dict[str, str] = {}

    def next_id() -> str:
        return f"s{len(steps) + 1}"

    def analytics_args(kind: str, period: str, scope: str, group: str, limit: int = 5) -> dict[str, Any]:
        args: dict[str, Any] = {
            "kind": kind,
            "status_scope": scope,
            "group_by": group,
            "limit": limit,
        }
        args.update(_period_runtime_args(prompt, period))
        return args

    def compile_goal(gid: str) -> None:
        if gid in compiled_goals:
            return
        c = by_id[gid]
        dep = c.get("depends_on")
        if dep:
            compile_goal(str(dep))

        gt = str(c["goal_type"])
        kind = str(c.get("kind") or contract["kind"])
        scope = str(c.get("scope") or contract["scope"])

        if gt == "compare":
            left_id = next_id()
            steps.append({
                "id": left_id,
                "op": "document_analytics",
                "args": analytics_args(kind, str(c["left_period"]), scope, "none", 5),
                "emit": False,
            })
            right_id = next_id()
            steps.append({
                "id": right_id,
                "op": "document_analytics",
                "args": analytics_args(kind, str(c["right_period"]), scope, "none", 5),
                "emit": False,
            })
            sid = next_id()
            steps.append({"id": sid, "op": "compare", "left": left_id, "right": right_id, "emit": True})
            goal_step[gid] = sid

        elif gt == "rank_party":
            sid = next_id()
            steps.append({
                "id": sid,
                "op": "document_analytics",
                "args": analytics_args(kind, str(c["period"]), scope, "party", int(c.get("limit") or 1)),
                "emit": True,
            })
            goal_step[gid] = sid

        elif gt == "ledger_same_party":
            source_goal = str(c["depends_on"])
            source_step = goal_step.get(source_goal)
            if not source_step:
                raise WorkflowPlanError("workflow_goal_compile_party_dependency_missing")
            sid = next_id()
            steps.append({"id": sid, "op": "party_ledger", "party_from": source_step, "emit": True})
            goal_step[gid] = sid

        elif gt == "rank_item":
            sid = next_id()
            steps.append({
                "id": sid,
                "op": "document_analytics",
                "args": analytics_args(kind, str(c["period"]), scope, "item", int(c.get("limit") or 1)),
                "emit": True,
            })
            goal_step[gid] = sid

        elif gt == "analyze_same_item":
            source_goal = str(c["depends_on"])
            source_step = goal_step.get(source_goal)
            if not source_step:
                raise WorkflowPlanError("workflow_goal_compile_item_dependency_missing")
            sid = next_id()
            args = analytics_args(kind, str(c["target_period"]), scope, "none", 5)
            args["item_from"] = source_step
            steps.append({"id": sid, "op": "document_analytics", "args": args, "emit": True})
            goal_step[gid] = sid

        else:
            raise WorkflowPlanError("workflow_goal_compile_unknown_type:" + gt)

        compiled_goals.add(gid)

    for gid in goals:
        compile_goal(gid)

    if len(steps) > MAX_STEPS:
        raise WorkflowPlanError("workflow_goal_compile_too_many_steps")

    return sanitize_workflow_plan({"version": PLANNER_VERSION, "steps": steps}, prompt)


def llm_plan(worker: Any, job: dict[str, Any], prompt: str) -> tuple[dict[str, Any], dict[str, Any], str]:
    contract = build_goal_candidates(prompt)
    model = worker.model_for("agent")
    candidate_ids = [str(x["id"]) for x in contract["candidates"]]
    worker.trace(
        job,
        "workflow_plan_llm",
        f"Selecting grounded accounting goals with {model}",
        {
            "model": model,
            "started_epoch": time.time(),
            "planner_version": PLANNER_VERSION,
            "candidate_count": len(candidate_ids),
            "required_goal_types": list(contract["required_goal_types"]),
        },
    )
    response = worker.ollama_chat(
        job,
        0,
        [{"role": "system", "content": goal_selector_prompt(contract)}, {"role": "user", "content": prompt}],
        [],
        fast=True,
        model=model,
        num_ctx=1024,
        num_predict=128,
        temperature=0.0,
        timeout_seconds=120,
        response_format=goal_selection_schema(contract),
        think_override=False,
    )
    metrics = dict(response.get("_metrics") or {})
    metrics["candidate_count"] = len(candidate_ids)
    raw = str((response.get("message") or {}).get("content") or "")
    try:
        parsed = parse_json_object(raw)
        goals = validate_goal_selection(parsed, contract)
        metrics["selected_goal_ids"] = list(goals)
        clean = compile_goal_selection(prompt, contract, goals)
    except WorkflowPlanError as e:
        e.planner_model = model
        e.planner_metrics = metrics
        raise
    return clean, metrics, model


def _canonical_fallback(prompt: str) -> dict[str, Any] | None:
    """Small deterministic recovery for the main proven dependency patterns.

    This is a resilience path, not the general planner.
    """
    text = n(prompt)
    kinds = _allowed_kinds(prompt)
    scope = _expected_scope(prompt)

    # Pattern A: current vs previous + top customer + ledger of same customer.
    if (
        len(kinds) == 1
        and "current_jalali_month" in _explicit_periods(prompt)
        and "previous_jalali_month" in _explicit_periods(prompt)
        and has_any(text, COMPARE_TERMS)
        and has_any(text, RANK_PARTY_TERMS)
        and has_any(text, LEDGER_TERMS)
        and ("همان" in text or "همون" in text)
    ):
        kind = next(iter(kinds))
        top_limit = _explicit_limit(prompt) or 1
        raw = {
            "version": PLANNER_VERSION,
            "steps": [
                {"id": "s1", "op": "document_analytics", "args": {
                    "kind": kind, "period": "current_jalali_month", "status_scope": scope,
                    "group_by": "none", "limit": 5,
                }, "emit": False},
                {"id": "s2", "op": "document_analytics", "args": {
                    "kind": kind, "period": "previous_jalali_month", "status_scope": scope,
                    "group_by": "none", "limit": 5,
                }, "emit": False},
                {"id": "s3", "op": "compare", "left": "s1", "right": "s2", "emit": True},
                {"id": "s4", "op": "document_analytics", "args": {
                    "kind": kind, "period": "current_jalali_month", "status_scope": scope,
                    "group_by": "party", "limit": top_limit,
                }, "emit": True},
                {"id": "s5", "op": "party_ledger", "party_from": "s4", "emit": True},
            ],
        }
        return sanitize_workflow_plan(raw, prompt)

    # Pattern A2: rank the top customer in one grounded period, then inspect
    # the same server-returned party in the ledger. This is the safe recovery
    # for requests such as "مشتری برتر فروش قطعی ماه قبل ... مانده همان مشتری".
    if (
        len(kinds) == 1
        and has_any(text, RANK_PARTY_TERMS)
        and has_any(text, LEDGER_TERMS)
        and ("همان" in text or "همون" in text)
    ):
        det_period = rg.period_of(prompt)
        period = str(det_period.get("period") or "all")
        if period in ALLOWED_PERIODS:
            kind = next(iter(kinds))
            top_limit = _explicit_limit(prompt) or 1
            args = {
                "kind": kind,
                "period": period,
                "status_scope": scope,
                "group_by": "party",
                "limit": top_limit,
            }
            for key in ("months", "date_from", "date_to", "jalali_year", "jalali_month"):
                if key in det_period:
                    args[key] = det_period[key]
            raw = {
                "version": PLANNER_VERSION,
                "steps": [
                    {"id": "s1", "op": "document_analytics", "args": args, "emit": True},
                    {"id": "s2", "op": "party_ledger", "party_from": "s1", "emit": True},
                ],
            }
            return sanitize_workflow_plan(raw, prompt)

    # Pattern B: top item in one period, analyze the same item in another grounded period.
    if (
        len(kinds) == 1
        and has_any(text, RANK_ITEM_TERMS)
        and ("همان کالا" in text or "همون کالا" in text or "همان محصول" in text or "همون محصول" in text)
        and "current_jalali_month" in _explicit_periods(prompt)
        and "rolling_jalali_months" in _explicit_periods(prompt)
    ):
        kind = next(iter(kinds))
        top_limit = _explicit_limit(prompt) or 1
        raw = {
            "version": PLANNER_VERSION,
            "steps": [
                {"id": "s1", "op": "document_analytics", "args": {
                    "kind": kind, "period": "current_jalali_month", "status_scope": scope,
                    "group_by": "item", "limit": top_limit,
                }, "emit": True},
                {"id": "s2", "op": "document_analytics", "args": {
                    "kind": kind, "period": "rolling_jalali_months",
                    "months": rg.month_count_of(prompt, 3),
                    "status_scope": scope, "group_by": "none", "limit": 5,
                    "item_from": "s1",
                }, "emit": True},
            ],
        }
        return sanitize_workflow_plan(raw, prompt)

    return None


def _rows(value: Any) -> list[dict[str, Any]]:
    return rg.rows(value)


def _unique_entity(value: Any, query: str) -> tuple[dict[str, Any] | None, str]:
    return rg.unique_entity(_rows(value), query)



def _group_rows(raw_result: Any) -> list[dict[str, Any]]:
    if not isinstance(raw_result, dict):
        return []
    groups = raw_result.get("groups")
    if not isinstance(groups, list):
        return []
    return [g for g in groups if isinstance(g, dict)]


def _dependency_no_data_text(kind: str) -> str:
    if kind == "party":
        return "برای دامنه و دوره خواسته‌شده رکوردی برای تعیین مشتری برتر وجود ندارد؛ بنابراین بررسی مانده «همان مشتری» قابل اجرا نیست."
    return "برای دامنه و دوره خواسته‌شده رکوردی برای تعیین کالای برتر وجود ندارد؛ بنابراین تحلیل «همان کالا» قابل اجرا نیست."


def _top_group(raw_result: Any, expected_id_key: str) -> dict[str, Any]:
    if not isinstance(raw_result, dict):
        raise WorkflowBlocked("خروجی مرحله مرجع معتبر نیست؛ Workflow متوقف شد.")
    groups = _group_rows(raw_result)
    if not groups:
        raise LookupError("workflow_dependency_no_data")
    row = groups[0]
    try:
        entity_id = int(row.get(expected_id_key) or 0)
    except Exception:
        entity_id = 0
    if entity_id <= 0:
        raise WorkflowBlocked("شناسه Entity از نتیجه واقعی مرحله قبل قابل استخراج نبود؛ Workflow متوقف شد.")
    return row


def _resolve_direct_party(worker: Any, job: dict[str, Any], query: str, call_suffix: str) -> tuple[int, str, list[str]]:
    data = worker.tool(job, "search_parties", {"query": query}, f"job{job['id']}-{call_suffix}-party-search")
    party, reason = _unique_entity(data, query)
    if party is None:
        if reason == "ambiguous":
            raise WorkflowBlocked(f"طرف‌حساب «{query}» به‌صورت یکتا پیدا نشد؛ Workflow متوقف شد.")
        raise WorkflowBlocked(f"طرف‌حساب «{query}» پیدا نشد؛ Workflow متوقف شد.")
    return int(party["id"]), str(party.get("name") or query), ["search_parties"]


def _resolve_direct_item(worker: Any, job: dict[str, Any], query: str, call_suffix: str) -> tuple[int, str, list[str]]:
    data = worker.tool(job, "search_items", {"query": query}, f"job{job['id']}-{call_suffix}-item-search")
    item, reason = _unique_entity(data, query)
    if item is None:
        if reason == "ambiguous":
            raise WorkflowBlocked(f"کالا/خدمت «{query}» به‌صورت یکتا پیدا نشد؛ Workflow متوقف شد.")
        raise WorkflowBlocked(f"کالا/خدمت «{query}» پیدا نشد؛ Workflow متوقف شد.")
    return int(item["id"]), str(item.get("name") or query), ["search_items"]


def _ledger_text(data: Any, party_name: str) -> str:
    d = data if isinstance(data, dict) else {}
    rows = _rows(d)
    out = [
        f"گردش حساب: {party_name}",
        f"مانده فعلی بر اساس آرتیکل‌های تایید/نهایی: {rg.money(d.get('balance'))}",
    ]
    for row in rows[-8:]:
        out.append(
            f"• {row.get('voucher_date') or '-'} | {row.get('voucher_no') or '-'} | "
            f"بدهکار {rg.money(row.get('debit'))} | بستانکار {rg.money(row.get('credit'))} | "
            f"مانده جاری {rg.money(row.get('running_balance'))}"
        )
    return "\n".join(out)


def _analytics_call_args(
    worker: Any,
    job: dict[str, Any],
    step: dict[str, Any],
    results: dict[str, dict[str, Any]],
) -> tuple[dict[str, Any], list[str], str]:
    args = dict(step.get("args") or {})
    used: list[str] = []

    party_from = str(args.pop("party_from", "") or "")
    item_from = str(args.pop("item_from", "") or "")
    party_query = str(args.pop("party_query", "") or "")
    item_query = str(args.pop("item_query", "") or "")

    if party_from:
        parent = results.get(party_from) or {}
        try:
            row = _top_group(parent.get("raw"), "party_id")
        except LookupError:
            return {}, used, _dependency_no_data_text("party")
        args["party_id"] = int(row["party_id"])
    elif party_query:
        party_id, _name, tools = _resolve_direct_party(worker, job, party_query, step["id"])
        args["party_id"] = party_id
        used.extend(tools)

    if item_from:
        parent = results.get(item_from) or {}
        try:
            row = _top_group(parent.get("raw"), "item_id")
        except LookupError:
            return {}, used, _dependency_no_data_text("item")
        args["item_id"] = int(row["item_id"])
    elif item_query:
        item_id, _name, tools = _resolve_direct_item(worker, job, item_query, step["id"])
        args["item_id"] = item_id
        used.extend(tools)

    # Only the already-server-supported analytics arguments are forwarded.
    allowed = {
        "kind", "period", "months", "date_from", "date_to", "jalali_year", "jalali_month",
        "status_scope", "group_by", "limit", "party_id", "item_id",
    }
    return {k: v for k, v in args.items() if k in allowed and v not in (None, "")}, used, ""


def execute_workflow(
    worker: Any,
    job: dict[str, Any],
    plan: dict[str, Any],
    planner_source: str,
    planner_model: str,
    planner_metrics: dict[str, Any] | None,
) -> tuple[str, dict[str, Any]]:
    results: dict[str, dict[str, Any]] = {}
    tools_used: list[str] = []
    emitted: list[str] = []
    partial_notes: list[str] = []
    steps = plan["steps"]

    worker.trace(
        job,
        "workflow_plan_validated",
        f"Validated accounting workflow with {len(steps)} steps",
        {"steps": len(steps), "planner_source": planner_source, "model": planner_model},
    )

    for index, step in enumerate(steps, 1):
        sid = step["id"]
        op = step["op"]
        worker.trace(
            job,
            "workflow_step",
            f"Executing workflow step {index}/{len(steps)}: {op}",
            {"step_id": sid, "op": op},
        )

        if op == "document_analytics":
            args, extra_tools, dependency_note = _analytics_call_args(worker, job, step, results)
            tools_used.extend(extra_tools)
            if dependency_note:
                text = dependency_note
                partial_notes.append(dependency_note)
                results[sid] = {"raw": {}, "text": text, "op": op, "args": dict(step.get("args") or {}), "skipped": True}
            else:
                raw = worker.tool(job, "document_analytics", args, f"job{job['id']}-wf-{sid}-analytics")
                tools_used.append("document_analytics")
                text = rg.analytics_text(raw)
                results[sid] = {"raw": raw, "text": text, "op": op, "args": dict(step.get("args") or {})}

        elif op == "compare":
            left = results.get(step["left"])
            right = results.get(step["right"])
            if not left or not right:
                raise WorkflowPlanError("workflow_compare_result_missing")
            kind = str((left.get("args") or {}).get("kind") or "sales")
            text = rg.compare_text(left.get("raw"), right.get("raw"), kind)
            results[sid] = {"raw": {"left": left.get("raw"), "right": right.get("raw")}, "text": text, "op": op}

        elif op == "party_ledger":
            if step.get("party_from"):
                parent = results.get(str(step["party_from"])) or {}
                try:
                    row = _top_group(parent.get("raw"), "party_id")
                except LookupError:
                    text = _dependency_no_data_text("party")
                    partial_notes.append(text)
                    results[sid] = {"raw": {}, "text": text, "op": op, "skipped": True}
                    if bool(step.get("emit", True)):
                        emitted.append(text)
                    worker.trace(
                        job,
                        "workflow_step_skipped",
                        "Dependent ledger step skipped because prior ranking returned no rows",
                        {"step_id": sid, "op": op, "reason": "dependency_no_data"},
                    )
                    continue
                party_id = int(row["party_id"])
                party_name = str(row.get("label") or row.get("name") or f"طرف‌حساب #{party_id}")
            else:
                party_id, party_name, extra_tools = _resolve_direct_party(
                    worker, job, str(step.get("party_query") or ""), sid
                )
                tools_used.extend(extra_tools)

            raw = worker.tool(job, "party_ledger", {"party_id": party_id}, f"job{job['id']}-wf-{sid}-ledger")
            tools_used.append("party_ledger")
            text = _ledger_text(raw, party_name)
            results[sid] = {"raw": raw, "text": text, "op": op, "party_id": party_id, "party_name": party_name}

        else:
            raise WorkflowPlanError("workflow_execute_unknown_op")

        worker.trace(
            job,
            "workflow_step_complete",
            f"Workflow step {sid} completed",
            {"step_id": sid, "op": op},
        )

        if bool(step.get("emit", True)):
            emitted.append(text)

    if not emitted:
        # Safety: never return an empty answer if a plan hid every step.
        emitted = [r["text"] for r in results.values() if r.get("text")]

    # Preserve successful earlier steps even when a later dependency has no rows.
    deduped: list[str] = []
    seen: set[str] = set()
    for block in emitted:
        if block not in seen:
            deduped.append(block)
            seen.add(block)
    final = "\n\n".join(deduped).strip()
    if not final:
        raise WorkflowPlanError("workflow_empty_result")

    worker.trace(
        job,
        "workflow_complete",
        "Constrained accounting workflow completed" + (" with unavailable dependency data" if partial_notes else ""),
        {"steps": len(steps), "tools_used": tools_used, "partial": bool(partial_notes)},
    )

    with worker.progress_lock:
        trace = list(worker.current_trace[-60:])

    model_value = planner_model if planner_model and planner_model != "none" else "none"
    meta = {
        "provider": "accounting_workflow_planner",
        "mode": "accounting_workflow_partial" if partial_notes else "accounting_workflow_read",
        "model": model_value,
        "planner_model": model_value,
        "planner_source": planner_source,
        "planner_version": PLANNER_VERSION,
        "metrics": planner_metrics or {},
        "planner_metrics": planner_metrics or {},
        "tools_used": tools_used,
        "workflow_steps": len(steps),
        "workflow_partial": bool(partial_notes),
        "trace": trace,
        "patch_version": PATCH_VERSION,
    }
    return final, meta


def install_workflow_planner(cls: type) -> None:
    if getattr(cls, "_workflow_planner_v1_installed", False):
        return

    base = cls.process_agent

    def patched(self: Any, job: dict[str, Any], tools_desc: list[dict[str, Any]]):
        prompt = str(job.get("prompt") or "")
        if not is_workflow_candidate(prompt):
            return base(self, job, tools_desc)

        self.trace(
            job,
            "workflow_candidate",
            "Complex read-only accounting request selected for constrained workflow planning",
            {"planner_version": PLANNER_VERSION},
        )

        attempted_model = "none"
        attempted_metrics: dict[str, Any] = {}
        try:
            attempted_model = self.model_for("agent")
            plan, metrics, model = llm_plan(self, job, prompt)
            return execute_workflow(self, job, plan, "llm", model, metrics)
        except WorkflowBlocked as e:
            # Real ERP ambiguity/not-found should be shown as a safe grounded block,
            # not silently broadened into another query.
            self.trace(job, "workflow_blocked", str(e), {"reason": "grounding"})
            with self.progress_lock:
                trace = list(self.current_trace[-60:])
            return str(e), {
                "provider": "accounting_workflow_planner",
                "mode": "accounting_workflow_blocked",
                "model": "none",
                "planner_model": "none",
                "planner_source": "grounding",
                "metrics": {},
                "tools_used": [],
                "trace": trace,
                "patch_version": PATCH_VERSION,
            }
        except Exception as primary_error:
            attempted_model = str(getattr(primary_error, "planner_model", attempted_model) or attempted_model)
            maybe_metrics = getattr(primary_error, "planner_metrics", None)
            if isinstance(maybe_metrics, dict):
                attempted_metrics = dict(maybe_metrics)
            reason = (type(primary_error).__name__ + ": " + str(primary_error))[:220]
            self.trace(
                job,
                "workflow_plan_rejected",
                "Primary workflow plan rejected: " + reason,
                {"reason": reason},
            )

        try:
            fallback = _canonical_fallback(prompt)
            if fallback is not None:
                self.trace(
                    job,
                    "workflow_plan_fallback",
                    "Using deterministic dependency-safe recovery plan",
                    {"planner_version": PLANNER_VERSION},
                )
                return execute_workflow(
                    self,
                    job,
                    fallback,
                    "deterministic_fallback",
                    attempted_model,
                    attempted_metrics,
                )
        except WorkflowBlocked as e:
            self.trace(job, "workflow_blocked", str(e), {"reason": "grounding"})
            with self.progress_lock:
                trace = list(self.current_trace[-60:])
            return str(e), {
                "provider": "accounting_workflow_planner",
                "mode": "accounting_workflow_blocked",
                "model": "none",
                "planner_model": "none",
                "planner_source": "grounding",
                "metrics": {},
                "tools_used": [],
                "trace": trace,
                "patch_version": PATCH_VERSION,
            }
        except Exception as fallback_error:
            self.trace(
                job,
                "workflow_fallback_rejected",
                "Deterministic workflow recovery was not applicable",
                {"reason": (type(fallback_error).__name__ + ": " + str(fallback_error))[:350]},
            )

        self.trace(
            job,
            "workflow_delegate",
            "No safe multi-step workflow was available; delegating to existing guarded stack",
            {},
        )
        return base(self, job, tools_desc)

    cls.process_agent = patched
    cls._workflow_planner_v1_installed = True
    cls._workflow_planner_v1_original_process_agent = base
