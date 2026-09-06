#!/usr/bin/env python3
"""ERPSMART v10.9.2 — constrained Business Supervisor + structured evidence.

This rescue layer intentionally handles only the two MVP-B business workflows that
need real cross-module reasoning today:
- supplier performance / comparison
- shipment/trade-case impact on known sales commitments

The server owns tool names and arguments. The LLM receives a bounded Evidence Pack
only after deterministic ERP reads and may interpret it, never invent business facts.
"""
from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any

import business_skills as BS

PATCH_VERSION = "v10.9.2-cycle12.2-agent-core-rescue-r1"


def _norm(value: Any) -> str:
    return BS.norm(value)


def _entities(job: dict[str, Any]) -> list[dict[str, Any]]:
    return BS.context_entities(job)


def _supplier_intent(prompt: str, entities: list[dict[str, Any]]) -> bool:
    n = _norm(prompt)
    if "/supplier-review" in n or "/compare-suppliers" in n:
        return True
    if any(e.get("type") == "party.supplier" for e in entities):
        return any(x in n for x in ("تامین", "تأمین", "عملکرد", "مقایسه", "بهتر", "خرید", "ریسک"))
    return any(x in n for x in ("تامین کننده", "تأمین کننده", "تامین‌کننده", "تأمین‌کننده")) and any(
        x in n for x in ("عملکرد", "مقایسه", "بهتر", "بهترین", "کدوم", "کدام", "ریسک", "قابل اعتماد")
    )


def _shipment_impact_intent(prompt: str, entities: list[dict[str, Any]]) -> bool:
    n = _norm(prompt)
    has_trade_entity = any(e.get("type") in {"trade.case", "shipment"} for e in entities)
    impact = any(x in n for x in ("تعهد فروش", "فروش تحت تاثیر", "فروش تحت تأثیر", "فروش", "مشتری", "اثر", "تاثیر", "تأثیر", "چی میشه", "چه می شود", "چه می‌شود", "impact", "commitment"))
    delay = any(x in n for x in ("دیر", "تاخیر", "تأخیر", "eta", "محموله", "حمل", "shipment", "پرونده"))
    return (has_trade_entity and impact and delay) or (impact and any(x in n for x in ("محموله", "shipment")))


def _fact(fid: str, label: str, value: Any, source: str, unit: str = "", entity: str = "", note: str = "") -> dict[str, Any]:
    return {"id": fid, "label": label, "value": value, "unit": unit, "source": source, "entity": entity, "note": note}


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


def _numeric_tokens(value: Any) -> set[str]:
    s = str(value or "").translate(BS.DIGITS)
    return set(re.findall(r"\d+(?:\.\d+)?", s))


def _validate_model_claim(text: str, evidence_ids: list[str], facts: dict[str, dict[str, Any]]) -> bool:
    if not text.strip() or not evidence_ids:
        return False
    if any(eid not in facts for eid in evidence_ids):
        return False
    allowed: set[str] = set()
    for eid in evidence_ids:
        f = facts[eid]
        allowed |= _numeric_tokens(f.get("value"))
        allowed |= _numeric_tokens(f.get("entity"))
        allowed |= _numeric_tokens(f.get("note"))
    return _numeric_tokens(text).issubset(allowed)


def _render_pack(pack: dict[str, Any]) -> str:
    lines = [str(pack.get("title") or "گزارش"), "Grounded / Structured Evidence / Read-only", ""]
    for section in pack.get("sections") or []:
        lines.append(str(section.get("title") or "شواهد"))
        for fid in section.get("fact_ids") or []:
            f = pack["facts_by_id"].get(fid)
            if not f:
                continue
            suffix = f" | {f['entity']}" if f.get("entity") else ""
            lines.append(f"• [{fid}] {f['label']}: {_fmt(f['value'], f.get('unit') or '')}{suffix}")
        lines.append("")
    limitations = [str(x) for x in pack.get("limitations") or [] if str(x).strip()]
    if limitations:
        lines.append("محدودیت داده:")
        lines.extend(f"• {x}" for x in limitations)
        lines.append("")
    lines.append("Evidence IDs بالا به داده‌های تازه همان اجرای ERP متصل‌اند؛ عدد یا شناسه‌ای خارج از Toolها ساخته نشده است.")
    return "\n".join(lines).strip()


def _supplier_pack(data: Any, prompt: str) -> dict[str, Any]:
    d = data if isinstance(data, dict) else {}
    rows = [r for r in d.get("rows", []) if isinstance(r, dict)]
    facts: list[dict[str, Any]] = []
    sections: list[dict[str, Any]] = []
    counter = 1
    for row in rows:
        name = str(row.get("name") or row.get("code") or "تأمین‌کننده")
        ids: list[str] = []
        values = [
            ("خرید قطعی دوره", float(row.get("purchase_net_total") or 0), "ریال", "supplier_performance_summary"),
            ("تعداد اسناد خرید قطعی", int(row.get("purchase_document_count") or 0), "سند", "supplier_performance_summary"),
            ("درصد دریافت‌شده از مقدار سفارش", None if row.get("receipt_fill_rate") is None else float(row["receipt_fill_rate"])*100, "%", "supplier_performance_summary"),
            ("نرخ پذیرش در رسیدهای بازرسی‌شده", None if row.get("receipt_acceptance_rate") is None else float(row["receipt_acceptance_rate"])*100, "%", "supplier_performance_summary"),
            ("مقدار خرید باز/دریافت‌نشده", float(row.get("open_qty") or 0), "", "supplier_performance_summary"),
            ("پرونده بازرگانی پرریسک", int(row.get("high_risk_cases") or 0), "پرونده", "supplier_performance_summary"),
            ("بیشترین تأخیر ثبت‌شده", float(row.get("max_delay_days") or 0), "روز", "supplier_performance_summary"),
            ("نرخ رسیدن به‌موقع محموله‌های دارای نمونه", None if row.get("on_time_rate") is None else float(row["on_time_rate"])*100, "%", "supplier_performance_summary"),
            ("تعداد نمونه ورود دارای ETA/ATA", int(row.get("arrival_sample") or 0), "مورد", "supplier_performance_summary"),
        ]
        for label, value, unit, source in values:
            fid=f"E{counter}";counter+=1
            facts.append(_fact(fid,label,value,source,unit,name));ids.append(fid)
        sections.append({"title":f"تأمین‌کننده: {name}","fact_ids":ids})
    limitations=[]
    raw_lim=d.get("limitations")
    if isinstance(raw_lim, dict): limitations.extend(str(v) for v in raw_lim.values())
    elif isinstance(raw_lim, list): limitations.extend(str(v) for v in raw_lim)
    if not rows:limitations.append("در بازه فعلی خرید قطعی کافی برای مقایسه تأمین‌کنندگان پیدا نشد.")
    by={f["id"]:f for f in facts}
    return {"kind":"supplier_performance","title":"تحلیل عملکرد تأمین‌کنندگان","request":prompt,"facts":facts,"facts_by_id":by,"sections":sections,"limitations":limitations}


def _shipment_pack(data: Any, prompt: str) -> dict[str, Any]:
    d=data if isinstance(data,dict) else {}
    case=d.get("case") if isinstance(d.get("case"),dict) else {}
    shipment=d.get("shipment") if isinstance(d.get("shipment"),dict) else {}
    risk=d.get("trade_risk") if isinstance(d.get("trade_risk"),dict) else {}
    summary=d.get("summary") if isinstance(d.get("summary"),dict) else {}
    facts: list[dict[str, Any]]=[];sections=[];counter=1
    def add(label,value,unit="",entity="",source="shipment_commitment_impact",note=""):
        nonlocal counter
        fid=f"E{counter}";counter+=1;facts.append(_fact(fid,label,value,source,unit,entity,note));return fid
    case_name=str(case.get("case_no") or d.get("trade_case_id") or "پرونده")
    ids=[
        add("سطح ریسک پرونده",risk.get("risk_level") or "low",entity=case_name),
        add("تأخیر جاری محموله انتخاب‌شده",float(shipment.get("delay_days") if shipment.get("delay_days") is not None else (risk.get("delay_days") or 0)),"روز",case_name),
        add("وضعیت گمرک",case.get("clearance_status") or risk.get("clearance_status") or "-",entity=case_name),
        add("وضعیت حمل",shipment.get("status") or risk.get("shipment_status") or "-",entity=case_name),
        add("ETA",shipment.get("eta") or risk.get("eta") or "-",entity=case_name),
        add("تعداد تعهد فروش هم‌کالای باز",int(summary.get("affected_sales_count") or 0),"سند",case_name),
        add("تعداد مشتری در معرض بررسی",int(summary.get("affected_customer_count") or 0),"مشتری",case_name),
        add("تعداد کالاهای دارای سیگنال وابستگی به همین ورودی",int(summary.get("items_with_trade_case_dependency_signal") or summary.get("items_with_future_inbound_dependency") or 0),"کالا",case_name),
    ]
    sections.append({"title":f"پرونده/محموله: {case_name}","fact_ids":ids})
    for item in [x for x in d.get("item_exposure",[]) if isinstance(x,dict)]:
        name=str(item.get("item_name") or item.get("item_code") or "کالا")
        ids=[
            add("تعهد فروش باز",float(item.get("outstanding_sales_qty") or 0),"",name),
            add("مقدار رزروشده برای تعهدهای متاثر",float(item.get("reserved_for_affected_sales") or 0),"",name),
            add("موجودی قابل استفاده فعلی",float(item.get("available") or 0),"",name),
            add("ورودی مورد انتظار کل",float(item.get("expected_inbound") or 0),"",name),
            add("ورودی باز همین پرونده",float(item.get("trade_case_open_inbound") or 0),"",name),
            add("کسری پس از موجودی فعلی",float(item.get("uncovered_after_current_available") or 0),"",name),
            add("سیگنال وابستگی بالقوه به همین پرونده",bool(item.get("potential_dependency_on_this_trade_case")),"",name),
        ]
        sections.append({"title":f"کالا: {name}","fact_ids":ids})
    for sale in [x for x in d.get("affected_sales",[]) if isinstance(x,dict)][:10]:
        entity=f"{sale.get('document_no') or 'سند فروش'} / {sale.get('customer_name') or 'مشتری'}"
        ids=[
            add("تعهد تحویل باز سند",float(sale.get("outstanding_quantity") or 0),"",entity),
            add("مقدار رزروشده سند",float(sale.get("reserved_quantity") or 0),"",entity),
            add("مقدار بدون رزرو سند",float(sale.get("unreserved_quantity") or 0),"",entity),
        ]
        sections.append({"title":f"تعهد فروش: {entity}","fact_ids":ids})
    limitations=[]
    raw=d.get("limitations")
    if isinstance(raw,dict):limitations.extend(str(v) for v in raw.values())
    elif isinstance(raw,list):limitations.extend(str(v) for v in raw)
    by={f["id"]:f for f in facts}
    return {"kind":"shipment_commitment_impact","title":"اثر محموله بر تعهدهای فروش شناخته‌شده","request":prompt,"facts":facts,"facts_by_id":by,"sections":sections,"limitations":limitations}


def _runtime_analysis_models(worker: Any) -> list[str]:
    """Prefer the model that passed the real local preflight.

    The preflight writes only runtime routing metadata into /app/data (the existing
    Worker data volume). It never changes repository/config business truth. If the
    file is absent/stale/corrupt, normal role routing remains authoritative.
    """
    models: list[str] = []
    route_path = Path(__file__).resolve().parent / "data" / "cycle12_analysis_route.json"
    try:
        data = json.loads(route_path.read_text(encoding="utf-8"))
        selected = str(data.get("selected_model") or "").strip()
        if selected:
            models.append(selected)
    except Exception:
        pass
    for role in ("analysis", "fallback"):
        try:
            model = worker.model_for(role)
            if model and model not in models:
                models.append(model)
        except Exception:
            pass
    return models[:2]


def _analysis_schema(ids: list[str]) -> dict[str, Any]:
    ref={"type":"array","minItems":1,"maxItems":6,"uniqueItems":True,"items":{"type":"string","enum":ids}}
    claim={"type":"object","properties":{"text":{"type":"string"},"evidence_ids":ref},"required":["text","evidence_ids"],"additionalProperties":False}
    return {"type":"object","properties":{
        "summary":claim,
        "findings":{"type":"array","maxItems":5,"items":claim},
        "actions":{"type":"array","maxItems":4,"items":claim},
        "limitations":{"type":"array","maxItems":3,"items":{"type":"string"}},
    },"required":["summary","findings","actions","limitations"],"additionalProperties":False}


def _synthesize(worker: Any, job: dict[str, Any], prompt: str, pack: dict[str, Any], base_meta: dict[str, Any]) -> tuple[str, dict[str, Any]]:
    grounded=_render_pack(pack);facts=pack["facts_by_id"];ids=list(facts)
    if not ids:return grounded,{**base_meta,"synthesis":"deterministic_fallback","synthesis_error":"empty_evidence_pack"}
    public={"kind":pack["kind"],"request":prompt,"facts":pack["facts"],"limitations":pack.get("limitations") or []}
    system=(
        "You are ERPSMART Business Analyst. Use ONLY the supplied structured ERP facts. "
        "Every summary, finding and action must cite one or more Evidence IDs that directly support it. "
        "Do not invent business facts, IDs, dates, quantities, money or percentages. If evidence is insufficient, say so. "
        "For supplier comparison, do not equate higher purchase volume with better performance; weigh reliability, receipt acceptance, open quantity and trade risk. "
        "For shipment impact, distinguish potential exposure from proven causality and use inventory/reservation coverage. Answer in concise Persian."
    )
    schema=_analysis_schema(ids)
    models=_runtime_analysis_models(worker)
    last_error="analysis_model_unavailable"
    for attempt,model in enumerate(models[:2],1):
        try:
            worker.trace(job,"grounded_synthesis","Structured Evidence Pack sent to reasoning model",{"model":model,"fact_count":len(ids),"kind":pack["kind"],"attempt":attempt})
            response=worker.ollama_chat(job,90+attempt,[{"role":"system","content":system},{"role":"user","content":json.dumps(public,ensure_ascii=False,separators=(",",":"),default=str)}],[],fast=False,model=model,num_ctx=3200,num_predict=480,temperature=0.1,timeout_seconds=150,response_format=schema,think_override=False)
            data=json.loads(str((response.get("message") or {}).get("content") or ""))
            if not isinstance(data,dict):raise ValueError("analysis_json_object_required")
            claims=[data.get("summary")]+list(data.get("findings") or [])+list(data.get("actions") or [])
            for c in claims:
                if not isinstance(c,dict) or not _validate_model_claim(str(c.get("text") or ""),[str(x) for x in c.get("evidence_ids") or []],facts):
                    raise ValueError("analysis_claim_not_grounded")
            lines=[grounded,"","تحلیل هوشمند روی Evidence Pack:"]
            summary=data["summary"];lines.append(f"• {summary['text']}  [شواهد: {', '.join(summary['evidence_ids'])}]")
            if data.get("findings"):
                lines.append("• برداشت‌های کلیدی:")
                for c in data["findings"]:lines.append(f"  - {c['text']}  [شواهد: {', '.join(c['evidence_ids'])}]")
            if data.get("actions"):
                lines.append("• اقدام‌های پیشنهادی:")
                for c in data["actions"]:lines.append(f"  - {c['text']}  [شواهد: {', '.join(c['evidence_ids'])}]")
            model_limits=[str(x).strip() for x in data.get("limitations") or [] if str(x).strip()]
            if model_limits:
                lines.append("• محدودیت تحلیل:");lines.extend(f"  - {x}" for x in model_limits)
            meta=dict(base_meta);meta.update({"provider":"grounded_hybrid","model":model,"synthesis":"analysis_model","evidence_pack":pack["kind"],"fact_count":len(ids),"synthesis_guard":"evidence_id_claim_binding","patch_version":PATCH_VERSION})
            return "\n".join(lines),meta
        except Exception as exc:
            last_error=type(exc).__name__
            worker.trace(job,"grounded_synthesis_fallback","Reasoning model attempt rejected; evidence remains authoritative",{"model":model,"attempt":attempt,"error_type":last_error,"kind":pack["kind"]})
    meta=dict(base_meta);meta.update({"synthesis":"deterministic_fallback","synthesis_error":last_error,"evidence_pack":pack["kind"],"fact_count":len(ids),"patch_version":PATCH_VERSION})
    return grounded+"\n\nیادداشت: مدل تحلیلی در این اجرا پاسخ قابل اعتبارسنجی تولید نکرد؛ Evidence Pack بالا همچنان معتبر است.",meta


def _supplier_run(worker: Any, job: dict[str, Any], prompt: str, entities: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
    suppliers=[e for e in entities if e.get("type")=="party.supplier"][:2]
    args={"months":6,"limit":max(2,len(suppliers)) if suppliers else 5}
    if suppliers:args["party_ids"]=[int(e["id"]) for e in suppliers]
    worker.trace(job,"supervisor_plan","Server compiled supplier-performance plan",{"tools":["supplier_performance_summary"],"party_count":len(suppliers),"arguments_owned_by":"server"})
    data=worker.tool(job,"supplier_performance_summary",args,BS.stable(int(job["id"]),"supervisor-supplier",args))
    pack=_supplier_pack(data,prompt)
    return _synthesize(worker,job,prompt,pack,{"mode":"supplier_performance_supervisor_read","tools_used":["supplier_performance_summary"],"skill_id":"compare-suppliers" if len(suppliers)==2 else "supplier-review","supervisor":"bounded_plan_v1"})


def _shipment_run(worker: Any, job: dict[str, Any], prompt: str, entities: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
    trade=next((e for e in entities if e.get("type")=="trade.case"),None)
    shipment=next((e for e in entities if e.get("type")=="shipment"),None)
    if not trade and not shipment:
        return "برای تحلیل اثر تأخیر روی تعهدهای فروش، پرونده بازرگانی یا محموله را با @ متصل کن.",{"provider":"deterministic","model":"none","mode":"shipment_commitment_impact_blocked","tools_used":[],"skill_id":"shipment-impact","supervisor":"bounded_plan_v1","patch_version":PATCH_VERSION}
    args={"limit":15}
    if trade:args["trade_case_id"]=int(trade["id"])
    else:args["shipment_id"]=int(shipment["id"])
    worker.trace(job,"supervisor_plan","Server compiled shipment→sales commitment impact plan",{"tools":["shipment_commitment_impact"],"entity_type":"trade.case" if trade else "shipment","arguments_owned_by":"server"})
    data=worker.tool(job,"shipment_commitment_impact",args,BS.stable(int(job["id"]),"supervisor-shipment-impact",args))
    pack=_shipment_pack(data,prompt)
    return _synthesize(worker,job,prompt,pack,{"mode":"shipment_commitment_impact_read","tools_used":["shipment_commitment_impact"],"skill_id":"shipment-impact","supervisor":"bounded_plan_v1"})


def install_business_supervisor(worker_cls: type) -> None:
    if getattr(worker_cls,"_business_supervisor_v1_installed",False):return
    original=worker_cls.process_agent
    def patched(self: Any,job: dict[str,Any],tools_desc: list[dict[str,Any]]):
        prompt=str(job.get("prompt") or "")
        if BS.is_write_request(prompt):return original(self,job,tools_desc)
        entities=_entities(job);available={str(d.get("name") or "") for d in tools_desc if str(d.get("mode") or "read")=="read"}
        if _shipment_impact_intent(prompt,entities) and "shipment_commitment_impact" in available:
            return _shipment_run(self,job,prompt,entities)
        if _supplier_intent(prompt,entities) and "supplier_performance_summary" in available:
            return _supplier_run(self,job,prompt,entities)
        return original(self,job,tools_desc)
    worker_cls.process_agent=patched
    worker_cls._business_supervisor_v1_installed=True
    worker_cls._business_supervisor_v1_original_process_agent=original
