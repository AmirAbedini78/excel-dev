#!/usr/bin/env python3
"""ERPSMART v10.9 Cycle 12 — Skills / Capability Retrieval / Natural-Language routing.

Design:
- Natural language remains primary.
- `/skill-id` is an explicit discovery/selection shortcut, not a privileged command.
- The model may select capability IDs only; it never creates tool arguments or business IDs.
- Business facts always come from server tools.
- Existing guarded write routes are never intercepted.
"""
from __future__ import annotations

import hashlib
import json
import re
from typing import Any

import crm_lite as CRM
import inventory_procurement as IP
import sales_fulfillment as SF
import trade_logistics as TL

PATCH_VERSION = "v10.9-cycle12-skills-r6"
DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")

CAPABILITIES: dict[str, dict[str, Any]] = {
    "customer-review": {
        "title": "بررسی ۳۶۰ مشتری",
        "tools": {"crm_customer_360"},
        "entity_types": {"party.customer"},
        "keywords": ("مشتری", "معاملات", "فروش", "مانده", "وضعیت", "عملکرد", "رابطه", "پیگیری"),
    },
    "compare-customers": {
        "title": "مقایسه مشتری‌ها",
        "tools": {"crm_customer_360"},
        "entity_types": {"party.customer"},
        "keywords": ("مقایسه", "مقایس", "کدام", "کدوم", "بهتر", "اولویت", "ارزشمند", "ریسک"),
    },
    "supplier-review": {
        "title": "بررسی عملکرد تأمین‌کننده",
        "tools": {"document_analytics", "party_ledger", "search_trade_cases", "trade_risk_summary"},
        "entity_types": {"party.supplier"},
        "keywords": ("تامین کننده", "تأمین کننده", "تامین‌کننده", "تأمین‌کننده", "خرید", "عملکرد", "ریسک", "تاخیر", "تأخیر"),
    },
    "compare-suppliers": {
        "title": "مقایسه تأمین‌کننده‌ها",
        "tools": {"document_analytics", "party_ledger", "search_trade_cases", "trade_risk_summary"},
        "entity_types": {"party.supplier"},
        "keywords": ("مقایسه", "کدام", "کدوم", "بهتر", "تامین", "تأمین", "خرید", "تاخیر", "تأخیر"),
    },
    "trade-risk": {
        "title": "ریسک بازرگانی و محموله",
        "tools": {"trade_risk_summary", "trade_case_snapshot", "landed_cost_summary"},
        "entity_types": {"trade.case", "shipment"},
        "keywords": ("ریسک", "بازرگانی", "محموله", "حمل", "eta", "گمرک", "ترخیص", "تاخیر", "تأخیر", "دیر", "landed cost", "بهای تمام"),
    },
    "inventory-risk": {
        "title": "ریسک موجودی و کمبود",
        "tools": {"replenishment_risk", "inventory_position", "purchase_pipeline"},
        "entity_types": {"item", "warehouse", "inventory.receipt"},
        "keywords": ("موجودی", "کمبود", "کسری", "انبار", "تامین", "تأمین", "ورودی", "رزرو", "stock"),
    },
    "executive-brief": {
        "title": "بریف مدیریتی",
        "tools": {"trade_manager_brief", "company_snapshot", "financial_analysis_bundle"},
        "entity_types": {"company"},
        "keywords": ("مدیرعامل", "مدیریتی", "بریف", "اولویت", "مهم", "نگران", "امروز", "وضعیت شرکت", "چه چیزی", "چه چیز"),
    },
    "sales-fulfillment": {
        "title": "وضعیت تأمین و تحویل فروش",
        "tools": {"sales_fulfillment", "search_sales_documents", "inventory_position"},
        "entity_types": {"sales.document", "delivery"},
        "keywords": ("تحویل", "رزرو", "سفارش", "فروش", "تامین", "تأمین", "باقیمانده"),
    },
    "sales-margin": {
        "title": "حاشیه سود فروش",
        "tools": {"sales_margin_summary", "search_sales_documents"},
        "entity_types": {"sales.document", "delivery"},
        "keywords": ("حاشیه سود", "سود", "margin", "بهای تمام", "cogs"),
    },
    "explain-previous": {
        "title": "توضیح مبنای پاسخ قبلی",
        "tools": set(),
        "entity_types": set(),
        "keywords": ("بر چه اساس", "بر چه مبنا", "چطور حساب", "چگونه حساب", "منبع", "از کجا", "این عدد", "این نتیجه", "چرا این جواب"),
    },
    "crm-followup": {
        "title": "پیگیری‌های CRM",
        "tools": {"crm_followup_queue"},
        "entity_types": {"party.customer", "crm.activity"},
        "keywords": ("پیگیری", "سررسید", "تماس", "crm", "امروز", "عقب افتاده", "عقب‌افتاده"),
    },
    "crm-pipeline": {
        "title": "Pipeline فروش",
        "tools": {"crm_pipeline_summary"},
        "entity_types": {"crm.opportunity", "party.customer"},
        "keywords": ("pipeline", "پایپ", "فرصت فروش", "فرصت", "فروش احتمالی"),
    },
}

EXPLICIT_ALIASES = {
    "customer": "customer-review",
    "customer-review": "customer-review",
    "compare": "compare-customers",
    "compare-customers": "compare-customers",
    "supplier": "supplier-review",
    "supplier-review": "supplier-review",
    "compare-suppliers": "compare-suppliers",
    "trade-risk": "trade-risk",
    "shipment-risk": "trade-risk",
    "inventory-risk": "inventory-risk",
    "stock-risk": "inventory-risk",
    "executive-brief": "executive-brief",
    "brief": "executive-brief",
    "sales-fulfillment": "sales-fulfillment",
    "sales-margin": "sales-margin",
    "explain-previous": "explain-previous",
    "crm-followup": "crm-followup",
    "crm-pipeline": "crm-pipeline",
}

WRITE_WORDS = (
    "ثبت کن", "ایجاد کن", "بساز", "آماده کن", "رزرو کن", "تحویل کن",
    "پرداخت کن", "دریافت کن", "فاکتور بزن", "سند بزن", "اصلاح کن", "حذف کن",
)


def norm(value: Any) -> str:
    s = str(value or "").translate(DIGITS).replace("ي", "ی").replace("ك", "ک").replace("\u200c", " ")
    return re.sub(r"\s+", " ", s).strip().lower()


def stable(job_id: int, label: str, value: Any) -> str:
    raw = value if isinstance(value, str) else json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"), default=str)
    return f"job{job_id}-{label}-" + hashlib.sha256(str(raw).encode("utf-8")).hexdigest()[:16]


def rows(value: Any) -> list[dict[str, Any]]:
    if isinstance(value, dict) and isinstance(value.get("rows"), list):
        return [x for x in value["rows"] if isinstance(x, dict)]
    if isinstance(value, list):
        return [x for x in value if isinstance(x, dict)]
    return []


def context_entities(job: dict[str, Any], accepted: set[str] | None = None) -> list[dict[str, Any]]:
    ctx = job.get("context") if isinstance(job, dict) else None
    if not isinstance(ctx, dict):
        return []
    env = ctx.get("context_envelope")
    if not isinstance(env, dict) or env.get("version") != "v2" or env.get("validated") is not True:
        return []
    if int(env.get("company_id") or 0) != int(job.get("company_id") or 0):
        return []

    attached = env.get("attached_entities") if isinstance(env.get("attached_entities"), list) else []
    page_ctx = env.get("current_page")
    page = page_ctx.get("entities") if isinstance(page_ctx, dict) and isinstance(page_ctx.get("entities"), list) else []
    candidates = list(attached) + list(page)
    seen: set[tuple[str, int]] = set()
    out: list[dict[str, Any]] = []
    for entity in candidates:
        if not isinstance(entity, dict):
            continue
        typ = str(entity.get("type") or "")
        eid = int(entity.get("id") or 0)
        if not typ or eid <= 0 or (accepted is not None and typ not in accepted):
            continue
        key = (typ, eid)
        if key in seen:
            continue
        seen.add(key)
        out.append({
            "type": typ,
            "id": eid,
            "label": str(entity.get("label") or entity.get("name") or entity.get("code") or f"{typ}#{eid}"),
            "code": str(entity.get("code") or ""),
        })
    return out


def conversation_history(job: dict[str, Any]) -> list[dict[str, Any]]:
    ctx = job.get("context") if isinstance(job, dict) else None
    history = ctx.get("conversation_history") if isinstance(ctx, dict) else None
    if not isinstance(history, list):
        return []
    out: list[dict[str, Any]] = []
    for item in history[-3:]:
        if not isinstance(item, dict):
            continue
        out.append({
            "prompt": str(item.get("prompt") or "")[:500],
            "result_text": str(item.get("result_text") or "")[:1200],
            "mode": str(item.get("mode") or "")[:80],
            "tools_used": [str(x)[:80] for x in (item.get("tools_used") or []) if isinstance(x, str)][:16],
        })
    return out


def explicit_skill(prompt: str) -> str:
    m = re.search(r"(?:^|\s)/([a-z][a-z0-9-]{1,48})(?=\s|$)", str(prompt or ""), re.I)
    if not m:
        return ""
    return EXPLICIT_ALIASES.get(m.group(1).lower(), "")


def is_write_request(prompt: str) -> bool:
    n = norm(prompt)
    return any(word in n for word in WRITE_WORDS)


STRONG_INTENT_PHRASES = {
    "حاشیه سود", "بهای تمام", "landed cost", "وضعیت شرکت", "فرصت فروش",
    "تامین کننده", "تأمین کننده", "تامین‌کننده", "تأمین‌کننده",
}


def _keyword_score(text: str, keywords: tuple[str, ...]) -> int:
    """Score distinct intent phrases without duplicate substring inflation.

    Only an explicit allowlist of domain phrases receives strong weight. Generic
    conversational phrases (for example ``چه چیزی``) remain weak so ambiguous
    requests still reach the bounded capability-ID classifier.
    """
    matched = sorted(
        {norm(kw) for kw in keywords if norm(kw) and norm(kw) in text},
        key=len,
        reverse=True,
    )
    kept: list[str] = []
    for kw in matched:
        if any(kw in longer for longer in kept):
            continue
        kept.append(kw)
    strong = {norm(x) for x in STRONG_INTENT_PHRASES}
    return sum(4 if kw in strong else 2 for kw in kept)


def _score_capabilities(prompt: str, entities: list[dict[str, Any]]) -> dict[str, int]:
    n = norm(prompt)
    types = [str(x.get("type") or "") for x in entities]
    counts = {t: types.count(t) for t in set(types)}
    scores: dict[str, int] = {}
    for cid, cap in CAPABILITIES.items():
        score = _keyword_score(n, cap["keywords"])
        if set(types).intersection(cap["entity_types"]):
            score += 2
        if cid == "compare-customers":
            if counts.get("party.customer", 0) >= 2:
                score += 5
            else:
                score -= 2
        if cid == "compare-suppliers":
            if counts.get("party.supplier", 0) >= 2:
                score += 5
            else:
                score -= 2
        if cid == "customer-review" and counts.get("party.customer", 0) == 1:
            score += 3
        if cid == "supplier-review" and counts.get("party.supplier", 0) == 1:
            score += 3
        if cid == "trade-risk" and any(t in {"trade.case", "shipment"} for t in types):
            score += 3
        if cid == "inventory-risk" and any(t in {"item", "warehouse", "inventory.receipt"} for t in types):
            score += 2
        scores[cid] = score
    return scores


def lexical_retrieve(prompt: str, entities: list[dict[str, Any]]) -> list[str]:
    scores = _score_capabilities(prompt, entities)
    n = norm(prompt)
    if any(x in n for x in ("بر چه اساس", "بر چه مبنا", "چطور حساب", "چگونه حساب", "منبع این", "از کجا", "این عدد", "این نتیجه")):
        scores["explain-previous"] = max(scores.get("explain-previous", 0), 6)
    ranked = sorted(scores.items(), key=lambda x: (-x[1], x[0]))
    if not ranked or ranked[0][1] < 4:
        return []
    best = ranked[0][1]
    return [cid for cid, score in ranked if score >= max(4, best - 2)][:2]


def model_retrieve(worker: Any, job: dict[str, Any], prompt: str, entities: list[dict[str, Any]]) -> list[str]:
    ids = sorted(CAPABILITIES)
    entity_types = sorted({str(x.get("type") or "") for x in entities})
    system = (
        "You are a capability classifier for an ERP. Do not answer the user's business question. "
        "Choose at most two capability IDs that best match the Persian request. "
        "Never invent an ID outside the enum. If none fit, return an empty list."
    )
    descriptions = "\n".join(f"- {cid}: {CAPABILITIES[cid]['title']}" for cid in ids)
    history = conversation_history(job)
    history_hint = history[-1] if history else {}
    user = (
        f"CAPABILITIES:\n{descriptions}\n\nENTITY_TYPES:{json.dumps(entity_types, ensure_ascii=False)}"
        f"\nRECENT_CONVERSATION:{json.dumps(history_hint, ensure_ascii=False)}\nREQUEST:\n{prompt}"
    )
    schema = {
        "type": "object",
        "properties": {
            "capabilities": {
                "type": "array", "maxItems": 2,
                "items": {"type": "string", "enum": ids},
            }
        },
        "required": ["capabilities"],
        "additionalProperties": False,
    }
    try:
        response = worker.ollama_chat(
            job, 0,
            [{"role": "system", "content": system}, {"role": "user", "content": user}],
            [],
            fast=True,
            model=worker.model_for("fast"),
            num_ctx=1100,
            num_predict=80,
            temperature=0.0,
            timeout_seconds=45,
            response_format=schema,
            think_override=False,
        )
        content = str((response.get("message") or {}).get("content") or "").strip()
        data = json.loads(content)
        chosen = data.get("capabilities") if isinstance(data, dict) else []
        return [str(x) for x in chosen if str(x) in CAPABILITIES][:2]
    except Exception as exc:
        worker.trace(job, "capability_retrieval_fallback", "Fast capability classifier unavailable; deterministic routing retained", {
            "error_type": type(exc).__name__,
        })
        return []


def retrieve(worker: Any, job: dict[str, Any], prompt: str) -> tuple[list[str], str]:
    explicit = explicit_skill(prompt)
    if explicit:
        return [explicit], "explicit_slash"

    entities = context_entities(job)
    lexical = lexical_retrieve(prompt, entities)
    if lexical:
        return lexical, "deterministic_lexical"

    # Small local model only chooses capability IDs. It does not create tool arguments.
    if len(norm(prompt).split()) >= 3:
        selected = model_retrieve(worker, job, prompt, entities)
        if selected:
            return selected, "small_model_capability_id"
    return [], "none"


def _tool_names(tools_desc: list[dict[str, Any]]) -> set[str]:
    return {str(x.get("name") or "") for x in tools_desc if isinstance(x, dict) and x.get("name")}


def _looks_mutating_tool(name: str) -> bool:
    n = str(name or "").strip().lower()
    if not n:
        return False
    return n.startswith((
        "create_", "add_", "update_", "delete_", "approve_", "execute_",
        "reserve_", "deliver_", "post_", "cancel_", "revoke_",
    ))


def _read_only_descriptors(tools_desc: list[dict[str, Any]]) -> list[dict[str, Any]]:
    safe: list[dict[str, Any]] = []
    for item in tools_desc:
        if not isinstance(item, dict):
            continue
        name = str(item.get("name") or "")
        mode = str(item.get("mode") or "").strip().lower()
        # Production descriptors carry mode=read/proposal. The name guard is
        # defense-in-depth and also keeps tests/fallback descriptors fail-closed.
        if mode and mode != "read":
            continue
        if _looks_mutating_tool(name):
            continue
        safe.append(item)
    return safe


def _filter_tools(tools_desc: list[dict[str, Any]], selected: list[str]) -> list[dict[str, Any]]:
    read_only = _read_only_descriptors(tools_desc)
    if not selected:
        return read_only
    wanted: set[str] = set()
    for cid in selected:
        wanted.update(CAPABILITIES.get(cid, {}).get("tools") or set())
    filtered = [x for x in read_only if str(x.get("name") or "") in wanted]
    # A selected capability is an allowlist, not a hint. If its tools are not
    # available under the current permission/module scope, fail closed with an
    # empty descriptor set rather than broadening back to unrelated read tools.
    return filtered


def _money(value: Any) -> str:
    try:
        return f"{float(value or 0):,.0f} ریال"
    except (TypeError, ValueError):
        return "0 ریال"


def _num(value: Any) -> float:
    try:
        return float(value or 0)
    except (TypeError, ValueError):
        return 0.0


def _customer_facts(worker: Any, job: dict[str, Any], entity: dict[str, Any], tools: list[str]) -> dict[str, Any]:
    data = worker.tool(job, "crm_customer_360", {"party_id": int(entity["id"])}, stable(int(job["id"]), "skill-customer", entity["id"]))
    tools.append("crm_customer_360")
    data = data if isinstance(data, dict) else {}
    party = data.get("party") if isinstance(data.get("party"), dict) else {}
    financial = data.get("financial") if isinstance(data.get("financial"), dict) else {}
    crm = data.get("crm") if isinstance(data.get("crm"), dict) else {}
    return {
        "id": int(entity["id"]),
        "name": str(party.get("name") or entity.get("label") or "-"),
        "code": str(party.get("code") or entity.get("code") or ""),
        "balance": _num(financial.get("current_balance_irr")),
        "balance_nature": str(financial.get("balance_nature") or "-"),
        "sales": _num(financial.get("recorded_sales_net_irr")),
        "sales_docs": int(financial.get("sales_document_count") or 0),
        "outstanding_qty": _num(financial.get("outstanding_sales_quantity")),
        "pipeline": _num(crm.get("open_pipeline_irr")),
        "weighted_pipeline": _num(crm.get("weighted_pipeline_irr")),
        "next_followup": crm.get("next_followup") if isinstance(crm.get("next_followup"), dict) else None,
    }


def customer_review(worker: Any, job: dict[str, Any], entities: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
    customers = [x for x in entities if x["type"] == "party.customer"]
    if len(customers) != 1:
        return (
            "برای بررسی ۳۶۰ مشتری دقیقاً یک مشتری را با @ متصل کن.",
            {"provider": "deterministic", "model": "none", "mode": "crm_customer_review_blocked", "tools_used": [], "skill_id": "customer-review"},
        )
    tools: list[str] = []
    f = _customer_facts(worker, job, customers[0], tools)
    lines = [
        f"بررسی ۳۶۰ مشتری — {f['name']}",
        "Grounded / Read-only",
        "",
        f"• فروش ثبت‌شده: {_money(f['sales'])} در {f['sales_docs']} سند",
        f"• مانده طرف‌حساب: {_money(f['balance'])} | ماهیت: {f['balance_nature']}",
        f"• مقدار فروش تحویل‌نشده: {f['outstanding_qty']:g}",
        f"• Pipeline باز: {_money(f['pipeline'])} | وزنی: {_money(f['weighted_pipeline'])}",
    ]
    if f["next_followup"]:
        nxt = f["next_followup"]
        lines.append(f"• پیگیری بعدی: {nxt.get('due_date') or '-'} | {nxt.get('subject') or '-'}")
    else:
        lines.append("• پیگیری بعدی: ثبت نشده")

    lines += ["", "برداشت قابل اتکا:"]
    if f["outstanding_qty"] > 0:
        lines.append("• تعهد تحویل باز وجود دارد؛ وضعیت تأمین/تحویل این مشتری ارزش بررسی دارد.")
    if f["balance"] > 0 and "بدهکار" in f["balance_nature"]:
        lines.append("• مانده بدهکار ثبت شده است؛ پیگیری وصول می‌تواند در اولویت قرار گیرد.")
    if f["weighted_pipeline"] > 0:
        lines.append("• فرصت فروش باز وجود دارد؛ Pipeline وزنی برای برنامه پیگیری قابل استفاده است.")
    if len(lines) <= 8:
        lines.append("• از داده فعلی سیگنال فوری دیگری بدون تحلیل تکمیلی قابل استنباط نیست.")
    lines += ["", "شواهد: CRM Customer 360 ← Sales + Fulfillment + Party Ledger + CRM"]
    return "\n".join(lines), {
        "provider": "deterministic",
        "model": "none",
        "mode": "crm_customer_review_read",
        "tools_used": tools,
        "skill_id": "customer-review",
        "evidence": ["crm_customer_360"],
        "patch_version": PATCH_VERSION,
    }


def compare_customers(worker: Any, job: dict[str, Any], entities: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
    customers = [x for x in entities if x["type"] == "party.customer"]
    if len(customers) != 2:
        return (
            "برای مقایسه مشتری‌ها دقیقاً دو مشتری را با @ متصل کن.",
            {"provider": "deterministic", "model": "none", "mode": "crm_customer_compare_blocked", "tools_used": [], "skill_id": "compare-customers"},
        )
    tools: list[str] = []
    a = _customer_facts(worker, job, customers[0], tools)
    b = _customer_facts(worker, job, customers[1], tools)
    lines = [
        f"مقایسه مشتری‌ها — {a['name']} ↔ {b['name']}",
        "Grounded / Read-only",
        "",
        f"فروش ثبت‌شده: {_money(a['sales'])} ↔ {_money(b['sales'])}",
        f"تعداد اسناد فروش: {a['sales_docs']} ↔ {b['sales_docs']}",
        f"تحویل‌نشده: {a['outstanding_qty']:g} ↔ {b['outstanding_qty']:g}",
        f"مانده طرف‌حساب: {_money(a['balance'])} ({a['balance_nature']}) ↔ {_money(b['balance'])} ({b['balance_nature']})",
        f"Pipeline وزنی: {_money(a['weighted_pipeline'])} ↔ {_money(b['weighted_pipeline'])}",
        "",
        "برداشت مقایسه‌ای:",
    ]
    if abs(a["sales"] - b["sales"]) > 0.01:
        winner = a if a["sales"] > b["sales"] else b
        lines.append(f"• از نظر فروش ثبت‌شده، {winner['name']} مقدار بیشتری دارد.")
    if abs(a["weighted_pipeline"] - b["weighted_pipeline"]) > 0.01:
        winner = a if a["weighted_pipeline"] > b["weighted_pipeline"] else b
        lines.append(f"• از نظر Pipeline وزنی فعلی، {winner['name']} ظرفیت فروش باز بیشتری نشان می‌دهد.")
    debtor = [x for x in (a, b) if x["balance"] > 0 and "بدهکار" in x["balance_nature"]]
    if debtor:
        top = max(debtor, key=lambda x: x["balance"])
        lines.append(f"• بزرگ‌ترین مانده بدهکار بین این دو مربوط به {top['name']} است: {_money(top['balance'])}.")
    if a["outstanding_qty"] > 0 or b["outstanding_qty"] > 0:
        top = a if a["outstanding_qty"] >= b["outstanding_qty"] else b
        lines.append(f"• تعهد تحویل باز بیشتر از نظر مقدار فعلی مربوط به {top['name']} است.")
    lines += [
        "• «بهتر بودن کلی» بدون هدف تصمیم‌گیری (فروش، وصول، رشد یا ریسک تحویل) اعلام نمی‌شود.",
        "",
        "شواهد: دو خوانش مستقل CRM Customer 360؛ هیچ عددی توسط مدل ساخته نشده است.",
    ]
    return "\n".join(lines), {
        "provider": "deterministic",
        "model": "none",
        "mode": "crm_customer_compare_read",
        "tools_used": tools,
        "skill_id": "compare-customers",
        "evidence": ["crm_customer_360"],
        "patch_version": PATCH_VERSION,
    }


def _supplier_facts(worker: Any, job: dict[str, Any], entity: dict[str, Any], tools: list[str], risk_data: dict[str, Any] | None = None) -> dict[str, Any]:
    party_id = int(entity["id"])
    analytics = worker.tool(job, "document_analytics", {
        "kind": "purchases",
        "period": "rolling_jalali_months",
        "months": 6,
        "status_scope": "confirmed",
        "party_id": party_id,
        "group_by": "jalali_month",
        "limit": 6,
    }, stable(int(job["id"]), "supplier-purchases", party_id))
    tools.append("document_analytics")

    ledger = worker.tool(job, "party_ledger", {"party_id": party_id}, stable(int(job["id"]), "supplier-ledger", party_id))
    tools.append("party_ledger")

    label = str(entity.get("label") or entity.get("code") or "")
    cases = worker.tool(job, "search_trade_cases", {"query": label}, stable(int(job["id"]), "supplier-cases", label))
    tools.append("search_trade_cases")

    if risk_data is None:
        risk_data = worker.tool(job, "trade_risk_summary", {"limit": 50}, stable(int(job["id"]), "supplier-risk", "all"))
        tools.append("trade_risk_summary")

    analytics = analytics if isinstance(analytics, dict) else {}
    summary = analytics.get("summary") if isinstance(analytics.get("summary"), dict) else {}
    groups = analytics.get("groups") if isinstance(analytics.get("groups"), list) else []
    case_rows = rows(cases)
    risk_rows = rows(risk_data)
    case_nos = {str(x.get("case_no") or "") for x in case_rows if x.get("case_no")}
    matched_risks = [x for x in risk_rows if str(x.get("case_no") or "") in case_nos]

    trend_pct = None
    if len(groups) >= 2:
        prev = _num(groups[-2].get("net_total"))
        curr = _num(groups[-1].get("net_total"))
        if abs(prev) > 0.01:
            trend_pct = (curr - prev) / abs(prev) * 100.0

    max_delay = max((_num(x.get("delay_days")) for x in matched_risks), default=0.0)
    high_risk = sum(1 for x in matched_risks if str(x.get("risk_level") or "").lower() == "high")
    medium_risk = sum(1 for x in matched_risks if str(x.get("risk_level") or "").lower() == "medium")

    return {
        "id": party_id,
        "name": label or f"Supplier #{party_id}",
        "purchase_net_6m": _num(summary.get("net_total")),
        "purchase_docs_6m": int(summary.get("document_count") or 0),
        "ledger_balance": _num(ledger.get("balance") if isinstance(ledger, dict) else 0),
        "trade_case_count": len(case_rows),
        "high_risk_cases": high_risk,
        "medium_risk_cases": medium_risk,
        "max_delay_days": max_delay,
        "trend_pct": trend_pct,
        "months": groups,
    }


def _supplier_lines(f: dict[str, Any]) -> list[str]:
    lines = [
        f"• خرید قطعی ۶ ماه اخیر: {_money(f['purchase_net_6m'])} در {f['purchase_docs_6m']} سند",
        f"• مانده دفتر طرف‌حساب (بدهکار-بستانکار): {_money(f['ledger_balance'])}",
        f"• پرونده بازرگانی پیدا شده: {f['trade_case_count']}",
        f"• ریسک پرونده‌ها: high={f['high_risk_cases']} | medium={f['medium_risk_cases']} | بیشترین تأخیر ثبت‌شده={f['max_delay_days']:g} روز",
    ]
    if f["trend_pct"] is not None:
        lines.append(f"• تغییر خرید قطعی آخرین ماه داده نسبت به ماه قبل: {f['trend_pct']:+.1f}%")
    else:
        lines.append("• برای مقایسه ماه‌به‌ماه، داده کافی در دو ماه متوالی موجود نیست.")
    return lines


def supplier_review(worker: Any, job: dict[str, Any], entities: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
    suppliers = [x for x in entities if x["type"] == "party.supplier"]
    if len(suppliers) != 1:
        return (
            "برای بررسی تأمین‌کننده دقیقاً یک تأمین‌کننده را با @ متصل کن.",
            {"provider": "deterministic", "model": "none", "mode": "procurement_supplier_review_blocked", "tools_used": [], "skill_id": "supplier-review"},
        )
    tools: list[str] = []
    f = _supplier_facts(worker, job, suppliers[0], tools)
    lines = [f"بررسی عملکرد تأمین‌کننده — {f['name']}", "Grounded / Read-only", ""] + _supplier_lines(f) + ["", "برداشت قابل اتکا:"]
    if f["high_risk_cases"] > 0:
        lines.append("• حداقل یک پرونده بازرگانی با ریسک بالا ثبت شده؛ بررسی همان پرونده قبل از سفارش/تعهد جدید اولویت دارد.")
    elif f["medium_risk_cases"] > 0:
        lines.append("• ریسک متوسط در پرونده‌های مرتبط دیده می‌شود؛ علت تأخیر/گمرک/هزینه باید در پرونده بررسی شود.")
    if f["trend_pct"] is not None and abs(f["trend_pct"]) >= 25:
        lines.append("• حجم خرید ماه اخیر تغییر قابل توجهی نسبت به ماه قبل دارد؛ علت این تغییر ارزش بررسی دارد.")
    if f["trade_case_count"] == 0:
        lines.append("• پرونده بازرگانی مرتبط پیدا نشد؛ ارزیابی لجستیکی این تأمین‌کننده با داده فعلی محدود است.")
    lines += ["• امتیاز کلی تأمین‌کننده بدون KPIهای زمان تحویل، کیفیت/مرجوعی و شرایط پرداخت ساخته نمی‌شود.", "", "شواهد: Purchase Analytics + Party Ledger + Trade Cases + Trade Risk."]
    return "\n".join(lines), {
        "provider": "deterministic",
        "model": "none",
        "mode": "procurement_supplier_review",
        "tools_used": tools,
        "skill_id": "supplier-review",
        "evidence": ["document_analytics", "party_ledger", "search_trade_cases", "trade_risk_summary"],
        "patch_version": PATCH_VERSION,
    }


def compare_suppliers(worker: Any, job: dict[str, Any], entities: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
    suppliers = [x for x in entities if x["type"] == "party.supplier"]
    if len(suppliers) != 2:
        return (
            "برای مقایسه تأمین‌کننده‌ها دقیقاً دو تأمین‌کننده را با @ متصل کن.",
            {"provider": "deterministic", "model": "none", "mode": "procurement_supplier_compare_blocked", "tools_used": [], "skill_id": "compare-suppliers"},
        )
    tools: list[str] = []
    risk_data = worker.tool(job, "trade_risk_summary", {"limit": 50}, stable(int(job["id"]), "supplier-compare-risk", "all"))
    tools.append("trade_risk_summary")
    a = _supplier_facts(worker, job, suppliers[0], tools, risk_data)
    b = _supplier_facts(worker, job, suppliers[1], tools, risk_data)
    lines = [
        f"مقایسه تأمین‌کننده‌ها — {a['name']} ↔ {b['name']}",
        "Grounded / Read-only",
        "",
        f"خرید قطعی ۶ ماهه: {_money(a['purchase_net_6m'])} ↔ {_money(b['purchase_net_6m'])}",
        f"تعداد اسناد خرید: {a['purchase_docs_6m']} ↔ {b['purchase_docs_6m']}",
        f"پرونده بازرگانی: {a['trade_case_count']} ↔ {b['trade_case_count']}",
        f"پرونده high risk: {a['high_risk_cases']} ↔ {b['high_risk_cases']}",
        f"بیشترین تأخیر ثبت‌شده: {a['max_delay_days']:g} روز ↔ {b['max_delay_days']:g} روز",
        f"مانده دفتر طرف‌حساب: {_money(a['ledger_balance'])} ↔ {_money(b['ledger_balance'])}",
        "",
        "برداشت مقایسه‌ای:",
    ]
    if a["high_risk_cases"] != b["high_risk_cases"]:
        safer = a if a["high_risk_cases"] < b["high_risk_cases"] else b
        lines.append(f"• از نظر تعداد پرونده high-risk ثبت‌شده در داده فعلی، {safer['name']} وضعیت بهتری دارد.")
    if abs(a["max_delay_days"] - b["max_delay_days"]) > 0.01:
        lower = a if a["max_delay_days"] < b["max_delay_days"] else b
        lines.append(f"• بیشترین تأخیر ثبت‌شده برای {lower['name']} کمتر است.")
    if abs(a["purchase_net_6m"] - b["purchase_net_6m"]) > 0.01:
        larger = a if a["purchase_net_6m"] > b["purchase_net_6m"] else b
        lines.append(f"• حجم خرید قطعی ۶ ماهه با {larger['name']} بیشتر بوده است.")
    lines += [
        "• «بهترین تأمین‌کننده» بدون KPI کیفیت، مرجوعی، شرایط پرداخت و SLA اعلام نمی‌شود.",
        "",
        "شواهد: Purchase Analytics + Party Ledger + Trade Cases + Trade Risk؛ اعداد از ERP خوانده شده‌اند.",
    ]
    return "\n".join(lines), {
        "provider": "deterministic",
        "model": "none",
        "mode": "procurement_supplier_compare",
        "tools_used": tools,
        "skill_id": "compare-suppliers",
        "evidence": ["document_analytics", "party_ledger", "search_trade_cases", "trade_risk_summary"],
        "patch_version": PATCH_VERSION,
    }


def trade_risk(worker: Any, job: dict[str, Any], entities: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
    cases = [x for x in entities if x["type"] == "trade.case"]
    available_tools: list[str] = []
    if len(cases) == 1:
        eid = int(cases[0]["id"])
        snapshot = worker.tool(job, "trade_case_snapshot", {"case_id": eid}, stable(int(job["id"]), "skill-trade-snapshot", eid))
        available_tools.append("trade_case_snapshot")
        landed = worker.tool(job, "landed_cost_summary", {"case_id": eid}, stable(int(job["id"]), "skill-trade-landed", eid))
        available_tools.append("landed_cost_summary")
        risk = worker.tool(job, "trade_risk_summary", {"limit": 50}, stable(int(job["id"]), "skill-trade-risk", "all"))
        available_tools.append("trade_risk_summary")
        snapshot = snapshot if isinstance(snapshot, dict) else {}
        landed = landed if isinstance(landed, dict) else {}
        case = snapshot.get("case") if isinstance(snapshot.get("case"), dict) else {}
        shipments = snapshot.get("shipments") if isinstance(snapshot.get("shipments"), list) else []
        ship = shipments[0] if shipments and isinstance(shipments[0], dict) else {}
        case_no = str(case.get("case_no") or cases[0].get("code") or cases[0].get("label") or f"#{eid}")
        rr = next((x for x in rows(risk) if str(x.get("case_no") or "") == case_no), {})
        lines = [
            f"ریسک پرونده بازرگانی — {case_no}",
            "Grounded / Read-only",
            "",
            f"• تأمین‌کننده: {case.get('supplier_name') or '-'} | Incoterm: {case.get('incoterm') or '-'}",
            f"• وضعیت پرونده: {case.get('status') or '-'} | گمرک/ترخیص: {case.get('clearance_status') or '-'}",
            f"• حمل: {ship.get('mode') or '-'} | وضعیت: {ship.get('status') or '-'} | ETA: {ship.get('eta') or '-'}",
            f"• سطح ریسک: {rr.get('risk_level') or '-'} | تأخیر ثبت‌شده: {_num(rr.get('delay_days')):g} روز",
            f"• خرید پایه: {_money(landed.get('purchase_base_irr'))}",
            f"• هزینه برآوردی افزوده: {_money(landed.get('estimated_additional_irr'))}",
            f"• هزینه واقعی ثبت‌شده تا این لحظه: {_money(landed.get('actual_additional_recorded_irr'))}",
            f"• Projected Landed Cost: {_money(landed.get('projected_landed_total_irr'))}",
            "",
            "اقدام بعدی:",
        ]
        level = str(rr.get("risk_level") or "").lower()
        if level == "high":
            lines.append("• این پرونده در داده فعلی high-risk است؛ علت ریسک (تاخیر/گمرک/هزینه) قبل از تعهد جدید بررسی شود.")
        elif level == "medium":
            lines.append("• ریسک متوسط ثبت شده؛ ETA، وضعیت ترخیص و هزینه‌های واقعی را نزدیک‌تر پایش کن.")
        else:
            lines.append("• ریسک بالا از خلاصه فعلی استخراج نشد؛ پایش ETA/ترخیص/Landed Cost ادامه پیدا کند.")
        lines += ["", "شواهد: Trade Case Snapshot + Landed Cost + Trade Risk Summary."]
        return "\n".join(lines), {
            "provider": "deterministic", "model": "none", "mode": "trade_case_risk_review",
            "tools_used": available_tools, "skill_id": "trade-risk",
            "evidence": available_tools, "patch_version": PATCH_VERSION,
        }

    # Reuse the proven deterministic global trade risk route.
    text, meta = TL.process_risk(worker, job)
    meta = dict(meta)
    meta["skill_id"] = "trade-risk"
    meta["patch_version"] = PATCH_VERSION
    return text, meta


def inventory_risk(worker: Any, job: dict[str, Any]) -> tuple[str, dict[str, Any]]:
    text, meta = IP.process_replenishment(worker, job)
    meta = dict(meta)
    meta["skill_id"] = "inventory-risk"
    meta["patch_version"] = PATCH_VERSION
    return text, meta


def executive_brief(worker: Any, job: dict[str, Any]) -> tuple[str, dict[str, Any]]:
    text, meta = SF.process_brief(worker, job)
    meta = dict(meta)
    meta["skill_id"] = "executive-brief"
    meta["patch_version"] = PATCH_VERSION
    return text, meta


TOOL_LABELS = {
    "company_snapshot": "خلاصه شرکت",
    "financial_analysis_bundle": "بسته تحلیل مالی",
    "crm_customer_360": "نمای ۳۶۰ مشتری",
    "document_analytics": "تحلیل اسناد خرید/فروش",
    "party_ledger": "گردش طرف‌حساب",
    "trade_case_snapshot": "نمای پرونده بازرگانی",
    "landed_cost_summary": "محاسبه Landed Cost",
    "trade_risk_summary": "خلاصه ریسک بازرگانی",
    "replenishment_risk": "ریسک کمبود موجودی",
    "trade_manager_brief": "بریف بین‌ماژولی مدیر",
    "sales_fulfillment": "وضعیت تأمین و تحویل فروش",
    "sales_margin_summary": "حاشیه سود فروش",
}


def explain_previous(job: dict[str, Any]) -> tuple[str, dict[str, Any]]:
    history = conversation_history(job)
    if not history:
        return (
            "برای توضیح مبنای پاسخ، هنوز پاسخ قبلی قابل اتکایی در این گفت‌وگو ندارم.",
            {"provider": "deterministic", "model": "none", "mode": "grounded_conversation_explain_blocked", "tools_used": [], "skill_id": "explain-previous"},
        )
    last = history[-1]
    tools = list(last.get("tools_used") or [])
    tool_labels = [TOOL_LABELS.get(x, x) for x in tools]
    lines = [
        "مبنای پاسخ قبلی",
        "Grounded / Conversation-aware",
        "",
        f"• نوع مسیر قبلی: {last.get('mode') or 'ثبت نشده'}",
        "• منابع خوانده‌شده از ERP: " + ("، ".join(tool_labels) if tool_labels else "در metadata پاسخ قبلی Tool مشخصی ثبت نشده"),
        "• پرسش قبلی: " + (last.get("prompt") or "-"),
        "",
        "توضیح:",
        "• اعداد پاسخ قبلی از نتیجه همان Toolهای ERP آمده‌اند؛ مدل اجازه ساخت شناسه یا عدد تجاری جدید ندارد.",
        "• اگر داده ERP بعد از آن پاسخ تغییر کرده باشد، برای تصمیم عملی باید دوباره خوانش تازه انجام شود.",
    ]
    preview = str(last.get("result_text") or "").strip().splitlines()
    if preview:
        lines += ["", "خلاصه پاسخ قبلی برای ارجاع:", "• " + " | ".join(x.strip() for x in preview[:3] if x.strip())[:900]]
    lines += ["", "اگر منظورت مبنای یک عدد مشخص است، همان عدد یا شاخص را نام ببر تا مسیر داده‌اش را دقیق‌تر باز کنم."]
    return "\\n".join(lines), {
        "provider": "deterministic",
        "model": "none",
        "mode": "grounded_conversation_explain_read",
        "tools_used": [],
        "skill_id": "explain-previous",
        "evidence": tools,
        "patch_version": PATCH_VERSION,
    }


def _direct_skill(worker: Any, job: dict[str, Any], tools_desc: list[dict[str, Any]], selected: list[str]) -> tuple[str, dict[str, Any]] | None:
    if not selected:
        return None
    available = _tool_names(tools_desc)
    entities = context_entities(job)
    first = selected[0]

    if first == "explain-previous":
        return explain_previous(job)
    if first == "compare-customers" and {"crm_customer_360"}.issubset(available):
        return compare_customers(worker, job, entities)
    if first == "customer-review" and {"crm_customer_360"}.issubset(available):
        return customer_review(worker, job, entities)
    if first == "compare-suppliers" and CAPABILITIES[first]["tools"].issubset(available):
        return compare_suppliers(worker, job, entities)
    if first == "supplier-review" and CAPABILITIES[first]["tools"].issubset(available):
        return supplier_review(worker, job, entities)
    if first == "trade-risk" and {"trade_risk_summary"}.issubset(available):
        # Specific trade-case review requires all three; otherwise proven global route still works.
        if any(x["type"] == "trade.case" for x in entities) and {"trade_case_snapshot", "landed_cost_summary"}.issubset(available):
            return trade_risk(worker, job, entities)
        return trade_risk(worker, job, [])
    if first == "inventory-risk" and {"replenishment_risk"}.issubset(available):
        return inventory_risk(worker, job)
    if first == "executive-brief" and {"trade_manager_brief"}.issubset(available):
        return executive_brief(worker, job)
    return None


def install_business_skills(worker_cls: type) -> None:
    if getattr(worker_cls, "_business_skills_v1_installed", False):
        return
    original = worker_cls.process_agent

    def patched(self: Any, job: dict[str, Any], tools_desc: list[dict[str, Any]]):
        prompt = str(job.get("prompt") or "")

        # Existing guarded write routes stay authoritative and receive the full descriptor set.
        if is_write_request(prompt):
            return original(self, job, tools_desc)

        selected, source = retrieve(self, job, prompt)
        self.trace(job, "capability_retrieval", "Business capabilities retrieved", {
            "selected": selected,
            "source": source,
            "available_tools": len(tools_desc),
        })

        direct = _direct_skill(self, job, tools_desc, selected)
        if direct is not None:
            text, meta = direct
            meta = dict(meta)
            meta["capability_retrieval"] = {"selected": selected, "source": source}
            return text, meta

        narrowed = _filter_tools(tools_desc, selected)
        text, meta = original(self, job, narrowed)
        meta = dict(meta)
        meta["capability_retrieval"] = {
            "selected": selected,
            "source": source,
            "descriptor_count_before": len(tools_desc),
            "descriptor_count_after": len(narrowed),
        }
        return text, meta

    worker_cls.process_agent = patched
    worker_cls._business_skills_v1_installed = True
    worker_cls._business_skills_v1_original_process_agent = original
