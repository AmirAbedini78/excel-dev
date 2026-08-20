#!/usr/bin/env python3
"""ERPSMART v8.3.0 guarded sales-invoice orchestrator.

The LLM may understand language, but it is never trusted for ERP identifiers or
for claiming that a mutation happened.

For sales-invoice creation requests:
1) Parse user intent into an ID-free spec.
2) Ground every customer/item phrase and every financial number in the prompt.
3) Resolve party/item IDs only through server read tools.
4) Construct proposal arguments deterministically.
5) Call create_sales_invoice_draft and require a real proposal_id.
6) Stop at human approval. Nothing is posted/finalized here.
"""
from __future__ import annotations

import hashlib
import json
import re
import time
from typing import Any

PATCH_VERSION = "v8.3.0"

_DIGIT_TRANS = str.maketrans(
    "۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩",
    "01234567890123456789",
)
_NUMBER_WORDS = {
    0: ("صفر",),
    1: ("یک", "يک"),
    2: ("دو",),
    3: ("سه",),
    4: ("چهار",),
    5: ("پنج",),
    6: ("شش",),
    7: ("هفت",),
    8: ("هشت",),
    9: ("نه",),
    10: ("ده",),
}


def normalize_text(value: Any) -> str:
    text = str(value or "").translate(_DIGIT_TRANS)
    text = text.replace("ي", "ی").replace("ك", "ک").replace("\u200c", " ")
    text = re.sub(r"\s+", " ", text).strip().lower()
    return text


def normalize_number_text(value: Any) -> str:
    text = str(value or "").translate(_DIGIT_TRANS)
    return text.replace(",", "").replace("٬", "").replace(" ", "")


def is_sales_invoice_create_request(prompt: str) -> bool:
    p = normalize_text(prompt)
    has_invoice = "فاکتور" in p or "invoice" in p
    has_sales_context = (
        "فروش" in p
        or "sales" in p
        or "مشتری" in p
        or "customer" in p
    )
    has_action = any(term in p for term in (
        "بساز", "ایجاد", "آماده", "پیش نویس", "draft", "create",
    ))
    purchase_only = "فاکتور خرید" in p or "purchase invoice" in p
    return has_invoice and has_sales_context and has_action and not purchase_only


def _numeric_token_grounded(text: str, value: float | int) -> bool:
    normalized = str(text or "").translate(_DIGIT_TRANS)
    compact = normalized.replace(",", "").replace("٬", "")
    number = float(value)
    if abs(number - round(number)) < 1e-9:
        token = str(int(round(number)))
        if re.search(rf"(?<!\d){re.escape(token)}(?!\d)", compact):
            return True
        if 0 <= int(round(number)) <= 10:
            p = normalize_text(normalized)
            for word in _NUMBER_WORDS.get(int(round(number)), ()):
                if re.search(rf"(?<!\w){re.escape(normalize_text(word))}(?!\w)", p):
                    return True
    else:
        token = ("%f" % number).rstrip("0").rstrip(".")
        if re.search(rf"(?<!\d){re.escape(token)}(?!\d)", compact):
            return True
    return False


def _exact_phrase_positions(prompt: str, phrases: list[str]) -> dict[str, int]:
    p = normalize_text(prompt)
    out: dict[str, int] = {}
    for phrase in phrases:
        q = normalize_text(phrase)
        if not q or q in out:
            continue
        idx = p.find(q)
        if idx < 0 or p.find(q, idx + len(q)) >= 0:
            # Missing or repeated phrase is ambiguous for financial grounding.
            raise ValueError("phrase_not_uniquely_grounded:" + phrase)
        out[q] = idx
    return out


def _item_segments(prompt: str, item_queries: list[str]) -> dict[str, str]:
    normalized_prompt = normalize_text(prompt)
    positions = _exact_phrase_positions(prompt, item_queries)
    ordered = sorted((pos, q) for q, pos in positions.items())
    segments: dict[str, str] = {}
    for i, (pos, q) in enumerate(ordered):
        prev_end = 0
        if i > 0:
            prev_pos, prev_q = ordered[i - 1]
            prev_end = prev_pos + len(prev_q)
        next_pos = len(normalized_prompt)
        if i + 1 < len(ordered):
            next_pos = ordered[i + 1][0]
        # Quantity often precedes the item phrase, while price follows it.
        start = max(prev_end, pos - 80)
        segments[q] = normalized_prompt[start:next_pos]
    return segments


def deterministic_parse(prompt: str) -> dict[str, Any] | None:
    text = str(prompt or "").translate(_DIGIT_TRANS)

    party_match = re.search(
        r"(?:مشتری|customer)\s*[«\"']\s*([^»\"'\r\n]+?)\s*[»\"']",
        text,
        flags=re.IGNORECASE,
    )
    if not party_match:
        return None
    party_query = party_match.group(1).strip()

    line_pattern = re.compile(
        r"(?m)^\s*(\d+(?:\.\d+)?)\s*(?:عدد|واحد|تا)?\s+([^\r\n]+?)\s*"
        r"\r?\n\s*با\s+قیمت\s+واحد\s+([\d,٬]+(?:\.\d+)?)\s*ریال\b",
        flags=re.IGNORECASE,
    )
    rows = list(line_pattern.finditer(text))
    if not rows:
        return None

    tax_match = re.search(
        r"مالیات(?:\s+هر\s+ردیف|\s+همه\s+اقلام|\s+اقلام)?\s*"
        r"(\d+(?:\.\d+)?)\s*(?:درصد|%)",
        text,
        flags=re.IGNORECASE,
    )
    global_tax = float(tax_match.group(1)) if tax_match else None

    lines: list[dict[str, Any]] = []
    for row in rows:
        quantity = float(row.group(1))
        if quantity.is_integer():
            quantity = int(quantity)
        item_query = row.group(2).strip(" \t.;،")
        price_raw = normalize_number_text(row.group(3))
        unit_price = float(price_raw)
        if unit_price.is_integer():
            unit_price = int(unit_price)
        lines.append({
            "item_query": item_query,
            "quantity": quantity,
            "unit_price": unit_price,
            "tax_percent": global_tax,
        })

    return {
        "party_query": party_query,
        "lines": lines,
        "_parser_source": "deterministic",
    }


def extract_json_object(text: Any) -> dict[str, Any]:
    raw = str(text or "").strip()
    if raw.startswith("```"):
        raw = re.sub(r"^```(?:json)?\s*", "", raw, flags=re.IGNORECASE)
        raw = re.sub(r"\s*```$", "", raw)
    start = raw.find("{")
    end = raw.rfind("}")
    if start < 0 or end < start:
        raise ValueError("parser_json_object_missing")
    value = json.loads(raw[start:end + 1])
    if not isinstance(value, dict):
        raise ValueError("parser_json_root_not_object")
    return value


def _contains_forbidden_ids(value: Any) -> bool:
    if isinstance(value, dict):
        for key, item in value.items():
            if str(key).lower() in {
                "party_id", "item_id", "customer_id", "company_id",
                "account_id", "voucher_id", "invoice_id",
            }:
                return True
            if _contains_forbidden_ids(item):
                return True
    elif isinstance(value, list):
        return any(_contains_forbidden_ids(x) for x in value)
    return False


def validate_and_ground_spec(prompt: str, spec: dict[str, Any]) -> dict[str, Any]:
    if _contains_forbidden_ids(spec):
        raise ValueError("llm_identifier_forbidden")

    party_query = str(spec.get("party_query") or "").strip()
    lines_raw = spec.get("lines")
    if not party_query:
        raise ValueError("party_query_required")
    if not isinstance(lines_raw, list) or not (1 <= len(lines_raw) <= 20):
        raise ValueError("invoice_lines_required")

    normalized_prompt = normalize_text(prompt)
    if normalize_text(party_query) not in normalized_prompt:
        raise ValueError("party_query_not_grounded_in_prompt")

    lines: list[dict[str, Any]] = []
    item_queries: list[str] = []
    for raw in lines_raw:
        if not isinstance(raw, dict):
            raise ValueError("invoice_line_not_object")
        item_query = str(raw.get("item_query") or "").strip()
        if not item_query:
            raise ValueError("item_query_required")
        if normalize_text(item_query) not in normalized_prompt:
            raise ValueError("item_query_not_grounded_in_prompt")
        if item_query in item_queries:
            raise ValueError("duplicate_item_query_ambiguous")
        item_queries.append(item_query)

    segments = _item_segments(prompt, item_queries)
    prompt_has_tax_instruction = "مالیات" in normalized_prompt or "tax" in normalized_prompt

    for raw, item_query in zip(lines_raw, item_queries):
        try:
            quantity = float(raw.get("quantity"))
            unit_price = float(raw.get("unit_price"))
        except Exception as exc:
            raise ValueError("quantity_and_unit_price_required") from exc

        if not (quantity > 0 and unit_price > 0):
            raise ValueError("quantity_and_unit_price_must_be_positive")

        qnorm = normalize_text(item_query)
        segment = segments[qnorm]

        if not _numeric_token_grounded(segment, quantity):
            raise ValueError("quantity_not_grounded_near_item:" + item_query)
        if not _numeric_token_grounded(segment, unit_price):
            raise ValueError("unit_price_not_grounded_near_item:" + item_query)

        tax_raw = raw.get("tax_percent")
        tax_percent: float | int | None
        if tax_raw is None or tax_raw == "":
            tax_percent = None
            if prompt_has_tax_instruction:
                raise ValueError("tax_percent_missing")
        else:
            tax_percent = float(tax_raw)
            if not (0 <= tax_percent <= 100):
                raise ValueError("tax_percent_out_of_range")
            if tax_percent == 0 and "بدون مالیات" in normalized_prompt:
                pass
            elif not _numeric_token_grounded(prompt, tax_percent):
                raise ValueError("tax_percent_not_grounded_in_prompt")

        if quantity.is_integer():
            quantity = int(quantity)
        if unit_price.is_integer():
            unit_price = int(unit_price)
        if isinstance(tax_percent, float) and tax_percent.is_integer():
            tax_percent = int(tax_percent)

        lines.append({
            "item_query": item_query,
            "quantity": quantity,
            "unit_price": unit_price,
            "tax_percent": tax_percent,
        })

    return {
        "party_query": party_query,
        "lines": lines,
        "_parser_source": str(spec.get("_parser_source") or "llm"),
    }


def parse_with_llm(worker: Any, job: dict[str, Any], prompt: str) -> tuple[dict[str, Any], dict[str, Any], str]:
    model = worker.model_for("agent")
    system = (
        "You are a strict ERP intent parser. Return ONLY one JSON object, no markdown and no explanation. "
        "Never output any database identifier such as party_id or item_id. "
        "Copy party_query and every item_query verbatim from the user's prompt. "
        "Use only quantities, unit prices and tax percentages explicitly stated by the user; never infer a price. "
        "If a required value is missing, use null instead of guessing. "
        "Schema: {\"party_query\":\"exact customer phrase\",\"lines\":["
        "{\"item_query\":\"exact item phrase\",\"quantity\":number|null,"
        "\"unit_price\":number|null,\"tax_percent\":number|null}]}. "
        "Do not create dates, due dates, discounts, IDs, document numbers or notes."
    )
    messages = [
        {"role": "system", "content": system},
        {"role": "user", "content": prompt},
    ]
    worker.trace(job, "invoice_parse", f"Parsing invoice intent with {model}", {
        "model": model,
        "started_epoch": time.time(),
    })
    response = worker.ollama_chat(
        job, 0, messages, [], fast=True, model=model,
        num_ctx=int(worker.cfg.get("invoice_parse_num_ctx", 1536)),
        num_predict=int(worker.cfg.get("invoice_parse_num_predict", 220)),
        temperature=0.0,
        timeout_seconds=int(worker.cfg.get("invoice_parse_timeout_seconds", 120)),
    )
    content = str((response.get("message") or {}).get("content") or "")
    spec = extract_json_object(content)
    spec["_parser_source"] = "llm"
    return spec, dict(response.get("_metrics") or {}), model


def _rows(result: Any) -> list[dict[str, Any]]:
    if isinstance(result, list):
        return [x for x in result if isinstance(x, dict)]
    if isinstance(result, dict):
        for key in ("rows", "items", "results", "data"):
            value = result.get(key)
            if isinstance(value, list):
                return [x for x in value if isinstance(x, dict)]
    return []


def _resolve_unique(rows: list[dict[str, Any]], query: str, fields: tuple[str, ...]) -> tuple[dict[str, Any] | None, str]:
    q = normalize_text(query)
    exact: list[dict[str, Any]] = []
    contains: list[dict[str, Any]] = []

    for row in rows:
        values = [normalize_text(row.get(field)) for field in fields if row.get(field) not in (None, "")]
        if any(value == q for value in values):
            exact.append(row)
            continue
        name = normalize_text(row.get("name"))
        if name and (q in name or name in q):
            contains.append(row)

    candidates = exact or contains
    # Deduplicate by server-provided ID.
    unique: dict[int, dict[str, Any]] = {}
    for row in candidates:
        try:
            rid = int(row.get("id"))
        except Exception:
            continue
        if rid > 0:
            unique[rid] = row

    if len(unique) == 1:
        return next(iter(unique.values())), ""
    if not unique:
        return None, "not_found"
    return None, "ambiguous"


def _safe_names(rows: list[dict[str, Any]], limit: int = 5) -> str:
    names = []
    for row in rows:
        name = str(row.get("name") or "").strip()
        if name and name not in names:
            names.append(name)
        if len(names) >= limit:
            break
    return "، ".join(names)


def _call_id(job_id: Any, label: str, value: str = "") -> str:
    digest = hashlib.sha256((label + "|" + value).encode("utf-8")).hexdigest()[:16]
    return f"job{job_id}-guard-{label}-{digest}"


def _blocked(worker: Any, job: dict[str, Any], message: str, tools_used: list[str],
             parser_source: str, model: str, metrics: dict[str, Any], reason: str) -> tuple[str, dict[str, Any]]:
    worker.trace(job, "proposal_blocked", "Sales invoice proposal blocked safely", {
        "reason": reason,
        "tools_used": tools_used,
    })
    with worker.progress_lock:
        trace_copy = list(worker.current_trace[-50:])
    return message, {
        "provider": "guarded_tool_orchestrator",
        "model": model if parser_source == "llm" else "none",
        "attempted_model": model if parser_source == "llm" else "none",
        "mode": "guarded_sales_invoice_blocked",
        "tools_used": tools_used,
        "rounds": 1 if parser_source == "llm" else 0,
        "parser_source": parser_source,
        "blocked_reason": reason,
        "metrics": metrics,
        "trace": trace_copy,
        "patch_version": PATCH_VERSION,
    }


def process_sales_invoice_request(worker: Any, job: dict[str, Any], tools_desc: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
    prompt = str(job.get("prompt") or "")
    tools_used: list[str] = []
    metrics: dict[str, Any] = {}
    model = "none"

    worker.trace(job, "guarded_route", "Sales invoice creation -> guarded proposal workflow")

    spec = deterministic_parse(prompt)
    if spec is None:
        try:
            spec, metrics, model = parse_with_llm(worker, job, prompt)
        except Exception as exc:
            return _blocked(
                worker, job,
                "درخواست ساخت فاکتور را متوجه شدم، اما نتوانستم اقلام و قیمت‌ها را با اطمینان از متن استخراج کنم. "
                "لطفاً نام مشتری، نام هر کالا، تعداد و قیمت واحد را صریح بنویسید؛ هیچ پیش‌نویسی ساخته نشد.",
                tools_used, "llm", model, metrics,
                "intent_parse_failed:" + type(exc).__name__,
            )

    parser_source = str(spec.get("_parser_source") or "llm")
    try:
        grounded = validate_and_ground_spec(prompt, spec)
    except Exception as exc:
        return _blocked(
            worker, job,
            "اطلاعات فاکتور به‌طور کامل به متن درخواست قابل انتساب نبود؛ برای جلوگیری از ساخت شناسه یا عدد فرضی، "
            "هیچ پیش‌نویسی ساخته نشد. نام مشتری، کالا، تعداد، قیمت واحد و در صورت نیاز مالیات را صریح وارد کنید.",
            tools_used, parser_source, model, metrics,
            "grounding_failed:" + str(exc),
        )

    party_query = grounded["party_query"]
    worker.trace(job, "tool_call", "Resolving customer through search_parties", {"query": party_query})
    party_result = worker.tool(
        job, "search_parties", {"query": party_query},
        _call_id(job.get("id"), "party", party_query),
    )
    tools_used.append("search_parties")
    party_rows = _rows(party_result)
    party, party_reason = _resolve_unique(
        party_rows, party_query, ("name", "code", "national_id", "mobile")
    )
    worker.trace(job, "tool_result", "search_parties returned", {
        "rows": len(party_rows),
        "resolved": bool(party),
    })

    if party is None:
        suffix = ""
        names = _safe_names(party_rows)
        if party_reason == "ambiguous" and names:
            suffix = " نتایج نزدیک: " + names
        return _blocked(
            worker, job,
            f"مشتری «{party_query}» در شرکت انتخاب‌شده به‌صورت یکتا پیدا نشد؛ هیچ پیش‌نویسی ساخته نشد.{suffix}",
            tools_used, parser_source, model, metrics,
            "party_" + party_reason,
        )

    party_type = normalize_text(party.get("party_type"))
    if party_type in {"supplier", "تامین کننده", "تأمین کننده"}:
        return _blocked(
            worker, job,
            f"طرف حساب «{party.get('name') or party_query}» فقط به‌عنوان تأمین‌کننده ثبت شده است؛ "
            "برای فاکتور فروش هیچ پیش‌نویسی ساخته نشد.",
            tools_used, parser_source, model, metrics,
            "party_supplier_only",
        )

    try:
        party_id = int(party["id"])
    except Exception:
        party_id = 0
    if party_id <= 0:
        return _blocked(
            worker, job,
            "شناسه معتبر مشتری از Tool دریافت نشد؛ هیچ پیش‌نویسی ساخته نشد.",
            tools_used, parser_source, model, metrics,
            "party_server_id_invalid",
        )

    resolved_lines: list[dict[str, Any]] = []
    display_lines: list[str] = []

    for index, line in enumerate(grounded["lines"], start=1):
        item_query = str(line["item_query"])
        worker.trace(job, "tool_call", "Resolving invoice item through search_items", {
            "line": index,
            "query": item_query,
        })
        item_result = worker.tool(
            job, "search_items", {"query": item_query},
            _call_id(job.get("id"), f"item{index}", item_query),
        )
        tools_used.append("search_items")
        item_rows = _rows(item_result)
        item, item_reason = _resolve_unique(item_rows, item_query, ("name", "code", "barcode"))
        worker.trace(job, "tool_result", "search_items returned", {
            "line": index,
            "rows": len(item_rows),
            "resolved": bool(item),
        })

        if item is None:
            names = _safe_names(item_rows)
            suffix = (" نتایج نزدیک: " + names) if item_reason == "ambiguous" and names else ""
            return _blocked(
                worker, job,
                f"کالای ردیف {index} «{item_query}» در شرکت انتخاب‌شده به‌صورت یکتا پیدا نشد؛ "
                f"هیچ پیش‌نویسی ساخته نشد.{suffix}",
                tools_used, parser_source, model, metrics,
                "item_" + item_reason,
            )

        try:
            item_id = int(item["id"])
        except Exception:
            item_id = 0
        if item_id <= 0:
            return _blocked(
                worker, job,
                f"شناسه معتبر برای کالای «{item_query}» از Tool دریافت نشد؛ هیچ پیش‌نویسی ساخته نشد.",
                tools_used, parser_source, model, metrics,
                "item_server_id_invalid",
            )

        proposal_line: dict[str, Any] = {
            "item_id": item_id,
            "quantity": line["quantity"],
            "unit_price": line["unit_price"],
            "description": str(item.get("name") or item_query),
        }
        if line.get("tax_percent") is not None:
            proposal_line["tax_percent"] = line["tax_percent"]
        resolved_lines.append(proposal_line)

        tax_text = ""
        if line.get("tax_percent") is not None:
            tax_text = f" • مالیات {line['tax_percent']}٪"
        display_lines.append(
            f"• {line['quantity']} × {item.get('name') or item_query} "
            f"با قیمت واحد {int(line['unit_price']):,} ریال{tax_text}"
        )

    proposal_args = {
        "party_id": party_id,
        "lines": resolved_lines,
    }

    worker.trace(job, "proposal_request", "Creating server-side sales invoice proposal", {
        "party_id_source": "search_parties",
        "item_ids_source": "search_items",
        "line_count": len(resolved_lines),
        "human_approval_required": True,
    })
    proposal_result = worker.tool(
        job,
        "create_sales_invoice_draft",
        proposal_args,
        _call_id(job.get("id"), "sales-invoice-proposal", str(party_id)),
    )
    tools_used.append("create_sales_invoice_draft")

    if not isinstance(proposal_result, dict):
        return _blocked(
            worker, job,
            "سرور ایجاد Proposal معتبر را تأیید نکرد؛ هیچ عملیات نهایی انجام نشده است.",
            tools_used, parser_source, model, metrics,
            "proposal_result_invalid",
        )
    try:
        proposal_id = int(proposal_result.get("proposal_id"))
    except Exception:
        proposal_id = 0
    status = str(proposal_result.get("status") or "")
    if proposal_id <= 0 or status != "awaiting_human_approval":
        return _blocked(
            worker, job,
            "سرور شناسه Proposal در وضعیت انتظار برای تأیید انسانی برنگرداند؛ "
            "Agent اجازه اعلام ایجاد پیش‌نویس را ندارد.",
            tools_used, parser_source, model, metrics,
            "proposal_server_confirmation_missing",
        )

    worker.trace(job, "proposal_created", "Sales invoice proposal created; awaiting human approval", {
        "proposal_id": proposal_id,
        "human_approval_required": True,
    })

    customer_name = str(party.get("name") or party_query)
    text = (
        f"پیشنهاد فاکتور فروش با شناسه Proposal #{proposal_id} ساخته شد؛ هنوز هیچ سندی نهایی یا ثبت نشده است.\n"
        f"مشتری: {customer_name}\n"
        + "\n".join(display_lines)
        + "\nبرای اجرای واقعی، کارت Proposal را در پنل بررسی و صراحتاً تأیید کنید."
    )

    with worker.progress_lock:
        trace_copy = list(worker.current_trace[-50:])

    return text, {
        "provider": "guarded_tool_orchestrator",
        "model": model if parser_source == "llm" else "none",
        "attempted_model": model if parser_source == "llm" else "none",
        "mode": "guarded_sales_invoice_proposal",
        "tools_used": tools_used,
        "rounds": 1 if parser_source == "llm" else 0,
        "parser_source": parser_source,
        "proposal_id": proposal_id,
        "awaiting_human_approval": True,
        "resolved_party_id": party_id,
        "resolved_item_ids": [int(x["item_id"]) for x in resolved_lines],
        "metrics": metrics,
        "trace": trace_copy,
        "patch_version": PATCH_VERSION,
    }


def install_agent_guard(worker_cls: type) -> None:
    if bool(getattr(worker_cls, "_agent_guard_v1_installed", False)):
        return

    original_process_agent = worker_cls.process_agent

    def patched_process_agent(self: Any, job: dict[str, Any], tools_desc: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
        prompt = str(job.get("prompt") or "")
        if is_sales_invoice_create_request(prompt):
            return process_sales_invoice_request(self, job, tools_desc)
        return original_process_agent(self, job, tools_desc)

    worker_cls.process_agent = patched_process_agent
    worker_cls._agent_guard_v1_installed = True
    worker_cls._agent_guard_original_process_agent = original_process_agent
