#!/usr/bin/env python3
"""ERPSMART v10.2 deterministic Trade/Logistics/Landed Cost Agent slice."""
from __future__ import annotations
import re, json, hashlib
from typing import Any

DIGITS=str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩","01234567890123456789")
PATCH_VERSION="v10.2-trade-logistics-r1"
COST_MAP={"حمل":"freight","بیمه":"insurance","حقوق گمرکی":"customs_duty","مالیات واردات":"import_tax","ترخیص":"brokerage","کارگزاری":"brokerage","هندلینگ":"handling","انبارداری":"storage","بازرسی":"inspection","کارمزد بانکی":"bank_fee","سایر":"other"}
MODE_MAP={"دریایی":"sea","هوایی":"air","جاده ای":"road","جاده‌ای":"road","ریلی":"rail","کوریر":"courier","courier":"courier"}

def norm(x:Any)->str:
    s=str(x or "").translate(DIGITS).replace("ي","ی").replace("ك","ک").replace("\u200c"," ")
    return re.sub(r"\s+"," ",s).strip().lower()

def number(v:Any)->float:
    try:return float(v or 0)
    except (TypeError,ValueError):return 0.0

def rows(x:Any)->list[dict[str,Any]]:
    if isinstance(x,dict) and isinstance(x.get("rows"),list):return [r for r in x["rows"] if isinstance(r,dict)]
    if isinstance(x,list):return [r for r in x if isinstance(r,dict)]
    return []

def stable(job_id:int,label:str,value:Any)->str:
    raw=json.dumps(value,ensure_ascii=False,sort_keys=True,separators=(",",":")) if not isinstance(value,str) else value
    return f"job{job_id}-{label}-"+hashlib.sha256(raw.encode("utf-8")).hexdigest()[:16]

def quoted_after(prompt:str,label:str)->str:
    m=re.search(re.escape(label)+r"\s*[«\"']([^»\"'\r\n]{1,190})[»\"']",str(prompt or ""),re.I);return m.group(1).strip() if m else ""

def resolve_unique(candidates:list[dict[str,Any]],query:str,keys:tuple[str,...])->dict[str,Any]|None:
    q=norm(query);exact=[r for r in candidates if any(norm(r.get(k))==q for k in keys if r.get(k) is not None)]
    if len(exact)==1:return exact[0]
    if len(candidates)==1:return candidates[0]
    return None

def purchase_no(prompt:str)->str:
    q=quoted_after(prompt,"سند خرید")
    if q:return q
    m=re.search(r"\b(?:AI-PUR|PUR)-[A-Za-z0-9_-]{3,120}\b",str(prompt or ""),re.I);return m.group(0) if m else ""

def case_no(prompt:str)->str:
    q=quoted_after(prompt,"پرونده بازرگانی")
    if q:return q
    m=re.search(r"\bTRD-[A-Za-z0-9_-]{3,120}\b",str(prompt or ""),re.I);return m.group(0) if m else ""

def action_word(n:str)->bool:return any(x in n for x in ("آماده کن","بساز","ثبت کن","ایجاد کن"))
def is_case_create(p:str)->bool:
    n=norm(p);return "پرونده بازرگانی" in n and "سند خرید" in n and action_word(n)
def is_shipment_create(p:str)->bool:
    n=norm(p);return "پرونده بازرگانی" in n and "حمل" in n and action_word(n) and any(k in n for k in MODE_MAP)
def is_cost_create(p:str)->bool:
    n=norm(p);return "پرونده بازرگانی" in n and "هزینه" in n and action_word(n) and any(k in n for k in COST_MAP)
def is_landed_read(p:str)->bool:
    n=norm(p);return any(x in n for x in ("landed cost","بهای تمام شده واردات","بهای تمام‌شده واردات","هزینه تمام شده واردات","هزینه تمام‌شده واردات")) and not action_word(n)
def is_risk_read(p:str)->bool:
    n=norm(p);return any(x in n for x in ("ریسک بازرگانی","ریسک حمل","تاخیر حمل","تأخیر حمل","پرونده های پرریسک","پرونده‌های پرریسک")) and not action_word(n)
def is_case_read(p:str)->bool:
    n=norm(p);return "پرونده بازرگانی" in n and any(x in n for x in ("وضعیت","گزارش","جزئیات")) and not (is_case_create(p) or is_shipment_create(p) or is_cost_create(p) or is_landed_read(p))

def blocked(text:str,tools:list[str],mode:str):return text,{"provider":"deterministic","model":"none","mode":mode,"tools_used":tools,"patch_version":PATCH_VERSION}

def find_case(worker:Any,job:dict[str,Any],query:str,tools:list[str])->dict[str,Any]|None:
    r=worker.tool(job,"search_trade_cases",{"query":query},stable(int(job["id"]),"trade-case",query));tools.append("search_trade_cases");return resolve_unique(rows(r),query,("case_no","purchase_document_no","proforma_no"))

def process_case_create(worker:Any,job:dict[str,Any],prompt:str):
    tools=[];docq=purchase_no(prompt);inc=quoted_after(prompt,"اینکوترمز").upper();origin=quoted_after(prompt,"مبدا") or quoted_after(prompt,"مبدأ");dest=quoted_after(prompt,"مقصد");currency=quoted_after(prompt,"ارز").upper();m=re.search(r"نرخ(?:\s+تبدیل)?\s+([0-9۰-۹٠-٩]+(?:\.[0-9]+)?)",str(prompt or ""),re.I);fx=number(m.group(1).translate(DIGITS)) if m else 0
    worker.trace(job,"guarded_route","Trade case -> guarded proposal workflow",{})
    if not docq or not inc or not currency or (currency!="IRR" and fx<=0):return blocked("برای پرونده بازرگانی باید سند خرید، Incoterm، ارز و نرخ تبدیل صریح باشند؛ Proposal ساخته نشد.",tools,"guarded_trade_case_blocked")
    rd=worker.tool(job,"search_purchase_documents",{"query":docq},stable(int(job["id"]),"trade-doc",docq));tools.append("search_purchase_documents");doc=resolve_unique(rows(rd),docq,("document_no",))
    if not doc:return blocked(f"سند خرید «{docq}» یکتا پیدا نشد؛ Proposal ساخته نشد.",tools,"guarded_trade_case_blocked")
    args={"purchase_doc_id":int(doc["id"]),"incoterm":inc,"currency_code":currency,"fx_rate_to_irr":1 if currency=="IRR" else fx,"origin_country":origin,"destination_country":dest}
    worker.trace(job,"proposal_request","Creating trade case proposal",{"human_approval_required":True});pr=worker.tool(job,"create_trade_case",args,stable(int(job["id"]),"trade-case-proposal",args));tools.append("create_trade_case")
    if not isinstance(pr,dict) or int(pr.get("proposal_id") or 0)<=0:return blocked("Control Plane ایجاد Proposal پرونده بازرگانی را تأیید نکرد.",tools,"guarded_trade_case_blocked")
    pid=int(pr["proposal_id"]);worker.trace(job,"proposal_created","Trade case proposal created",{"proposal_id":pid,"human_approval_required":True});return f"Proposal #{pid} برای ساخت پرونده بازرگانی سند {doc.get('document_no') or docq} با {inc} و ارز {currency} آماده شد. تا تأیید انسانی هیچ پرونده‌ای ایجاد نمی‌شود.",{"provider":"guarded_tool_orchestrator","model":"none","mode":"guarded_trade_case_proposal","tools_used":tools,"proposal_id":pid,"proposal_status":"awaiting_human_approval","awaiting_human_approval":True,"patch_version":PATCH_VERSION}

def process_shipment_create(worker:Any,job:dict[str,Any],prompt:str):
    tools=[];cq=case_no(prompt);n=norm(prompt);mode=next((v for k,v in MODE_MAP.items() if k in n),"");origin=quoted_after(prompt,"مبدا") or quoted_after(prompt,"مبدأ");dest=quoted_after(prompt,"مقصد");etd=quoted_after(prompt,"ETD");eta=quoted_after(prompt,"ETA");forwarder=quoted_after(prompt,"فورواردر") or quoted_after(prompt,"Forwarder")
    worker.trace(job,"guarded_route","Trade shipment -> guarded proposal workflow",{})
    if not cq or not mode:return blocked("برای محموله باید شماره پرونده بازرگانی و روش حمل صریح باشند؛ Proposal ساخته نشد.",tools,"guarded_trade_shipment_blocked")
    case=find_case(worker,job,cq,tools)
    if not case:return blocked(f"پرونده «{cq}» یکتا پیدا نشد؛ Proposal ساخته نشد.",tools,"guarded_trade_shipment_blocked")
    args={"trade_case_id":int(case["id"]),"mode":mode,"origin_location":origin,"destination_location":dest,"etd":etd,"eta":eta,"forwarder":forwarder,"status":"planned"};worker.trace(job,"proposal_request","Creating trade shipment proposal",{"human_approval_required":True});pr=worker.tool(job,"create_trade_shipment",args,stable(int(job["id"]),"trade-shipment-proposal",args));tools.append("create_trade_shipment")
    if not isinstance(pr,dict) or int(pr.get("proposal_id") or 0)<=0:return blocked("Control Plane ایجاد Proposal محموله را تأیید نکرد.",tools,"guarded_trade_shipment_blocked")
    pid=int(pr["proposal_id"]);worker.trace(job,"proposal_created","Trade shipment proposal created",{"proposal_id":pid,"human_approval_required":True});return f"Proposal #{pid} برای حمل {mode} پرونده {case.get('case_no') or cq} آماده شد. تا تأیید انسانی Shipment ساخته نمی‌شود.",{"provider":"guarded_tool_orchestrator","model":"none","mode":"guarded_trade_shipment_proposal","tools_used":tools,"proposal_id":pid,"proposal_status":"awaiting_human_approval","awaiting_human_approval":True,"patch_version":PATCH_VERSION}

def process_cost_create(worker:Any,job:dict[str,Any],prompt:str):
    tools=[];cq=case_no(prompt);p=str(prompt or "").translate(DIGITS);n=norm(p);basis="actual" if "واقعی" in n else ("estimated" if any(x in n for x in ("برآوردی","براوردی")) else "");key=next((k for k in COST_MAP if k in n),"");ctype=COST_MAP.get(key,"");m=re.search(r"(?:هزینه\s+)?(?:واقعی|برآوردی|براوردی)?\s*(?:"+"|".join(map(re.escape,COST_MAP.keys()))+r")\s+([0-9]+(?:\.[0-9]+)?)\s*([A-Za-z]{3})",p,re.I);amount=number(m.group(1)) if m else 0;currency=m.group(2).upper() if m else "";mr=re.search(r"نرخ\s+([0-9]+(?:\.[0-9]+)?)",p,re.I);fx=number(mr.group(1)) if mr else (1 if currency=="IRR" else 0)
    worker.trace(job,"guarded_route","Trade cost -> guarded proposal workflow",{})
    if not cq or not basis or not ctype or amount<=0 or not currency or fx<=0:return blocked("برای هزینه باید پرونده، مبنای واقعی/برآوردی، نوع، مبلغ، ارز و نرخ صریح باشند؛ Proposal ساخته نشد.",tools,"guarded_trade_cost_blocked")
    case=find_case(worker,job,cq,tools)
    if not case:return blocked(f"پرونده «{cq}» یکتا پیدا نشد؛ Proposal ساخته نشد.",tools,"guarded_trade_cost_blocked")
    args={"trade_case_id":int(case["id"]),"cost_type":ctype,"basis":basis,"amount":amount,"currency_code":currency,"fx_rate_to_irr":fx};worker.trace(job,"proposal_request","Creating trade cost proposal",{"human_approval_required":True});pr=worker.tool(job,"add_trade_cost",args,stable(int(job["id"]),"trade-cost-proposal",args));tools.append("add_trade_cost")
    if not isinstance(pr,dict) or int(pr.get("proposal_id") or 0)<=0:return blocked("Control Plane ایجاد Proposal هزینه را تأیید نکرد.",tools,"guarded_trade_cost_blocked")
    pid=int(pr["proposal_id"]);worker.trace(job,"proposal_created","Trade cost proposal created",{"proposal_id":pid,"human_approval_required":True});return f"Proposal #{pid} برای هزینه {basis} نوع {ctype} به مبلغ {amount:g} {currency} در پرونده {case.get('case_no') or cq} آماده شد. تا تأیید انسانی Landed Cost تغییر نمی‌کند.",{"provider":"guarded_tool_orchestrator","model":"none","mode":"guarded_trade_cost_proposal","tools_used":tools,"proposal_id":pid,"proposal_status":"awaiting_human_approval","awaiting_human_approval":True,"patch_version":PATCH_VERSION}

def process_case_read(worker:Any,job:dict[str,Any],prompt:str):
    tools=[];cq=case_no(prompt)
    if not cq:return blocked("شماره پرونده بازرگانی را صریح وارد کن.",tools,"trade_case_read")
    case=find_case(worker,job,cq,tools)
    if not case:return blocked(f"پرونده «{cq}» یکتا پیدا نشد.",tools,"trade_case_read")
    worker.trace(job,"grounded_read","Grounded trade case snapshot",{});d=worker.tool(job,"trade_case_snapshot",{"case_id":int(case["id"])},stable(int(job["id"]),"trade-snapshot",int(case["id"])));tools.append("trade_case_snapshot");c=d.get("case",{}) if isinstance(d,dict) else {};ships=d.get("shipments",[]) if isinstance(d,dict) else [];s=ships[0] if ships else {};land=d.get("landed_cost",{}) if isinstance(d,dict) else {};text=f"پرونده {c.get('case_no') or cq} | خرید {c.get('purchase_document_no') or '-'} | تأمین‌کننده {c.get('supplier_name') or '-'} | Incoterm {c.get('incoterm') or '-'} | وضعیت {c.get('status') or '-'} | حمل {s.get('mode') or '-'} / {s.get('status') or '-'} | ETA {s.get('eta') or '-'} | ترخیص {c.get('clearance_status') or '-'} | Projected Landed Cost {number(land.get('projected_landed_total_irr')):,.0f} ریال";worker.trace(job,"grounded_read_complete","Grounded trade case snapshot completed",{});return text,{"provider":"deterministic","model":"none","mode":"trade_case_read","tools_used":tools,"patch_version":PATCH_VERSION}

def process_landed_read(worker:Any,job:dict[str,Any],prompt:str):
    tools=[];cq=case_no(prompt)
    if not cq:return blocked("شماره پرونده بازرگانی برای Landed Cost الزامی است.",tools,"trade_landed_cost_read")
    case=find_case(worker,job,cq,tools)
    if not case:return blocked(f"پرونده «{cq}» یکتا پیدا نشد.",tools,"trade_landed_cost_read")
    worker.trace(job,"grounded_read","Grounded landed cost",{});d=worker.tool(job,"landed_cost_summary",{"case_id":int(case["id"])},stable(int(job["id"]),"landed",int(case["id"])));tools.append("landed_cost_summary");out=[f"Landed Cost پرونده {d.get('case_no') or cq}:",f"• خرید پایه: {number(d.get('purchase_base_irr')):,.0f} ریال",f"• هزینه برآوردی: {number(d.get('estimated_additional_irr')):,.0f} ریال",f"• هزینه واقعی ثبت‌شده: {number(d.get('actual_additional_recorded_irr')):,.0f} ریال",f"• Projected Landed: {number(d.get('projected_landed_total_irr')):,.0f} ریال"]
    for a in (d.get("allocations") or [])[:20]:out.append(f"• {a.get('item_code') or '-'} | Base Unit {number(a.get('base_unit_cost_irr')):,.0f} | Projected Unit {number(a.get('projected_landed_unit_cost_irr')):,.0f} | دریافت {number(a.get('accepted_qty')):g}")
    worker.trace(job,"grounded_read_complete","Grounded landed cost completed",{});return "\n".join(out),{"provider":"deterministic","model":"none","mode":"trade_landed_cost_read","tools_used":tools,"patch_version":PATCH_VERSION}

def process_risk(worker:Any,job:dict[str,Any]):
    worker.trace(job,"grounded_read","Grounded trade risk",{});d=worker.tool(job,"trade_risk_summary",{"limit":50},stable(int(job["id"]),"trade-risk",50));rr=rows(d);text="ریسک بازرگانی مهمی ثبت نشده است." if not rr else "ریسک پرونده‌های بازرگانی:\n"+"\n".join(f"• {r.get('case_no') or '-'} | {r.get('risk_level') or 'low'} | تاخیر {number(r.get('delay_days')):g} روز | حمل {r.get('shipment_status') or '-'} | ترخیص {r.get('clearance_status') or '-'} | Projected {number(r.get('projected_landed_total_irr')):,.0f} ریال" for r in rr[:50]);worker.trace(job,"grounded_read_complete","Grounded trade risk completed",{"rows":len(rr)});return text,{"provider":"deterministic","model":"none","mode":"trade_risk_read","tools_used":["trade_risk_summary"],"patch_version":PATCH_VERSION}

def install_trade_logistics(worker_cls:type)->None:
    if getattr(worker_cls,"_trade_logistics_v1_installed",False):return
    original=worker_cls.process_agent
    def patched(self:Any,job:dict[str,Any],tools_desc:list[dict[str,Any]]):
        p=str(job.get("prompt") or "");available={str(x.get("name") or "") for x in tools_desc if isinstance(x,dict)}
        if is_cost_create(p) and {"search_trade_cases","add_trade_cost"}.issubset(available):return process_cost_create(self,job,p)
        if is_shipment_create(p) and {"search_trade_cases","create_trade_shipment"}.issubset(available):return process_shipment_create(self,job,p)
        if is_case_create(p) and {"search_purchase_documents","create_trade_case"}.issubset(available):return process_case_create(self,job,p)
        if is_landed_read(p) and {"search_trade_cases","landed_cost_summary"}.issubset(available):return process_landed_read(self,job,p)
        if is_risk_read(p) and "trade_risk_summary" in available:return process_risk(self,job)
        if is_case_read(p) and {"search_trade_cases","trade_case_snapshot"}.issubset(available):return process_case_read(self,job,p)
        return original(self,job,tools_desc)
    worker_cls.process_agent=patched;worker_cls._trade_logistics_v1_installed=True;worker_cls._trade_logistics_v1_original_process_agent=original
