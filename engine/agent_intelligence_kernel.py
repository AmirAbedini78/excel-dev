#!/usr/bin/env python3
"""ERPSMART Agent Intelligence Kernel v1.

A bounded, read-only, single-agent orchestration loop over server-approved ERP Tools.

The kernel deliberately separates *agency* from *authority*:
- the model may select only an opaque server-declared action_id;
- action_id -> Tool + arguments is compiled by this module from validated Context
  and prior Tool observations;
- the model never creates ERP IDs, Tool names, SQL, money, dates, quantities or
  mutation arguments;
- write requests bypass the kernel and continue through the existing guarded
  Proposal -> Approval stack;
- final business facts remain Tool-owned. The model may interpret/prioritize them,
  while a deterministic decision-first fallback remains useful if model output fails.

Initial production capabilities are the live-blocking MVP-B flows:
- supplier performance / comparison
- trade risk prioritization
- shipment/trade-case impact on sales commitments

The runtime is registry/state driven so additional read capabilities can be added
without creating a separate agent or a new hard-coded report renderer per question.
"""
from __future__ import annotations

import json
import math
import re
import time
from pathlib import Path
from typing import Any, Callable

import business_skills as BS
import decision_brief as DB
from local_inference import token_upper_bound

PATCH_VERSION = "v10.10.3-cycle12.3-agent-intelligence-kernel-v1.6"
ANALYST_CONTRACT = DB.CONTRACT
AGENT_KERNEL_ROUTE_FILE = Path(__file__).resolve().parent / "data" / "agent_kernel_model_route.json"
KERNEL_CONTRACT = "erpsmart.agent-kernel.v1"
DECISION_CALL_SECONDS = 60
MAX_TOOL_CALLS = 4
MAX_PLANNER_CALLS = 3
MAX_EVIDENCE = 48
MAX_FINAL_EVIDENCE = 6
FINISH_ACTION = "finish"

_SUPPLIER_TERMS = ("تامین کننده", "تأمین کننده", "تامین‌کننده", "تأمین‌کننده", "supplier")
_COMPARE_TERMS = ("مقایسه", "کدام", "کدوم", "بهتر", "بهترین", "مناسب تر", "مناسب‌تر", "ادامه همکاری")
_TRADE_TERMS = ("بازرگانی", "محموله", "حمل", "گمرک", "ترخیص", "eta", "landed cost", "پرونده")
_RISK_TERMS = ("ریسک", "پرریسک", "خطر", "نگران", "risk")
_IMPACT_TERMS = ("اثر", "تاثیر", "تأثیر", "تعهد", "مشتری", "فروش", "impact", "commitment")
_DELAY_TERMS = ("دیر", "تاخیر", "تأخیر", "محموله", "shipment", "eta")


def _norm(value: Any) -> str:
    return BS.norm(value)


def _explicit_entities(job: dict[str, Any]) -> list[dict[str, Any]]:
    """Only user-attached refs, not page context.

    This distinction is important for explicit comparison. If the user attaches one
    Supplier and one Company, silently dropping the Company and running a portfolio
    comparison is wrong; the kernel must fail closed and ask for the correct type.
    """
    ctx = job.get("context") if isinstance(job, dict) else None
    env = ctx.get("context_envelope") if isinstance(ctx, dict) else None
    if not isinstance(env, dict) or env.get("version") != "v2" or env.get("validated") is not True:
        return []
    if int(env.get("company_id") or 0) != int(job.get("company_id") or 0):
        return []
    raw = env.get("attached_entities") if isinstance(env.get("attached_entities"), list) else []
    out: list[dict[str, Any]] = []
    seen: set[tuple[str, int]] = set()
    for item in raw:
        if not isinstance(item, dict):
            continue
        typ = str(item.get("type") or "")
        eid = int(item.get("id") or 0)
        if not typ or eid <= 0 or (typ, eid) in seen:
            continue
        seen.add((typ, eid))
        out.append({
            "type": typ,
            "id": eid,
            "label": str(item.get("label") or item.get("name") or item.get("code") or f"{typ}#{eid}"),
            "code": str(item.get("code") or ""),
        })
    return out


def _all_entities(job: dict[str, Any]) -> list[dict[str, Any]]:
    return _explicit_entities(job) or BS.context_entities(job)


def _capability(prompt: str, entities: list[dict[str, Any]]) -> str | None:
    n = _norm(prompt)
    types = {str(x.get("type") or "") for x in entities}
    if "/shipment-impact" in n:
        return "shipment.impact"
    if (types & {"trade.case", "shipment"}) and any(x in n for x in _IMPACT_TERMS) and any(x in n for x in _DELAY_TERMS):
        return "shipment.impact"
    if any(x in n for x in ("محموله", "shipment", "حمل", "پرونده")) and any(x in n for x in _IMPACT_TERMS):
        return "shipment.impact"
    if "/trade-risk" in n or "/shipment-risk" in n:
        return "trade.risk"
    if any(x in n for x in _TRADE_TERMS) and any(x in n for x in _RISK_TERMS):
        return "trade.risk"
    if "/supplier-review" in n or "/compare-suppliers" in n:
        return "supplier.performance"
    if "party.supplier" in types and any(x in n for x in ("عملکرد", "مقایسه", "بهتر", "ریسک", "خرید", "همکاری")):
        return "supplier.performance"
    if any(x in n for x in _SUPPLIER_TERMS) and any(x in n for x in ("عملکرد", "مقایسه", "بهتر", "بهترین", "ریسک", "همکاری", "قابل اعتماد", "مطمئن")):
        return "supplier.performance"
    return None


def _available_read_tools(tools_desc: list[dict[str, Any]]) -> set[str]:
    out: set[str] = set()
    for d in tools_desc:
        if not isinstance(d, dict):
            continue
        name = str(d.get("name") or "")
        mode = str(d.get("mode") or "read").lower().strip()
        if mode != "read" or not name:
            continue
        if name.startswith(("create_", "update_", "delete_", "reserve_", "deliver_", "add_", "approve_", "execute_")):
            continue
        out.add(name)
    return out


def _fmt(value: Any, unit: str = "") -> str:
    if value is None:
        return "داده کافی نیست"
    if isinstance(value, bool):
        text = "بله" if value else "خیر"
    elif isinstance(value, float):
        if unit == "ریال":
            text = f"{value:,.0f}"
        elif unit == "%":
            text = f"{value:.1f}"
        else:
            text = f"{value:g}"
    elif isinstance(value, int):
        text = f"{value:,}" if unit == "ریال" else str(value)
    else:
        text = str(value)
    return f"{text} {unit}".strip()


def _safe_number(value: Any, default: float = 0.0) -> float:
    try:
        n = float(value)
        return n if math.isfinite(n) else default
    except (TypeError, ValueError):
        return default


def _evidence(state: dict[str, Any], label: str, value: Any, unit: str, entity: str, source: str, kind: str = "fact") -> str:
    if len(state["evidence"]) >= MAX_EVIDENCE:
        return ""
    eid = f"E{len(state['evidence']) + 1}"
    state["evidence"].append({
        "id": eid,
        "label": label,
        "value": value,
        "unit": unit,
        "entity": entity,
        "source": source,
        "kind": kind,
    })
    return eid


def _compact_generic(value: Any, depth: int = 0) -> Any:
    if depth >= 3:
        return "…"
    if isinstance(value, dict):
        out: dict[str, Any] = {}
        for key in list(value)[:16]:
            out[str(key)] = _compact_generic(value[key], depth + 1)
        return out
    if isinstance(value, list):
        return [_compact_generic(x, depth + 1) for x in value[:6]]
    if isinstance(value, str):
        return value[:220]
    if isinstance(value, (int, float, bool)) or value is None:
        return value
    return str(value)[:220]


def _observe_supplier(state: dict[str, Any], result: Any) -> dict[str, Any]:
    data = result if isinstance(result, dict) else {}
    rows = [x for x in data.get("rows", []) if isinstance(x, dict)]
    compact_rows: list[dict[str, Any]] = []
    state["supplier_rows"] = rows[:5]
    state["period"] = data.get("period") or {}
    for r in rows[:5]:
        pid = int(r.get("party_id") or 0)
        name = str(r.get("name") or r.get("code") or f"supplier#{pid}")
        ordered = _safe_number(r.get("ordered_qty"))
        accepted = _safe_number(r.get("accepted_qty"))
        open_qty = _safe_number(r.get("open_qty"))
        open_ratio = (open_qty / ordered) if ordered > 0 else None
        fill = None if r.get("receipt_fill_rate") is None else _safe_number(r.get("receipt_fill_rate"))
        ontime = None if r.get("on_time_rate") is None else _safe_number(r.get("on_time_rate"))
        eids = [
            _evidence(state, "خرید قطعی دوره", _safe_number(r.get("purchase_net_total")), "ریال", name, "supplier_performance_summary"),
            _evidence(state, "تعداد اسناد خرید قطعی", int(r.get("purchase_document_count") or 0), "سند", name, "supplier_performance_summary"),
            _evidence(state, "نرخ تکمیل دریافت", None if fill is None else fill * 100, "%", name, "supplier_performance_summary"),
            _evidence(state, "نسبت مقدار باز به مقدار سفارش", None if open_ratio is None else open_ratio * 100, "%", name, "calculated:supplier_performance_summary", "calculated"),
            _evidence(state, "پرونده بازرگانی پرریسک", int(r.get("high_risk_cases") or 0), "پرونده", name, "supplier_performance_summary"),
            _evidence(state, "پرونده بازرگانی ریسک متوسط", int(r.get("medium_risk_cases") or 0), "پرونده", name, "supplier_performance_summary"),
            _evidence(state, "بیشترین تأخیر ثبت‌شده", _safe_number(r.get("max_delay_days")), "روز", name, "supplier_performance_summary"),
            _evidence(state, "نمونه ورود دارای ETA/ATA", int(r.get("arrival_sample") or 0), "مورد", name, "supplier_performance_summary"),
        ]
        if ontime is not None:
            eids.append(_evidence(state, "نرخ ورود به‌موقع", ontime * 100, "%", name, "supplier_performance_summary"))
        compact_rows.append({
            "party_id": pid,
            "name": name,
            "purchase_documents": int(r.get("purchase_document_count") or 0),
            "purchase_total_irr": _safe_number(r.get("purchase_net_total")),
            "ordered_qty": ordered,
            "accepted_qty": accepted,
            "open_qty": open_qty,
            "receipt_fill_rate": fill,
            "open_ratio": open_ratio,
            "high_risk_cases": int(r.get("high_risk_cases") or 0),
            "medium_risk_cases": int(r.get("medium_risk_cases") or 0),
            "max_delay_days": _safe_number(r.get("max_delay_days")),
            "arrival_sample": int(r.get("arrival_sample") or 0),
            "on_time_rate": ontime,
            "evidence_ids": [x for x in eids if x],
        })
    limitations = data.get("limitations")
    if isinstance(limitations, dict):
        state["limitations"].extend(str(x) for x in limitations.values() if str(x).strip())
    elif isinstance(limitations, list):
        state["limitations"].extend(str(x) for x in limitations if str(x).strip())
    return {"suppliers": compact_rows, "limitations": state["limitations"][-5:]}


def _observe_trade_risk(state: dict[str, Any], result: Any) -> dict[str, Any]:
    data = result if isinstance(result, dict) else {}
    rows = [x for x in data.get("rows", []) if isinstance(x, dict)]
    selected = {int(x["id"]) for x in state["entities"] if x.get("type") == "trade.case"}
    if not selected and re.search(r"این\s+پرونده|پرونده.{0,20}فعلی", _norm(state["prompt"])) and len(rows) > 1:
        state["scope_block"] = "trade_context_ambiguous"
        rows = []
    if selected:
        rows = [r for r in rows if int(r.get("trade_case_id") or 0) in selected]
        state["trade_scope_missing"] = not rows
    rows = sorted(rows, key=lambda r: (-int(r.get("risk_score") or 0), int(r.get("trade_case_id") or 0)))[:6]
    state["trade_rows"] = rows
    compact: list[dict[str, Any]] = []
    reason_fa = {
        "shipment_missing": "محموله ثبت نشده",
        "shipment_delayed": "عبور از ETA",
        "customs_hold": "توقف گمرکی",
        "customs_pending": "ترخیص تکمیل نشده",
        "cost_overrun": "انحراف هزینه",
    }
    for r in rows:
        case_id = int(r.get("trade_case_id") or 0)
        case_no = str(r.get("case_no") or f"case#{case_id}")
        supplier = str(r.get("supplier_name") or "-")
        entity = f"{case_no} / {supplier}"
        reasons = [reason_fa.get(str(x), str(x)) for x in (r.get("reasons") or [])]
        eids = [
            _evidence(state, "سطح ریسک", str(r.get("risk_level") or "low"), "", entity, "trade_risk_summary"),
            _evidence(state, "علت‌های قطعی ریسک", "، ".join(reasons) if reasons else "سیگنال اضافه ثبت نشده", "", entity, "trade_risk_summary"),
            _evidence(state, "وضعیت حمل", str(r.get("shipment_status") or "-"), "", entity, "trade_risk_summary"),
            _evidence(state, "وضعیت ترخیص", str(r.get("clearance_status") or "-"), "", entity, "trade_risk_summary"),
            _evidence(state, "تأخیر ثبت‌شده", _safe_number(r.get("delay_days")), "روز", entity, "trade_risk_summary"),
            _evidence(state, "Projected Landed Cost", _safe_number(r.get("projected_landed_total_irr")), "ریال", entity, "trade_risk_summary"),
        ]
        compact.append({
            "trade_case_id": case_id,
            "case_no": case_no,
            "supplier": supplier,
            "risk_level": str(r.get("risk_level") or "low"),
            "risk_score": int(r.get("risk_score") or 0),
            "reasons": reasons,
            "shipment_status": str(r.get("shipment_status") or ""),
            "clearance_status": str(r.get("clearance_status") or ""),
            "delay_days": _safe_number(r.get("delay_days")),
            "evidence_ids": [x for x in eids if x],
        })
    return {"risk_count": int(data.get("risk_count") or 0), "cases": compact}


def _observe_shipment_impact(state: dict[str, Any], result: Any) -> dict[str, Any]:
    d = result if isinstance(result, dict) else {}
    state["shipment_result"] = d
    case = d.get("case") if isinstance(d.get("case"), dict) else {}
    shipment = d.get("shipment") if isinstance(d.get("shipment"), dict) else {}
    summary = d.get("summary") if isinstance(d.get("summary"), dict) else {}
    case_id = int(d.get("trade_case_id") or case.get("id") or 0)
    entity = str(case.get("case_no") or f"trade.case#{case_id}")
    eids = [
        _evidence(state, "تعداد تعهد فروش هم‌کالای باز", int(summary.get("affected_sales_count") or 0), "سند", entity, "shipment_commitment_impact"),
        _evidence(state, "تعداد مشتری در معرض بررسی", int(summary.get("affected_customer_count") or 0), "مشتری", entity, "shipment_commitment_impact"),
        _evidence(state, "کالاهای دارای سیگنال وابستگی به همین ورودی", int(summary.get("items_with_trade_case_dependency_signal") or 0), "کالا", entity, "shipment_commitment_impact"),
        _evidence(state, "وضعیت حمل", str(shipment.get("status") or "-"), "", entity, "shipment_commitment_impact"),
        _evidence(state, "تأخیر جاری", _safe_number(shipment.get("delay_days")), "روز", entity, "shipment_commitment_impact"),
    ]
    items: list[dict[str, Any]] = []
    for item in [x for x in d.get("item_exposure", []) if isinstance(x, dict)][:6]:
        name = str(item.get("item_name") or item.get("item_code") or "کالا")
        dep = bool(item.get("potential_dependency_on_this_trade_case"))
        item_eids = [
            _evidence(state, "تعهد فروش باز", _safe_number(item.get("outstanding_sales_qty")), "", name, "shipment_commitment_impact"),
            _evidence(state, "موجودی قابل استفاده فعلی", _safe_number(item.get("available")), "", name, "shipment_commitment_impact"),
            _evidence(state, "کسری پس از موجودی فعلی", _safe_number(item.get("uncovered_after_current_available")), "", name, "shipment_commitment_impact"),
            _evidence(state, "وابستگی بالقوه به همین پرونده", dep, "", name, "shipment_commitment_impact"),
        ]
        items.append({
            "item_id": int(item.get("item_id") or 0),
            "name": name,
            "outstanding": _safe_number(item.get("outstanding_sales_qty")),
            "available": _safe_number(item.get("available")),
            "uncovered": _safe_number(item.get("uncovered_after_current_available")),
            "depends_on_case": dep,
            "evidence_ids": [x for x in item_eids if x],
        })
    sales: list[dict[str, Any]] = []
    for row in [x for x in d.get("affected_sales", []) if isinstance(x, dict)][:8]:
        sale_entity = f"{row.get('document_no') or 'سند فروش'} / {row.get('customer_name') or 'مشتری'}"
        se = [
            _evidence(state, "تعهد تحویل باز سند", _safe_number(row.get("outstanding_quantity")), "", sale_entity, "shipment_commitment_impact"),
            _evidence(state, "مقدار بدون رزرو سند", _safe_number(row.get("unreserved_quantity")), "", sale_entity, "shipment_commitment_impact"),
        ]
        sales.append({
            "sales_doc_id": int(row.get("sales_doc_id") or 0),
            "document_no": str(row.get("document_no") or ""),
            "customer": str(row.get("customer_name") or ""),
            "outstanding": _safe_number(row.get("outstanding_quantity")),
            "unreserved": _safe_number(row.get("unreserved_quantity")),
            "evidence_ids": [x for x in se if x],
        })
    raw_lim = d.get("limitations")
    if isinstance(raw_lim, dict):
        state["limitations"].extend(str(x) for x in raw_lim.values() if str(x).strip())
    elif isinstance(raw_lim, list):
        state["limitations"].extend(str(x) for x in raw_lim if str(x).strip())
    return {
        "trade_case_id": case_id,
        "entity": entity,
        "summary": {
            "affected_sales_count": int(summary.get("affected_sales_count") or 0),
            "affected_customer_count": int(summary.get("affected_customer_count") or 0),
            "dependency_items": int(summary.get("items_with_trade_case_dependency_signal") or 0),
            "evidence_ids": [x for x in eids if x],
        },
        "items": items,
        "sales": sales,
    }


def _observe_supplier_mix(state: dict[str, Any], action: dict[str, Any], result: Any) -> dict[str, Any]:
    d = result if isinstance(result, dict) else {}
    groups = [x for x in d.get("groups", []) if isinstance(x, dict)][:5]
    name = str(action.get("entity_label") or "تأمین‌کننده")
    for g in groups[:4]:
        _evidence(state, "خرید قطعی به تفکیک کالا", _safe_number(g.get("line_total") or g.get("net_total")), "ریال", f"{name} / {g.get('label') or g.get('code') or 'کالا'}", "document_analytics")
    return {"supplier": name, "top_purchase_items": _compact_generic(groups)}


def _observe_supplier_trade(state: dict[str, Any], action: dict[str, Any], result: Any) -> dict[str, Any]:
    rows = result if isinstance(result, list) else (result.get("rows", []) if isinstance(result, dict) else [])
    rows = [x for x in rows if isinstance(x, dict)][:8]
    name = str(action.get("entity_label") or "تأمین‌کننده")
    pid = int(action.get("supplier_id") or 0)
    rows = [r for r in rows if int(r.get("supplier_id") or 0) == pid and pid > 0]
    state.setdefault("supplier_cases", {})[pid] = rows
    for r in rows[:5]:
        entity = str(r.get("case_no") or "پرونده")
        _evidence(state, "وضعیت پرونده بازرگانی", str(r.get("status") or "-"), "", f"{name} / {entity}", "search_trade_cases")
        _evidence(state, "وضعیت آخرین محموله", str(r.get("latest_shipment_status") or "-"), "", f"{name} / {entity}", "search_trade_cases")
    return {"supplier": name, "trade_cases": _compact_generic(rows)}


def _observe_supplier_ledger(state: dict[str, Any], action: dict[str, Any], result: Any) -> dict[str, Any]:
    d = result if isinstance(result, dict) else {}
    name = str(action.get("entity_label") or "تأمین‌کننده")
    # Different historical ledger contracts use current_balance/balance fields.
    balance = d.get("current_balance")
    if balance is None:
        balance = d.get("current_balance_irr")
    if balance is None:
        balance = d.get("balance")
    if balance is not None:
        _evidence(state, "مانده فعلی طرف‌حساب", _safe_number(balance), "ریال", name, "party_ledger")
    return {"supplier": name, "ledger": _compact_generic(d)}


def _observe_trade_snapshot(state: dict[str, Any], action: dict[str, Any], result: Any) -> dict[str, Any]:
    d = result if isinstance(result, dict) else {}
    case = d.get("case") if isinstance(d.get("case"), dict) else {}
    entity = str(case.get("case_no") or action.get("entity_label") or "پرونده")
    pid = int(action.get("supplier_id") or 0)
    if pid and int(case.get("supplier_id") or 0) == pid:
        state.setdefault("supplier_case_details", {}).setdefault(pid, []).append(case)
    _evidence(state, "وضعیت پرونده", str(case.get("status") or "-"), "", entity, "trade_case_snapshot")
    _evidence(state, "وضعیت ترخیص", str(case.get("clearance_status") or "-"), "", entity, "trade_case_snapshot")
    landed = d.get("landed_cost") if isinstance(d.get("landed_cost"), dict) else {}
    if landed:
        _evidence(state, "Landed Cost پیش‌بینی‌شده", _safe_number(landed.get("projected_landed_total_irr")), "ریال", entity, "trade_case_snapshot")
    ships = [x for x in d.get("shipments", []) if isinstance(x, dict)][:3]
    return {"case": _compact_generic(case), "shipments": _compact_generic(ships), "landed_cost": _compact_generic(landed)}


def _observe_landed(state: dict[str, Any], action: dict[str, Any], result: Any) -> dict[str, Any]:
    d = result if isinstance(result, dict) else {}
    entity = str(d.get("case_no") or action.get("entity_label") or "پرونده")
    _evidence(state, "Landed Cost پیش‌بینی‌شده", _safe_number(d.get("projected_landed_total_irr")), "ریال", entity, "landed_cost_summary")
    _evidence(state, "هزینه اضافی واقعی ثبت‌شده", _safe_number(d.get("actual_additional_recorded_irr")), "ریال", entity, "landed_cost_summary")
    _evidence(state, "پوشش انواع هزینه واقعی", _safe_number(d.get("actual_cost_type_coverage")) * 100, "%", entity, "landed_cost_summary")
    return _compact_generic(d)


_OBSERVERS: dict[str, Callable[[dict[str, Any], dict[str, Any]], dict[str, Any]]] = {
    "supplier_performance_summary": _observe_supplier,
    "trade_risk_summary": _observe_trade_risk,
    "shipment_commitment_impact": _observe_shipment_impact,
}


def _initial_action(cap: str, state: dict[str, Any], available: set[str]) -> dict[str, Any] | None:
    if cap == "supplier.performance":
        if "supplier_performance_summary" not in available:
            return None
        explicit = state["explicit_entities"]
        suppliers = [x for x in (explicit or state["entities"]) if x.get("type") == "party.supplier"]
        compare = any(x in _norm(state["prompt"]) for x in _COMPARE_TERMS) or "/compare-suppliers" in _norm(state["prompt"])
        if explicit and compare:
            if len(explicit) != 2 or len(suppliers) != 2:
                return {"blocked": "supplier_entity_type_mismatch"}
        n = _norm(state["prompt"])
        # Preserve an explicit supported period; never silently substitute six months.
        period = re.search(r"(\d+|یک|دو|سه|چهار|پنج|شش|هفت|هشت|نه|ده|یازده|دوازده)\s*ماه", n)
        names = dict(zip(("یک","دو","سه","چهار","پنج","شش","هفت","هشت","نه","ده","یازده","دوازده"), range(1,13)))
        months = names.get(period[1], int(period[1]) if period and period[1].isdigit() else 6) if period else (1 if "ماه گذشته" in n else 6)
        if months > 12 or months < 1 or any(x in n for x in ("آینده", "از تاریخ", "تا تاریخ", "سال گذشته", "امسال", "هفته گذشته")):
            return {"blocked": "supplier_period_unsupported"}
        args: dict[str, Any] = {"months": months, "limit": max(2, len(suppliers)) if suppliers else 5}
        if suppliers:
            args["party_ids"] = [int(x["id"]) for x in suppliers[:2]]
        return {"id": "supplier.performance.primary", "tool": "supplier_performance_summary", "arguments": args, "description": "خواندن عملکرد قطعی تأمین‌کننده از خرید، دریافت و Trade Risk"}
    if cap == "trade.risk":
        if any(x.get("type") == "shipment" for x in state["entities"]) and not any(x.get("type") == "trade.case" for x in state["entities"]):
            return {"blocked": "shipment_risk_parent_required"}
        if "trade_risk_summary" not in available:
            return None
        return {"id": "trade.risk.primary", "tool": "trade_risk_summary", "arguments": {"limit": 100 if any(x.get("type") == "trade.case" for x in state["entities"]) else 10}, "description": "خواندن ریسک جاری پرونده‌های بازرگانی"}
    if cap == "shipment.impact":
        if "shipment_commitment_impact" not in available:
            return None
        trade = next((x for x in state["entities"] if x.get("type") == "trade.case"), None)
        shipment = next((x for x in state["entities"] if x.get("type") == "shipment"), None)
        if not trade and not shipment:
            return {"blocked": "shipment_context_required"}
        args = {"limit": 15}
        if trade:
            args["trade_case_id"] = int(trade["id"])
        else:
            args["shipment_id"] = int(shipment["id"])
        return {"id": "shipment.impact.primary", "tool": "shipment_commitment_impact", "arguments": args, "description": "اتصال محموله به موجودی و تعهدهای فروش"}
    return None


def _actions_after_observation(state: dict[str, Any], available: set[str]) -> list[dict[str, Any]]:
    cap = state["capability"]
    actions: list[dict[str, Any]] = []
    if cap == "supplier.performance":
        rows = state.get("supplier_rows") or []
        # Drill into at most two suppliers; exact args come from Tool-returned IDs/labels.
        for r in sorted(rows[:5], key=lambda r: (-int(r.get("high_risk_cases") or 0), -int(r.get("medium_risk_cases") or 0))):
            pid = int(r.get("party_id") or 0)
            name = str(r.get("name") or r.get("code") or f"supplier#{pid}")
            if pid <= 0:
                continue
            if "document_analytics" in available:
                actions.append({
                    "id": f"supplier.purchase_mix.{pid}",
                    "tool": "document_analytics",
                    "arguments": {"kind": "purchases", "period": "rolling_jalali_months", "months": 6, "status_scope": "confirmed", "party_id": pid, "group_by": "item", "limit": 5},
                    "description": f"بررسی ترکیب خرید {name} برای تفسیر مقدار/نسبت باز",
                    "entity_label": name,
                    "observer": "supplier_mix",
                })
            if (int(r.get("trade_case_count") or 0) > 0 or int(r.get("high_risk_cases") or 0) > 0 or int(r.get("medium_risk_cases") or 0) > 0) and "search_trade_cases" in available:
                actions.append({
                    "id": f"supplier.trade_cases.{pid}",
                    "tool": "search_trade_cases",
                    "arguments": {"query": name},
                    "description": f"باز کردن پرونده‌های بازرگانی {name} برای توضیح سیگنال ریسک",
                    "entity_label": name,
                    "observer": "supplier_trade",
                    "supplier_id": pid,
                })
            if any(x in _norm(state["prompt"]) for x in ("مانده", "مالی", "پرداخت", "بدهی", "اعتبار")) and "party_ledger" in available:
                actions.append({
                    "id": f"supplier.ledger.{pid}",
                    "tool": "party_ledger",
                    "arguments": {"party_id": pid},
                    "description": f"بررسی مانده مالی {name} چون سؤال کاربر بعد مالی دارد",
                    "entity_label": name,
                    "observer": "supplier_ledger",
                })
        for pid, cases in state.get("supplier_cases", {}).items():
            for case in cases[:2]:
                cid = int(case.get("id") or 0)
                if cid > 0 and "trade_case_snapshot" in available:
                    actions.insert(0, {"id": f"supplier.case_snapshot.{cid}", "tool": "trade_case_snapshot",
                        "arguments": {"case_id": cid}, "description": "بررسی علت عملیاتی ریسک پرونده مرتبط",
                        "supplier_id": pid, "observer": "trade_snapshot", "entity_label": str(case.get("case_no") or "")})
    elif cap == "trade.risk":
        rows = state.get("trade_rows") or []
        for r in rows[:2]:
            cid = int(r.get("trade_case_id") or 0)
            if cid <= 0:
                continue
            label = str(r.get("case_no") or f"case#{cid}")
            if "trade_case_snapshot" in available:
                actions.append({"id": f"trade.snapshot.{cid}", "tool": "trade_case_snapshot", "arguments": {"case_id": cid}, "description": f"بررسی جزئیات پرونده {label} برای علت و مرحله بعد", "entity_label": label, "observer": "trade_snapshot"})
            reasons = {str(x) for x in (r.get("reasons") or [])}
            if ("cost_overrun" in reasons or any(x in _norm(state["prompt"]) for x in ("هزینه", "landed", "بهای تمام"))) and "landed_cost_summary" in available:
                actions.append({"id": f"trade.landed.{cid}", "tool": "landed_cost_summary", "arguments": {"case_id": cid}, "description": f"بررسی Landed Cost پرونده {label}", "entity_label": label, "observer": "landed"})
            if any(x in _norm(state["prompt"]) for x in ("اثر", "فروش", "مشتری", "تعهد")) and "shipment_commitment_impact" in available:
                actions.append({"id": f"trade.impact.{cid}", "tool": "shipment_commitment_impact", "arguments": {"trade_case_id": cid, "limit": 15}, "description": f"بررسی اثر پرونده {label} بر تعهدهای فروش", "entity_label": label, "observer": "shipment_impact"})
    elif cap == "shipment.impact":
        d = state.get("shipment_result") if isinstance(state.get("shipment_result"), dict) else {}
        cid = int(d.get("trade_case_id") or 0)
        if cid > 0 and "trade_case_snapshot" in available:
            actions.append({"id": f"shipment.snapshot.{cid}", "tool": "trade_case_snapshot", "arguments": {"case_id": cid}, "description": "بررسی مرحله فعلی حمل/گمرک برای تفسیر احتمال اثر", "entity_label": str((d.get("case") or {}).get("case_no") or f"case#{cid}"), "observer": "trade_snapshot"})
    executed = state["executed_action_ids"]
    dedup: list[dict[str, Any]] = []
    seen: set[str] = set()
    for a in actions:
        aid = str(a.get("id") or "")
        if not aid or aid in executed or aid in seen:
            continue
        if str(a.get("tool") or "") not in available:
            continue
        seen.add(aid)
        dedup.append(a)
    return dedup[:8]


def _planner_schema(action_ids: list[str]) -> dict[str, Any]:
    return {
        "type": "object",
        "properties": {
            "action_id": {"type": "string", "enum": [*action_ids, FINISH_ACTION]},
        },
        "required": ["action_id"],
        "additionalProperties": False,
    }


def _planner_fallback(state: dict[str, Any], actions: list[dict[str, Any]]) -> str:
    if not actions:
        return FINISH_ACTION
    cap = state["capability"]
    if cap == "supplier.performance":
        rows = state.get("supplier_rows") or []
        # If operational evidence is weak/ambiguous, purchase-mix is the most useful
        # bounded drill-down. If a supplier has explicit Trade risk, inspect that first.
        for r in rows[:5]:
            if int(r.get("high_risk_cases") or 0) > 0 or int(r.get("medium_risk_cases") or 0) > 0:
                prefix = f"supplier.trade_cases.{int(r.get('party_id') or 0)}"
                if any(a["id"] == prefix for a in actions):
                    return prefix
        snapshot = next((a for a in actions if str(a["id"]).startswith("supplier.case_snapshot.")), None)
        if snapshot:
            return str(snapshot["id"])
        if state.get("planner_mode") != "model":
            return FINISH_ACTION
        weak_receipts = bool(rows) and all((r.get("receipt_fill_rate") is None or _safe_number(r.get("receipt_fill_rate")) <= 0.000001) for r in rows[:2])
        if weak_receipts:
            mix = next((a for a in actions if str(a["id"]).startswith("supplier.purchase_mix.")), None)
            if mix:
                return str(mix["id"])
        return FINISH_ACTION
    if cap == "trade.risk":
        rows = state.get("trade_rows") or []
        if rows and (str(rows[0].get("risk_level") or "") in {"high", "medium"}):
            snap = next((a for a in actions if str(a["id"]).startswith("trade.snapshot.")), None)
            if snap:
                return str(snap["id"])
        return FINISH_ACTION
    return FINISH_ACTION


def _select_next_action(worker: Any, job: dict[str, Any], state: dict[str, Any], actions: list[dict[str, Any]]) -> str:
    if not actions:
        return FINISH_ACTION
    if state.get("planner_mode") != "model":
        selected = _planner_fallback(state, actions)
        worker.trace(job, "kernel_plan", "Known evidence rule selected a bounded read", {"selected_action": selected, "planner": "deterministic"})
        return selected
    menu = [{"action_id": a["id"], "purpose": a["description"]} for a in actions]
    observations = [{"action_id": o["action_id"], "tool": o["tool"], "observation": o["compact"]} for o in state["observations"][-3:]]
    system = (
        "You are the bounded planner inside ERPSMART Agent Intelligence Kernel. "
        "Choose exactly one action_id from ACTION_MENU or choose finish. "
        "The server owns every Tool name and every argument; you cannot change or invent them. "
        "Choose another action only when it can materially improve the user's business decision. "
        "Avoid redundant reads. Never ask for write/create/update/delete actions."
    )
    payload = {
        "goal": state["prompt"],
        "capability": state["capability"],
        "observations": observations,
        "action_menu": menu,
        "remaining_tool_budget": MAX_TOOL_CALLS - len(state["tool_sequence"]),
    }
    state["planner_calls"] += 1
    try:
        model = worker.model_for("agent")
        worker.trace(job, "kernel_plan", "Bounded planner choosing next server-declared action", {"model": model, "action_count": len(actions), "planner_call": state["planner_calls"] + 1})
        response = worker.ollama_chat(
            job,
            70 + state["planner_calls"],
            [{"role": "system", "content": system}, {"role": "user", "content": json.dumps(payload, ensure_ascii=False, separators=(",", ":"), default=str)}],
            [],
            fast=True,
            model=model,
            num_ctx=2200,
            num_predict=90,
            temperature=0.0,
            timeout_seconds=max(1, min(30, state["deadline"] - time.monotonic())),
            response_format=_planner_schema([str(a["id"]) for a in actions]),
            think_override=False,
        )
        data = json.loads(str((response.get("message") or {}).get("content") or ""))
        selected = str(data.get("action_id") or "") if isinstance(data, dict) else ""
        allowed = {str(a["id"]) for a in actions} | {FINISH_ACTION}
        if selected not in allowed:
            raise ValueError("planner_action_not_allowed")
        return selected
    except Exception as exc:
        selected = _planner_fallback(state, actions)
        worker.trace(job, "kernel_replan", "Planner unavailable/invalid; deterministic bounded planner used", {"error_type": type(exc).__name__, "selected_action": selected})
        return selected


def _execute_action(worker: Any, job: dict[str, Any], state: dict[str, Any], action: dict[str, Any]) -> None:
    aid = str(action["id"])
    if aid in state["executed_action_ids"]:
        raise RuntimeError("kernel_repeated_action_blocked")
    if len(state["tool_sequence"]) >= MAX_TOOL_CALLS:
        raise RuntimeError("kernel_tool_budget_exceeded")
    tool = str(action["tool"])
    args = dict(action.get("arguments") or {})
    state["executed_action_ids"].add(aid)
    worker.trace(job, "kernel_tool", "Executing server-compiled read action", {"action_id": aid, "tool": tool, "argument_keys": sorted(args), "tool_call": len(state["tool_sequence"]) + 1})
    result = worker.tool(job, tool, args, BS.stable(int(job["id"]), "kernel-action", {"action_id": aid, "args": args}))
    state["tool_sequence"].append(tool)
    observer = str(action.get("observer") or "")
    if tool == "supplier_performance_summary":
        compact = _observe_supplier(state, result)
    elif tool == "trade_risk_summary":
        compact = _observe_trade_risk(state, result)
    elif tool == "shipment_commitment_impact":
        compact = _observe_shipment_impact(state, result)
    elif observer == "supplier_mix":
        compact = _observe_supplier_mix(state, action, result)
    elif observer == "supplier_trade":
        compact = _observe_supplier_trade(state, action, result)
    elif observer == "supplier_ledger":
        compact = _observe_supplier_ledger(state, action, result)
    elif observer == "trade_snapshot":
        compact = _observe_trade_snapshot(state, action, result)
    elif observer == "landed":
        compact = _observe_landed(state, action, result)
    elif observer == "shipment_impact":
        compact = _observe_shipment_impact(state, result)
    else:
        compact = _compact_generic(result)
    state["observations"].append({"action_id": aid, "tool": tool, "compact": compact})
    worker.trace(job, "kernel_observation", "Tool result converted to bounded business observation", {"action_id": aid, "tool": tool, "evidence_count": len(state["evidence"])})


def _configured_decision_models(worker: Any) -> list[str]:
    """Return bounded configured models for decision synthesis.

    Runtime preflight may prove a specific model, but repository configuration stays
    authoritative. A persisted route is trusted only while that model is still one
    of the currently configured analysis/fallback/agent models.
    """
    models: list[str] = []
    for role in ("agent", "fallback", "analysis"):
        try:
            model = str(worker.model_for(role) or "").strip()
        except Exception:
            model = ""
        if model and model not in models:
            models.append(model)
    return models[:3]


def _decision_models(worker: Any, route_file: Path | None = None) -> list[str]:
    """Return the bounded decision-model chain or an explicit safe-open circuit.

    A live preflight may prove that every currently configured local analyst violates
    the compact structured-output contract. Persisting that *capability result* lets
    runtime skip repeated 90-second failures and use the deterministic decision
    renderer immediately. The circuit is valid only for this exact kernel/contract
    and exact configured model list; any configuration change automatically reopens
    model evaluation. Business truth and authorization are never stored here.
    """
    configured = _configured_decision_models(worker)
    path = route_file or AGENT_KERNEL_ROUTE_FILE
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        selected = str(data.get("analyst_model") or data.get("selected_model") or "").strip()
        contract = str(data.get("analyst_contract") or "").strip()
        kernel_version = str(data.get("kernel_version") or "").strip()
        mode = str(data.get("mode") or "model").strip()
        expires = float(data.get("expires_epoch") or 0)
        fingerprint = str(data.get("runtime_fingerprint") or "")
        route_models = [str(x).strip() for x in (data.get("configured_decision_models") or []) if str(x).strip()]
    except Exception:
        selected = ""
        contract = ""
        kernel_version = ""
        mode = ""
        route_models = []
        expires = 0
        fingerprint = ""

    runtime = getattr(worker, "local_inference", None)
    current_fingerprint = runtime.fingerprint() if runtime else "offline"
    route_current = (
        contract == ANALYST_CONTRACT
        and kernel_version == PATCH_VERSION
        and route_models == configured
        and expires > time.time()
        and fingerprint == current_fingerprint
    )
    if route_current and mode == "deterministic_decision_fallback":
        return []
    if route_current and selected in configured:
        return [selected] + [m for m in configured if m != selected]
    return configured




def _safe_json_object(raw: str) -> dict[str, Any]:
    """Best-effort deterministic extraction only. Never invents fields."""
    text = str(raw or "").strip()
    text = re.sub(r"^```(?:json)?\s*", "", text, flags=re.I)
    text = re.sub(r"\s*```$", "", text)
    start = text.find("{")
    end = text.rfind("}")
    if start >= 0 and end > start:
        candidate = text[start:end + 1]
        data = json.loads(candidate)
        if isinstance(data, dict):
            return data
    raise ValueError("no_recoverable_json_object")


def _synthesize(worker: Any, job: dict[str, Any], state: dict[str, Any]) -> tuple[str, dict[str, Any]]:
    brief = DB.build(state)
    state["brief"] = brief
    if not brief["findings"]:
        return DB.render(brief), {"synthesis": "deterministic_decision_fallback", "synthesis_error": "empty_evidence", "confidence": brief["confidence"], "model": "none"}
    messages = DB.messages(state["prompt"], brief)
    # Do not silently drop a user's constraints or chop JSON to make it fit.
    if token_upper_bound(messages) + 128 + 192 > 2048:
        return DB.render(brief), {"synthesis": "deterministic_decision_fallback", "synthesis_error": "context_budget", "confidence": brief["confidence"], "model": "none"}
    last_error = "analysis_model_unavailable"
    for attempt, model in enumerate(_decision_models(worker)[:2], 1):
        remaining = state["deadline"] - time.monotonic()
        if remaining <= 1:
            last_error = "deadline"
            break
        try:
            worker.trace(job, "kernel_answer", "Grounded finding matrix sent for ID selection", {
                "model": model, "attempt": attempt, "analyst_contract": ANALYST_CONTRACT,
                "prompt_token_upper_bound": token_upper_bound(messages), "finding_count": len(brief["findings"])})
            response = worker.ollama_chat(job, 80 + attempt, messages, [], model=model,
                num_ctx=2048, num_predict=128, temperature=0.0, timeout_seconds=min(DECISION_CALL_SECONDS, remaining),
                response_format=DB.schema(brief), think_override=False)
            data = DB.validate(_safe_json_object(str((response.get("message") or {}).get("content") or "")), brief)
            actual = str((response.get("_metrics") or {}).get("model") or model)
            worker.trace(job, "kernel_verify", "Model selected allowed findings; severity and facts remain server-owned", {
                "model": actual, "analyst_contract": ANALYST_CONTRACT, "selected_findings": data["finding_ids"]})
            return DB.render(brief, data), {"synthesis": "decision_model", "model": actual,
                "confidence": brief["confidence"], "analyst_contract": ANALYST_CONTRACT}
        except Exception as exc:
            last_error = str(getattr(exc, "code", type(exc).__name__))
            worker.trace(job, "kernel_fallback", "Finding selection unavailable; grounded brief preserved", {
                "model": model, "error_type": last_error, "attempt": attempt, "analyst_contract": ANALYST_CONTRACT})
    return DB.render(brief), {"synthesis": "deterministic_decision_fallback", "synthesis_error": last_error,
                              "confidence": brief["confidence"], "model": "none", "analyst_contract": ANALYST_CONTRACT}


def _block_message(state: dict[str, Any], reason: str) -> tuple[str, dict[str, Any]]:
    if reason == "supplier_period_unsupported":
        text = "این مقایسه فعلاً بازهٔ یک تا دوازده ماه گذشته را پشتیبانی می‌کند. بازهٔ خواسته‌شده را به بازهٔ دیگری تبدیل نکردم؛ تعداد ماه‌های گذشته را مشخص کن."
    elif reason == "trade_context_ambiguous":
        text = "چند پرونده بازرگانی در داده‌های فعلی وجود دارد. برای بررسی «پرونده فعلی»، پرونده موردنظر را با @ انتخاب کن."
    elif reason == "shipment_risk_parent_required":
        text = "برای ریسک همین محموله، پروندهٔ بازرگانی مرتبط را با @ انتخاب کن؛ فهرست ریسک کل شرکت جایگزین آن نمی‌شود."
    elif reason == "supplier_entity_type_mismatch":
        explicit = state["explicit_entities"]
        typed = "، ".join(f"{x['label']} ({x['type']})" for x in explicit[:4]) or "بدون انتخاب"
        text = (
            "برای مقایسه دقیق دو تأمین‌کننده، هر دو @ باید از نوع «تأمین‌کننده» باشند.\n"
            f"انتخاب فعلی: {typed}\n"
            "من این انتخاب را به مقایسه کل سبد تأمین‌کنندگان تبدیل نمی‌کنم؛ @ دوم را از گروه تأمین‌کننده انتخاب کن."
        )
    elif reason == "shipment_context_required":
        text = "برای تحلیل اثر این تأخیر، یک پرونده بازرگانی یا محموله معتبر را با @ متصل کن؛ اگر صفحه دقیق پرونده باز باشد Context همان صفحه هم قابل استفاده است."
    else:
        text = "ابزار خواندنی لازم برای این قابلیت در سطح دسترسی/ماژول فعلی در دسترس نیست."
    return text, {
        "provider": "deterministic",
        "model": "none",
        "mode": "agent_intelligence_kernel_blocked",
        "kernel": KERNEL_CONTRACT,
        "kernel_version": PATCH_VERSION,
        "capability": state["capability"],
        "block_reason": reason,
        "tools_used": [],
    }


def run_kernel(worker: Any, job: dict[str, Any], tools_desc: list[dict[str, Any]], capability: str | None = None) -> tuple[str, dict[str, Any]]:
    prompt = str(job.get("prompt") or "")
    entities = _all_entities(job)
    cap = capability or _capability(prompt, entities)
    if not cap:
        raise ValueError("kernel_capability_required")
    available = _available_read_tools(tools_desc)
    state: dict[str, Any] = {
        "prompt": prompt,
        "capability": cap,
        "entities": entities,
        "explicit_entities": _explicit_entities(job),
        "observations": [],
        "evidence": [],
        "limitations": [],
        "executed_action_ids": set(),
        "tool_sequence": [],
        "planner_calls": 0,
        "started": time.monotonic(),
        "deadline": time.monotonic() + max(5, min(120, float((getattr(worker, "cfg", {}) or {}).get("kernel_budget_seconds", 75)))),
        "planner_mode": str((getattr(worker, "cfg", {}) or {}).get("kernel_planner_mode", "auto")),
    }
    worker.trace(job, "kernel_goal", "Agent Intelligence Kernel accepted a read-only business goal", {"capability": cap, "explicit_entities": len(state["explicit_entities"]), "context_entities": len(entities)})
    worker.trace(job, "kernel_capability_retrieval", "Bounded capability retrieved; action registry filtered by current read tools", {"capability": cap, "available_read_tools": len(available)})

    initial = _initial_action(cap, state, available)
    if not initial:
        return _block_message(state, "required_read_tool_unavailable")
    if initial.get("blocked"):
        return _block_message(state, str(initial["blocked"]))

    _execute_action(worker, job, state, initial)
    if state.get("scope_block"):
        return _block_message(state, state["scope_block"])

    while len(state["tool_sequence"]) < MAX_TOOL_CALLS and state["planner_calls"] < MAX_PLANNER_CALLS:
        if time.monotonic() >= state["deadline"]:
            state["limitations"].append("بودجه زمانی خوانش تکمیلی تمام شد؛ نتیجه بر شواهد دریافت‌شده متکی است.")
            break
        actions = _actions_after_observation(state, available)
        if not actions:
            break
        selected = _select_next_action(worker, job, state, actions)
        if selected == FINISH_ACTION:
            break
        action = next((a for a in actions if str(a["id"]) == selected), None)
        if not action:
            worker.trace(job, "kernel_replan", "Planner selected unavailable action; terminating safely", {"selected_action": selected})
            break
        try:
            _execute_action(worker, job, state, action)
        except Exception as exc:
            state["limitations"].append("خوانش تکمیلی در دسترس نبود؛ علت دقیق‌تر ریسک هنوز تأیید نشده است.")
            worker.trace(job, "kernel_fallback", "Optional read failed; primary evidence retained", {"error_type": type(exc).__name__})
            break

    text, synthesis_meta = _synthesize(worker, job, state)
    elapsed = round(time.monotonic() - state["started"], 3)
    meta = {
        "provider": "grounded_agent_kernel" if synthesis_meta.get("synthesis") == "decision_model" else "deterministic",
        "model": synthesis_meta.get("model", "none"),
        "mode": "agent_intelligence_kernel_read",
        "kernel": KERNEL_CONTRACT,
        "kernel_version": PATCH_VERSION, "analyst_contract": ANALYST_CONTRACT,
        "capability": cap,
        "tools_used": list(state["tool_sequence"]),
        "tool_sequence": list(state["tool_sequence"]),
        "tool_calls": len(state["tool_sequence"]),
        "planner_calls": state["planner_calls"],
        "evidence_count": len(state["evidence"]),
        "synthesis": synthesis_meta.get("synthesis"),
        "synthesis_error": synthesis_meta.get("synthesis_error"),
        "confidence": synthesis_meta.get("confidence"),
        "guard_contract": "server_action_id_only+read_tools+grounded_finding_ids+severity_guard",
        "metrics": {"elapsed_seconds": elapsed},
    }
    worker.trace(job, "kernel_verify", "Agent Kernel completed within bounded tool/plan budget", {"capability": cap, "tool_calls": len(state["tool_sequence"]), "planner_calls": state["planner_calls"], "elapsed_seconds": elapsed})
    return text, meta


def install_agent_intelligence_kernel(worker_cls: type) -> None:
    if getattr(worker_cls, "_agent_intelligence_kernel_v1_installed", False):
        return
    original = worker_cls.process_agent

    def patched(self: Any, job: dict[str, Any], tools_desc: list[dict[str, Any]]):
        prompt = str(job.get("prompt") or "")
        if BS.is_write_request(prompt):
            return original(self, job, tools_desc)
        entities = _all_entities(job)
        cap = _capability(prompt, entities)
        if not cap:
            return original(self, job, tools_desc)
        return run_kernel(self, job, tools_desc, cap)

    worker_cls.process_agent = patched
    worker_cls._agent_intelligence_kernel_v1_installed = True
    worker_cls._agent_intelligence_kernel_v1_original_process_agent = original
