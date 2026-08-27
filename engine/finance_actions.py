#!/usr/bin/env python3
"""ERPSMART v10 Finance/Treasury action depth.

Adds guarded purchase-invoice proposal, guarded cheque proposal, and deterministic
cheque analytics. Current ERP IDs are always resolved through server tools.
No mutation bypasses Proposal/Human Approval.
"""
from __future__ import annotations

import hashlib
import json
import re
import time
from typing import Any

PATCH_VERSION = "v10.0-finance-actions-r1"
_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")


class FinanceActionError(RuntimeError):
    pass


class FinanceActionBlocked(RuntimeError):
    pass


def norm(value: Any) -> str:
    text = str(value or "").translate(_DIGITS).replace("ي", "ی").replace("ك", "ک").replace("\u200c", " ")
    return re.sub(r"\s+", " ", text).strip().lower()


def _number_token(value: Any) -> str:
    s = str(value or "").translate(_DIGITS).replace(",", "").replace("٬", "").strip()
    return s


def _fmt_rial(value: Any) -> str:
    try:
        return f"{float(value):,.0f} ریال"
    except Exception:
        return "نامشخص"


def _rows(value: Any) -> list[dict[str, Any]]:
    if isinstance(value, list):
        return [x for x in value if isinstance(x, dict)]
    if isinstance(value, dict):
        for key in ("rows", "items", "results", "data"):
            if isinstance(value.get(key), list):
                return [x for x in value[key] if isinstance(x, dict)]
    return []


def _resolve_unique(rows: list[dict[str, Any]], query: str, keys: tuple[str, ...]) -> tuple[dict[str, Any] | None, str, list[dict[str, Any]]]:
    nq = norm(query)
    exact: list[dict[str, Any]] = []
    contains: list[dict[str, Any]] = []
    for row in rows:
        values = [norm(row.get(k)) for k in keys if row.get(k) not in (None, "")]
        if any(v == nq for v in values):
            exact.append(row)
        elif any(v and (nq in v or v in nq) for v in values):
            contains.append(row)
    pool = exact or contains
    unique: dict[int, dict[str, Any]] = {}
    for row in pool:
        try:
            rid = int(row.get("id") or 0)
        except Exception:
            rid = 0
        if rid > 0:
            unique[rid] = row
    if len(unique) == 1:
        return next(iter(unique.values())), "", list(unique.values())
    return None, ("not_found" if not unique else "ambiguous"), list(unique.values())


def _choices(rows: list[dict[str, Any]]) -> str:
    values = []
    for row in rows[:8]:
        label = " ".join(str(row.get(k) or "").strip() for k in ("code", "name") if str(row.get(k) or "").strip())
        if label:
            values.append(label)
    return "، ".join(values)


def _stable_call_id(job_id: int, action: str, payload: Any) -> str:
    raw = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":"), default=str)
    return f"job{job_id}-{action}-" + hashlib.sha256(raw.encode("utf-8")).hexdigest()[:20]


def _quoted_after(text: str, labels: tuple[str, ...]) -> str:
    joined = "|".join(re.escape(x) for x in labels)
    m = re.search(rf"(?:{joined})\s*[«\"']\s*([^»\"'\r\n]{{2,190}}?)\s*[»\"']", str(text or ""), flags=re.I)
    return m.group(1).strip() if m else ""


def _extract_amount(prompt: str) -> float | None:
    text = str(prompt or "").translate(_DIGITS)
    patterns = (
        r"(?:مبلغ|به مبلغ|مبلغ کل)\s*([\d,٬]+(?:\.\d+)?)\s*(?:ریال)?",
        r"([\d,٬]+(?:\.\d+)?)\s*ریال",
    )
    for pat in patterns:
        m = re.search(pat, text, flags=re.I)
        if m:
            try:
                return float(_number_token(m.group(1)))
            except Exception:
                pass
    return None


def _extract_date(prompt: str) -> str:
    text = str(prompt or "").translate(_DIGITS)
    m = re.search(r"(?<!\d)((?:13|14)\d{2}[/-]\d{1,2}[/-]\d{1,2}|20\d{2}-\d{1,2}-\d{1,2})(?!\d)", text)
    return m.group(1) if m else ""


def _grounded_substring(value: str, prompt: str) -> bool:
    return bool(value) and norm(value) in norm(prompt)


def _numeric_grounded(prompt: str, value: float) -> bool:
    text = str(prompt or "").translate(_DIGITS)
    tokens = re.findall(r"(?<!\d)(\d[\d,٬]*(?:\.\d+)?)(?!\d)", text)
    for token in tokens:
        try:
            if abs(float(_number_token(token)) - float(value)) < 1e-9:
                return True
        except Exception:
            continue
    return False


def is_purchase_create_request(prompt: str) -> bool:
    p = norm(prompt)
    if not p:
        return False
    has_purchase = "خرید" in p or "purchase" in p
    has_doc = "فاکتور" in p or "invoice" in p
    has_action = any(x in p for x in ("بساز", "ایجاد", "آماده", "پیش نویس", "پیش‌نویس", "draft", "create"))
    return has_purchase and has_doc and has_action


def is_check_create_request(prompt: str) -> bool:
    p = norm(prompt)
    if not p or ("چک" not in p and "check" not in p):
        return False
    return any(x in p for x in ("ثبت", "بساز", "ایجاد", "آماده", "پیشنهاد", "پیش نویس", "پیش‌نویس", "create", "draft"))


def is_check_read_request(prompt: str) -> bool:
    p = norm(prompt)
    if not p or ("چک" not in p and "check" not in p):
        return False
    if is_check_create_request(prompt):
        return False
    return any(x in p for x in ("وضعیت", "سررسید", "باز", "برگشتی", "دریافتنی", "پرداختنی", "لیست", "گزارش", "کدام", "چه چک"))


def _purchase_deterministic(prompt: str) -> dict[str, Any] | None:
    text = str(prompt or "").translate(_DIGITS)
    party = _quoted_after(text, ("تامین کننده", "تأمین کننده", "فروشنده", "طرف حساب", "طرف‌حساب"))
    if not party:
        return None

    line_pattern = re.compile(
        r"(?m)^\s*(\d+(?:\.\d+)?)\s*(?:عدد|واحد|تا)?\s+([^\r\n]+?)\s*"
        r"\r?\n\s*با\s+قیمت\s+واحد\s+([\d,٬]+(?:\.\d+)?)\s*ریال\b",
        flags=re.I,
    )
    rows = list(line_pattern.finditer(text))
    if not rows:
        return None

    lines = []
    for row in rows:
        quantity = float(row.group(1))
        unit_price = float(_number_token(row.group(3)))
        if quantity.is_integer():
            quantity = int(quantity)
        if unit_price.is_integer():
            unit_price = int(unit_price)
        lines.append({
            "item_query": row.group(2).strip(" \t.;،"),
            "quantity": quantity,
            "unit_price": unit_price,
        })
    return {"party_query": party, "lines": lines, "_parser_source": "deterministic"}


def _parse_json_object(raw: Any) -> dict[str, Any]:
    text = str(raw or "").strip()
    a, b = text.find("{"), text.rfind("}")
    if a < 0 or b < a:
        raise FinanceActionError("json_missing")
    value = json.loads(text[a:b + 1])
    if not isinstance(value, dict):
        raise FinanceActionError("json_root_invalid")
    return value


def _purchase_llm(worker: Any, job: dict[str, Any], prompt: str) -> tuple[dict[str, Any], dict[str, Any], str]:
    model = worker.model_for("agent")
    system = (
        "Return ONLY JSON for a purchase invoice draft request. Never output ERP ids. "
        "Every party_query/item_query and every numeric quantity/unit_price must come from the user text. "
        "Schema: {\"party_query\":\"...\",\"lines\":[{\"item_query\":\"...\",\"quantity\":1,\"unit_price\":1000}]}."
    )
    worker.trace(job, "guarded_route", "Purchase invoice request requires constrained parsing", {"model": model, "started_epoch": time.time()})
    response = worker.ollama_chat(
        job, 0, [{"role": "system", "content": system}, {"role": "user", "content": prompt}], [],
        fast=True, model=model, num_ctx=1024, num_predict=160, temperature=0.0, timeout_seconds=90,
        response_format={"type": "object", "properties": {}}, think_override=False,
    )
    parsed = _parse_json_object((response.get("message") or {}).get("content"))
    parsed["_parser_source"] = "llm"
    return parsed, dict(response.get("_metrics") or {}), model


def _validate_purchase_spec(prompt: str, spec: dict[str, Any]) -> dict[str, Any]:
    if any(str(k).endswith("_id") for k in spec):
        raise FinanceActionError("purchase_identifier_forbidden")
    party = str(spec.get("party_query") or "").strip()
    lines = spec.get("lines")
    if not party or not _grounded_substring(party, prompt):
        raise FinanceActionError("purchase_party_not_grounded")
    if not isinstance(lines, list) or not (1 <= len(lines) <= 20):
        raise FinanceActionError("purchase_lines_required")
    out = []
    for row in lines:
        if not isinstance(row, dict):
            raise FinanceActionError("purchase_line_invalid")
        item = str(row.get("item_query") or "").strip()
        if not item or not _grounded_substring(item, prompt):
            raise FinanceActionError("purchase_item_not_grounded")
        try:
            qty = float(row.get("quantity"))
            price = float(row.get("unit_price"))
        except Exception as exc:
            raise FinanceActionError("purchase_numbers_required") from exc
        if qty <= 0 or price < 0:
            raise FinanceActionError("purchase_numbers_invalid")
        if not _numeric_grounded(prompt, qty):
            raise FinanceActionError("purchase_quantity_not_grounded")
        if not _numeric_grounded(prompt, price):
            raise FinanceActionError("purchase_price_not_grounded")
        discount = max(0.0, float(row.get("discount_amount") or 0))
        if discount and not _numeric_grounded(prompt, discount):
            raise FinanceActionError("purchase_discount_not_grounded")
        if discount > qty * price + 0.01:
            raise FinanceActionError("purchase_discount_invalid")
        if qty.is_integer(): qty = int(qty)
        if price.is_integer(): price = int(price)
        if discount.is_integer(): discount = int(discount)
        out.append({"item_query": item, "quantity": qty, "unit_price": price, "discount_amount": discount})
    return {"party_query": party, "lines": out, "_parser_source": str(spec.get("_parser_source") or "llm")}


def _check_deterministic(prompt: str) -> dict[str, Any] | None:
    p = norm(prompt)
    direction = "receivable" if "دریافتنی" in p else ("payable" if "پرداختنی" in p else "")
    if not direction:
        return None
    text = str(prompt or "").translate(_DIGITS)
    m = re.search(r"(?:شماره\s*چک|چک\s*شماره)\s*[«\"']?\s*([A-Za-z0-9\-/]{2,100})", text, flags=re.I)
    check_no = m.group(1).strip() if m else ""
    amount = _extract_amount(text)
    due = _extract_date(text)
    party = _quoted_after(text, ("مشتری", "تامین کننده", "تأمین کننده", "طرف حساب", "طرف‌حساب"))
    cash = _quoted_after(text, ("بانک", "صندوق", "حساب بانکی", "کارتخوان"))
    if not check_no or amount is None or amount <= 0 or not due:
        return None
    return {
        "direction": direction,
        "check_no": check_no,
        "amount": amount,
        "due_date": due,
        "party_query": party,
        "cash_query": cash,
        "_parser_source": "deterministic",
    }


def _check_llm(worker: Any, job: dict[str, Any], prompt: str) -> tuple[dict[str, Any], dict[str, Any], str]:
    model = worker.model_for("agent")
    system = (
        "Return ONLY JSON for creating a cheque record. Never output ERP ids. Copy textual identifiers and date from the user. "
        "direction must be receivable or payable and must be explicit in the prompt. "
        "Schema: {\"direction\":\"receivable\",\"check_no\":\"...\",\"amount\":1000,\"due_date\":\"1405/06/20\",\"party_query\":\"\",\"cash_query\":\"\"}."
    )
    worker.trace(job, "guarded_route", "Cheque request requires constrained parsing", {"model": model, "started_epoch": time.time()})
    response = worker.ollama_chat(
        job, 0, [{"role": "system", "content": system}, {"role": "user", "content": prompt}], [],
        fast=True, model=model, num_ctx=896, num_predict=120, temperature=0.0, timeout_seconds=90,
        response_format={"type": "object", "properties": {}}, think_override=False,
    )
    parsed = _parse_json_object((response.get("message") or {}).get("content"))
    parsed["_parser_source"] = "llm"
    return parsed, dict(response.get("_metrics") or {}), model


def _validate_check_spec(prompt: str, spec: dict[str, Any]) -> dict[str, Any]:
    if any(str(k).endswith("_id") for k in spec):
        raise FinanceActionError("check_identifier_forbidden")
    direction = str(spec.get("direction") or "").strip().lower()
    if direction not in {"receivable", "payable"}:
        raise FinanceActionError("check_direction_required")
    p = norm(prompt)
    if direction == "receivable" and "دریافتنی" not in p and "receivable" not in p:
        raise FinanceActionError("check_direction_not_grounded")
    if direction == "payable" and "پرداختنی" not in p and "payable" not in p:
        raise FinanceActionError("check_direction_not_grounded")
    check_no = str(spec.get("check_no") or "").strip()
    if len(check_no) < 2 or norm(check_no) not in p:
        raise FinanceActionError("check_no_not_grounded")
    try:
        amount = float(spec.get("amount"))
    except Exception as exc:
        raise FinanceActionError("check_amount_required") from exc
    if amount <= 0:
        raise FinanceActionError("check_amount_invalid")
    if not _numeric_grounded(prompt, amount):
        raise FinanceActionError("check_amount_not_grounded")
    due = str(spec.get("due_date") or "").strip()
    if not due or norm(due) not in p:
        raise FinanceActionError("check_due_date_not_grounded")
    party = str(spec.get("party_query") or "").strip()
    cash = str(spec.get("cash_query") or "").strip()
    if party and not _grounded_substring(party, prompt):
        raise FinanceActionError("check_party_not_grounded")
    if cash and not _grounded_substring(cash, prompt):
        raise FinanceActionError("check_cash_not_grounded")
    if amount.is_integer(): amount = int(amount)
    return {
        "direction": direction, "check_no": check_no, "amount": amount, "due_date": due,
        "party_query": party, "cash_query": cash, "_parser_source": str(spec.get("_parser_source") or "llm"),
    }


def _blocked(worker: Any, job: dict[str, Any], text: str, tools: list[str], mode: str, model: str = "none", metrics: dict[str, Any] | None = None) -> tuple[str, dict[str, Any]]:
    worker.trace(job, "action_blocked", text)
    return text, {
        "provider": "deterministic_block",
        "model": model,
        "mode": mode,
        "tools_used": tools,
        "metrics": metrics or {},
    }


def process_purchase(worker: Any, job: dict[str, Any], prompt: str, tools_desc: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
    required = {"search_parties", "search_items", "create_purchase_invoice_draft"}
    available = {str(x.get("name") or "") for x in tools_desc if isinstance(x, dict)}
    missing = sorted(required - available)
    if missing:
        return _blocked(worker, job, "ابزارهای لازم برای فاکتور خرید هنوز روی Control Plane فعال نیست: " + ", ".join(missing), [], "guarded_purchase_invoice_blocked")

    tools_used: list[str] = []
    metrics: dict[str, Any] = {}
    model = "none"
    worker.trace(job, "guarded_route", "Purchase invoice creation -> guarded proposal workflow")
    spec = _purchase_deterministic(prompt)
    if spec is None:
        try:
            spec, metrics, model = _purchase_llm(worker, job, prompt)
        except Exception:
            return _blocked(worker, job, "درخواست فاکتور خرید را متوجه شدم، اما تأمین‌کننده، اقلام، تعداد و قیمت‌ها به‌صورت قابل اتکا استخراج نشد؛ هیچ Proposal ساخته نشد.", tools_used, "guarded_purchase_invoice_blocked", model, metrics)
    try:
        grounded = _validate_purchase_spec(prompt, spec)
    except Exception as exc:
        return _blocked(worker, job, "اطلاعات فاکتور خرید به متن درخواست قابل انتساب نبود؛ هیچ Proposal ساخته نشد.", tools_used, "guarded_purchase_invoice_blocked", model, metrics)

    q = grounded["party_query"]
    worker.trace(job, "tool_call", "Resolving supplier through search_parties", {"query": q})
    result = worker.tool(job, "search_parties", {"query": q}, _stable_call_id(int(job["id"]), "purchase-party", q))
    tools_used.append("search_parties")
    party, reason, candidates = _resolve_unique(_rows(result), q, ("name", "code", "national_id", "mobile"))
    worker.trace(job, "tool_result", "search_parties returned", {"resolved": bool(party)})
    if not party:
        suffix = (" گزینه‌ها: " + _choices(candidates)) if reason == "ambiguous" else ""
        return _blocked(worker, job, f"تأمین‌کننده «{q}» به‌صورت یکتا پیدا نشد؛ هیچ Proposal ساخته نشد.{suffix}", tools_used, "guarded_purchase_invoice_blocked", model, metrics)
    if norm(party.get("party_type")) in {"customer", "مشتری"}:
        return _blocked(worker, job, f"طرف حساب «{party.get('name') or q}» فقط مشتری است و برای خرید قابل استفاده نیست؛ هیچ Proposal ساخته نشد.", tools_used, "guarded_purchase_invoice_blocked", model, metrics)
    party_id = int(party["id"])

    resolved: list[dict[str, Any]] = []
    item_kinds: set[str] = set()
    display: list[str] = []
    for idx, line in enumerate(grounded["lines"], start=1):
        iq = str(line["item_query"])
        worker.trace(job, "tool_call", "Resolving purchase item through search_items", {"line": idx, "query": iq})
        rr = worker.tool(job, "search_items", {"query": iq}, _stable_call_id(int(job["id"]), f"purchase-item-{idx}", iq))
        tools_used.append("search_items")
        item, reason, candidates = _resolve_unique(_rows(rr), iq, ("name", "code", "barcode"))
        worker.trace(job, "tool_result", "search_items returned", {"line": idx, "resolved": bool(item)})
        if not item:
            suffix = (" گزینه‌ها: " + _choices(candidates)) if reason == "ambiguous" else ""
            return _blocked(worker, job, f"کالا/خدمت ردیف {idx} «{iq}» یکتا پیدا نشد؛ هیچ Proposal ساخته نشد.{suffix}", tools_used, "guarded_purchase_invoice_blocked", model, metrics)
        item_id = int(item["id"])
        kind = "service" if norm(item.get("item_type")) in {"service", "خدمت", "services"} else "goods"
        item_kinds.add(kind)
        resolved.append({
            "item_id": item_id,
            "quantity": line["quantity"],
            "unit_price": line["unit_price"],
            "discount_amount": line.get("discount_amount", 0),
            "description": str(item.get("name") or iq),
        })
        display.append(f"• {line['quantity']} × {item.get('name') or iq} با قیمت واحد {int(line['unit_price']):,} ریال")

    if len(item_kinds) > 1:
        return _blocked(worker, job, "فاکتور خرید شامل کالا و خدمت مخلوط است؛ برای کنترل نوع سند، آن‌ها را در دو درخواست جداگانه ثبت کن. هیچ Proposal ساخته نشد.", tools_used, "guarded_purchase_invoice_blocked", model, metrics)
    doc_type = "purchase_invoice_service" if item_kinds == {"service"} else "purchase_invoice_goods"
    args = {"party_id": party_id, "doc_type": doc_type, "lines": resolved}
    worker.trace(job, "proposal_request", "Creating server-side purchase invoice proposal", {"party_id_source": "search_parties", "item_count": len(resolved), "human_approval_required": True})
    pr = worker.tool(job, "create_purchase_invoice_draft", args, _stable_call_id(int(job["id"]), "purchase-proposal", args))
    tools_used.append("create_purchase_invoice_draft")
    if not isinstance(pr, dict) or int(pr.get("proposal_id") or 0) <= 0 or str(pr.get("status") or "") != "awaiting_human_approval":
        return _blocked(worker, job, "Control Plane ایجاد Proposal فاکتور خرید را تأیید نکرد؛ هیچ عملیات مالی اجرا نشده است.", tools_used, "guarded_purchase_invoice_blocked", model, metrics)
    pid = int(pr["proposal_id"])
    worker.trace(job, "proposal_created", "Purchase invoice proposal created; awaiting human approval", {"proposal_id": pid, "human_approval_required": True})
    return (
        f"Proposal #{pid} برای فاکتور خرید آماده شد؛ هنوز هیچ سند خریدی ثبت نشده است.\n"
        f"تأمین‌کننده: {party.get('name') or q}\n" + "\n".join(display) + "\nبرای ایجاد پیش‌نویس واقعی، Proposal را در پنل تأیید کنید.",
        {"provider": "guarded_tool_orchestrator", "model": model if grounded["_parser_source"] == "llm" else "none", "mode": "guarded_purchase_invoice_proposal", "tools_used": tools_used, "proposal_id": pid, "proposal_status": "awaiting_human_approval", "awaiting_human_approval": True, "metrics": metrics, "parser_source": grounded["_parser_source"]},
    )


def process_check_create(worker: Any, job: dict[str, Any], prompt: str, tools_desc: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
    required = {"create_check"}
    available = {str(x.get("name") or "") for x in tools_desc if isinstance(x, dict)}
    if not required.issubset(available):
        return _blocked(worker, job, "Tool ثبت چک هنوز روی Control Plane فعال نیست؛ هیچ Proposal ساخته نشد.", [], "guarded_check_blocked")
    tools_used: list[str] = []
    metrics: dict[str, Any] = {}
    model = "none"
    worker.trace(job, "guarded_route", "Cheque creation -> guarded proposal workflow")
    spec = _check_deterministic(prompt)
    if spec is None:
        try:
            spec, metrics, model = _check_llm(worker, job, prompt)
        except Exception:
            return _blocked(worker, job, "برای ثبت چک باید دریافتنی/پرداختنی، شماره چک، مبلغ و تاریخ سررسید صریح باشد؛ هیچ Proposal ساخته نشد.", tools_used, "guarded_check_blocked", model, metrics)
    try:
        grounded = _validate_check_spec(prompt, spec)
    except Exception:
        return _blocked(worker, job, "مشخصات چک به متن درخواست قابل انتساب نبود؛ هیچ Proposal ساخته نشد.", tools_used, "guarded_check_blocked", model, metrics)

    party_id = None
    party_name = ""
    if grounded["party_query"]:
        if "search_parties" not in available:
            return _blocked(worker, job, "برای Resolve طرف حساب Tool جستجوی طرف حساب در دسترس نیست.", tools_used, "guarded_check_blocked", model, metrics)
        q = grounded["party_query"]
        rr = worker.tool(job, "search_parties", {"query": q}, _stable_call_id(int(job["id"]), "check-party", q))
        tools_used.append("search_parties")
        party, reason, candidates = _resolve_unique(_rows(rr), q, ("name", "code", "national_id", "mobile"))
        if not party:
            suffix = (" گزینه‌ها: " + _choices(candidates)) if reason == "ambiguous" else ""
            return _blocked(worker, job, f"طرف حساب «{q}» یکتا پیدا نشد؛ هیچ Proposal ساخته نشد.{suffix}", tools_used, "guarded_check_blocked", model, metrics)
        party_id = int(party["id"]); party_name = str(party.get("name") or q)

    cash_id = None
    cash_name = ""
    if grounded["cash_query"]:
        if "search_cash_accounts" not in available:
            return _blocked(worker, job, "برای Resolve بانک/صندوق Tool مربوطه در دسترس نیست.", tools_used, "guarded_check_blocked", model, metrics)
        q = grounded["cash_query"]
        rr = worker.tool(job, "search_cash_accounts", {"query": q}, _stable_call_id(int(job["id"]), "check-cash", q))
        tools_used.append("search_cash_accounts")
        cash, reason, candidates = _resolve_unique(_rows(rr), q, ("name", "code", "bank_name", "account_no", "iban"))
        if not cash:
            suffix = (" گزینه‌ها: " + _choices(candidates)) if reason == "ambiguous" else ""
            return _blocked(worker, job, f"بانک/صندوق «{q}» یکتا پیدا نشد؛ هیچ Proposal ساخته نشد.{suffix}", tools_used, "guarded_check_blocked", model, metrics)
        cash_id = int(cash["id"]); cash_name = str(cash.get("name") or q)

    args: dict[str, Any] = {
        "direction": grounded["direction"], "check_no": grounded["check_no"], "amount": grounded["amount"], "due_date": grounded["due_date"]
    }
    if party_id: args["party_id"] = party_id
    if cash_id: args["cash_account_id"] = cash_id
    worker.trace(job, "proposal_request", "Creating server-side cheque proposal", {"direction": grounded["direction"], "human_approval_required": True})
    pr = worker.tool(job, "create_check", args, _stable_call_id(int(job["id"]), "check-proposal", args))
    tools_used.append("create_check")
    if not isinstance(pr, dict) or int(pr.get("proposal_id") or 0) <= 0 or str(pr.get("status") or "") != "awaiting_human_approval":
        return _blocked(worker, job, "Control Plane ایجاد Proposal چک را تأیید نکرد؛ هیچ رکوردی ایجاد نشده است.", tools_used, "guarded_check_blocked", model, metrics)
    pid = int(pr["proposal_id"])
    worker.trace(job, "proposal_created", "Cheque proposal created; awaiting human approval", {"proposal_id": pid, "human_approval_required": True})
    direction_fa = "دریافتنی" if grounded["direction"] == "receivable" else "پرداختنی"
    extra = (f" • طرف حساب: {party_name}" if party_name else "") + (f" • بانک/صندوق: {cash_name}" if cash_name else "")
    return (
        f"Proposal #{pid} برای چک {direction_fa} شماره {grounded['check_no']} به مبلغ {_fmt_rial(grounded['amount'])} با سررسید {grounded['due_date']} آماده شد{extra}. هیچ رکوردی تا قبل از تأیید انسانی ایجاد نمی‌شود.",
        {"provider": "guarded_tool_orchestrator", "model": model if grounded["_parser_source"] == "llm" else "none", "mode": "guarded_check_proposal", "tools_used": tools_used, "proposal_id": pid, "proposal_status": "awaiting_human_approval", "awaiting_human_approval": True, "metrics": metrics, "parser_source": grounded["_parser_source"]},
    )


def _check_read_args(prompt: str) -> dict[str, Any]:
    p = norm(prompt)
    direction = "receivable" if "دریافتنی" in p else ("payable" if "پرداختنی" in p else "all")
    status = "all"
    if "برگشت" in p: status = "bounced"
    elif "وصول" in p: status = "received"
    elif "پرداخت شده" in p or "پرداخت‌شده" in p: status = "paid"
    elif "باز" in p: status = "open"
    elif "باطل" in p: status = "canceled"
    due_scope = "all"
    if any(x in p for x in ("سررسید گذشته", "معوق", "گذشته از سررسید", "overdue")):
        due_scope = "overdue"
    elif any(x in p for x in ("این هفته", "هفت روز", "7 روز", "هفته آینده", "هفته آتی")):
        due_scope = "upcoming_7"
    return {"direction": direction, "status": status, "due_scope": due_scope, "limit": 30}


def process_check_read(worker: Any, job: dict[str, Any], prompt: str, tools_desc: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
    available = {str(x.get("name") or "") for x in tools_desc if isinstance(x, dict)}
    if "check_analytics" not in available:
        return _blocked(worker, job, "Tool تحلیل چک‌ها هنوز روی Control Plane فعال نیست.", [], "treasury_check_read_blocked")
    args = _check_read_args(prompt)
    worker.trace(job, "grounded_read", "Grounded cheque analytics", {"due_scope": args["due_scope"], "status": args["status"], "direction": args["direction"]})
    result = worker.tool(job, "check_analytics", args, _stable_call_id(int(job["id"]), "check-read", args))
    rows = _rows(result)
    total_count = int((result or {}).get("total_count") or len(rows)) if isinstance(result, dict) else len(rows)
    total_amount = float((result or {}).get("total_amount") or 0) if isinstance(result, dict) else 0
    lines = [f"چک‌ها: {total_count} مورد • جمع مبلغ: {_fmt_rial(total_amount)}"]
    for row in rows[:20]:
        direction_fa = "دریافتنی" if str(row.get("direction")) == "receivable" else "پرداختنی"
        lines.append(f"• {row.get('check_no') or '-'} | {direction_fa} | سررسید {row.get('due_date_fa') or row.get('due_date') or '-'} | {_fmt_rial(row.get('amount'))} | {row.get('party_name') or '-'} | {row.get('status') or '-'}")
    worker.trace(job, "grounded_read_complete", "Grounded cheque analytics completed", {"rows": len(rows)})
    return "\n".join(lines), {"provider": "deterministic", "model": "none", "mode": "treasury_check_read", "tools_used": ["check_analytics"], "rounds": 0}


def install_finance_actions(worker_cls: type) -> None:
    if getattr(worker_cls, "_finance_actions_v1_installed", False):
        return
    original = worker_cls.process_agent

    def patched(self: Any, job: dict[str, Any], tools_desc: list[dict[str, Any]]):
        prompt = str(job.get("prompt") or "")
        if is_purchase_create_request(prompt):
            return process_purchase(self, job, prompt, tools_desc)
        if is_check_create_request(prompt):
            return process_check_create(self, job, prompt, tools_desc)
        if is_check_read_request(prompt):
            return process_check_read(self, job, prompt, tools_desc)
        return original(self, job, tools_desc)

    worker_cls.process_agent = patched
    worker_cls._finance_actions_v1_installed = True
    worker_cls._finance_actions_v1_original_process_agent = original
