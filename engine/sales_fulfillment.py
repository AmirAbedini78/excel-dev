#!/usr/bin/env python3
"""ERPSMART v10.3 deterministic Sales Fulfillment / Margin slice."""
from __future__ import annotations
import hashlib, json, re
from typing import Any

DIGITS=str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩","01234567890123456789")
PATCH_VERSION="v10.3-sales-fulfillment-r1"

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
    m=re.search(re.escape(label)+r"\s*[«\"']([^»\"'\r\n]{1,190})[»\"']",str(prompt or ""),re.I)
    return m.group(1).strip() if m else ""

def sales_no(prompt:str)->str:
    q=quoted_after(prompt,"سند فروش")
    if q:return q
    m=re.search(r"\b(?:AI-SAL|SAL)-[A-Za-z0-9_-]{3,120}\b",str(prompt or ""),re.I)
    return m.group(0) if m else ""

def action_word(n:str)->bool:
    return any(x in n for x in ("رزرو کن","تحویل کن","ارسال کن","آماده کن","ثبت کن","ایجاد کن"))

def resolve_unique(candidates:list[dict[str,Any]],query:str,keys:tuple[str,...])->dict[str,Any]|None:
    q=norm(query);exact=[r for r in candidates if any(norm(r.get(k))==q for k in keys if r.get(k) is not None)]
    if len(exact)==1:return exact[0]
    if len(candidates)==1:return candidates[0]
    return None

def is_reserve(p:str)->bool:
    n=norm(p);return "سند فروش" in n and "رزرو" in n and action_word(n) and not any(x in n for x in ("گزارش","وضعیت"))

def is_delivery(p:str)->bool:
    n=norm(p);return "سند فروش" in n and any(x in n for x in ("تحویل","ارسال")) and action_word(n) and not any(x in n for x in ("گزارش","وضعیت"))

def is_margin(p:str)->bool:
    n=norm(p);return "سند فروش" in n and any(x in n for x in ("حاشیه سود","سود ناخالص","gross margin")) and not action_word(n)

def is_fulfillment(p:str)->bool:
    n=norm(p);return "سند فروش" in n and any(x in n for x in ("وضعیت تامین","وضعیت تأمین","وضعیت تحویل","رزرو و تحویل","تامین و تحویل","تأمین و تحویل")) and not action_word(n)

def is_manager_brief(p:str)->bool:
    n=norm(p);return any(x in n for x in ("manager brief","گزارش مدیریتی بازرگانی و فروش","خلاصه مدیریتی عملیات تجاری","گزارش مدیریتی عملیات تجاری"))

def blocked(text:str,tools:list[str],mode:str):
    return text,{"provider":"deterministic","model":"none","mode":mode,"tools_used":tools,"patch_version":PATCH_VERSION}

def find_sales(worker:Any,job:dict[str,Any],query:str,tools:list[str])->dict[str,Any]|None:
    r=worker.tool(job,"search_sales_documents",{"query":query},stable(int(job["id"]),"sales-doc",query));tools.append("search_sales_documents")
    return resolve_unique(rows(r),query,("document_no",))

def find_warehouse(worker:Any,job:dict[str,Any],query:str,tools:list[str])->dict[str,Any]|None:
    r=worker.tool(job,"search_warehouses",{"query":query},stable(int(job["id"]),"sales-wh",query));tools.append("search_warehouses")
    return resolve_unique(rows(r),query,("name","code"))

def process_reserve(worker:Any,job:dict[str,Any],prompt:str):
    tools=[];sq=sales_no(prompt);wq=quoted_after(prompt,"انبار")
    worker.trace(job,"guarded_route","Sales reservation -> guarded proposal workflow",{})
    if not sq or not wq:return blocked("برای رزرو باید شماره سند فروش و انبار صریح باشند؛ Proposal ساخته نشد.",tools,"guarded_sales_reservation_blocked")
    doc=find_sales(worker,job,sq,tools);wh=find_warehouse(worker,job,wq,tools)
    if not doc:return blocked(f"سند فروش «{sq}» یکتا پیدا نشد؛ Proposal ساخته نشد.",tools,"guarded_sales_reservation_blocked")
    if not wh:return blocked(f"انبار «{wq}» یکتا پیدا نشد؛ Proposal ساخته نشد.",tools,"guarded_sales_reservation_blocked")
    ful=worker.tool(job,"sales_fulfillment",{"sales_doc_id":int(doc["id"]),"warehouse_id":int(wh["id"])},stable(int(job["id"]),"sales-ful",{"d":doc["id"],"w":wh["id"]}));tools.append("sales_fulfillment")
    lines=[{"sales_line_id":int(r["sales_line_id"]),"quantity":number(r.get("outstanding_qty"))} for r in rows(ful) if number(r.get("outstanding_qty"))>0]
    if not lines:return blocked("این سند فروش باقیمانده قابل رزرو ندارد؛ Proposal ساخته نشد.",tools,"guarded_sales_reservation_blocked")
    args={"sales_doc_id":int(doc["id"]),"warehouse_id":int(wh["id"]),"lines":lines}
    worker.trace(job,"proposal_request","Creating sales reservation proposal",{"human_approval_required":True})
    pr=worker.tool(job,"reserve_sales_stock",args,stable(int(job["id"]),"sales-reserve-proposal",args));tools.append("reserve_sales_stock")
    if not isinstance(pr,dict) or int(pr.get("proposal_id") or 0)<=0:return blocked("Control Plane ایجاد Proposal رزرو فروش را تأیید نکرد.",tools,"guarded_sales_reservation_blocked")
    pid=int(pr["proposal_id"]);worker.trace(job,"proposal_created","Sales reservation proposal created",{"proposal_id":pid,"human_approval_required":True})
    return f"Proposal #{pid} برای رزرو موجودی سند {doc.get('document_no') or sq} در انبار {wh.get('name') or wq} آماده شد. تا تأیید انسانی موجودی رزرو نمی‌شود.",{"provider":"guarded_tool_orchestrator","model":"none","mode":"guarded_sales_reservation_proposal","tools_used":tools,"proposal_id":pid,"proposal_status":"awaiting_human_approval","awaiting_human_approval":True,"patch_version":PATCH_VERSION}

def process_delivery(worker:Any,job:dict[str,Any],prompt:str):
    tools=[];sq=sales_no(prompt);wq=quoted_after(prompt,"انبار")
    worker.trace(job,"guarded_route","Sales delivery -> guarded proposal workflow",{})
    if not sq or not wq:return blocked("برای تحویل باید شماره سند فروش و انبار صریح باشند؛ Proposal ساخته نشد.",tools,"guarded_sales_delivery_blocked")
    doc=find_sales(worker,job,sq,tools);wh=find_warehouse(worker,job,wq,tools)
    if not doc:return blocked(f"سند فروش «{sq}» یکتا پیدا نشد؛ Proposal ساخته نشد.",tools,"guarded_sales_delivery_blocked")
    if not wh:return blocked(f"انبار «{wq}» یکتا پیدا نشد؛ Proposal ساخته نشد.",tools,"guarded_sales_delivery_blocked")
    ful=worker.tool(job,"sales_fulfillment",{"sales_doc_id":int(doc["id"]),"warehouse_id":int(wh["id"])},stable(int(job["id"]),"sales-delivery-ful",{"d":doc["id"],"w":wh["id"]}));tools.append("sales_fulfillment")
    lines=[{"sales_line_id":int(r["sales_line_id"]),"quantity":number(r.get("reserved_qty"))} for r in rows(ful) if number(r.get("reserved_qty"))>0]
    if not lines:return blocked("برای این سند در انبار انتخابی رزرو فعالی وجود ندارد؛ Proposal تحویل ساخته نشد.",tools,"guarded_sales_delivery_blocked")
    args={"sales_doc_id":int(doc["id"]),"warehouse_id":int(wh["id"]),"lines":lines}
    worker.trace(job,"proposal_request","Creating sales delivery proposal",{"human_approval_required":True})
    pr=worker.tool(job,"deliver_sales_stock",args,stable(int(job["id"]),"sales-delivery-proposal",args));tools.append("deliver_sales_stock")
    if not isinstance(pr,dict) or int(pr.get("proposal_id") or 0)<=0:return blocked("Control Plane ایجاد Proposal تحویل فروش را تأیید نکرد.",tools,"guarded_sales_delivery_blocked")
    pid=int(pr["proposal_id"]);worker.trace(job,"proposal_created","Sales delivery proposal created",{"proposal_id":pid,"human_approval_required":True})
    return f"Proposal #{pid} برای تحویل موجودی رزروشده سند {doc.get('document_no') or sq} آماده شد. تا تأیید انسانی خروجی انبار ثبت نمی‌شود.",{"provider":"guarded_tool_orchestrator","model":"none","mode":"guarded_sales_delivery_proposal","tools_used":tools,"proposal_id":pid,"proposal_status":"awaiting_human_approval","awaiting_human_approval":True,"patch_version":PATCH_VERSION}

def process_fulfillment(worker:Any,job:dict[str,Any],prompt:str):
    tools=[];sq=sales_no(prompt)
    if not sq:return blocked("شماره سند فروش را صریح وارد کن.",tools,"sales_fulfillment_read")
    doc=find_sales(worker,job,sq,tools)
    if not doc:return blocked(f"سند فروش «{sq}» یکتا پیدا نشد.",tools,"sales_fulfillment_read")
    worker.trace(job,"grounded_read","Grounded sales fulfillment",{})
    d=worker.tool(job,"sales_fulfillment",{"sales_doc_id":int(doc["id"])},stable(int(job["id"]),"sales-fulfillment-read",int(doc["id"])));tools.append("sales_fulfillment")
    out=[f"سند فروش {d.get('document_no') or sq} | مشتری {d.get('customer_name') or '-'} | سفارش {number(d.get('ordered_quantity')):g} | رزرو {number(d.get('reserved_quantity')):g} | تحویل {number(d.get('delivered_quantity')):g} | باقیمانده {number(d.get('outstanding_quantity')):g}"]
    for r in rows(d)[:20]:out.append(f"• {r.get('item_code') or '-'} | سفارش {number(r.get('ordered_qty')):g} | رزرو {number(r.get('reserved_qty')):g} | تحویل {number(r.get('delivered_qty')):g} | باقیمانده {number(r.get('outstanding_qty')):g}")
    worker.trace(job,"grounded_read_complete","Grounded sales fulfillment completed",{})
    return "\n".join(out),{"provider":"deterministic","model":"none","mode":"sales_fulfillment_read","tools_used":tools,"patch_version":PATCH_VERSION}

def process_margin(worker:Any,job:dict[str,Any],prompt:str):
    tools=[];sq=sales_no(prompt)
    if not sq:return blocked("شماره سند فروش برای گزارش سود الزامی است.",tools,"sales_margin_read")
    doc=find_sales(worker,job,sq,tools)
    if not doc:return blocked(f"سند فروش «{sq}» یکتا پیدا نشد.",tools,"sales_margin_read")
    worker.trace(job,"grounded_read","Grounded sales margin",{})
    d=worker.tool(job,"sales_margin_summary",{"sales_doc_id":int(doc["id"])},stable(int(job["id"]),"sales-margin",int(doc["id"])));tools.append("sales_margin_summary")
    out=[f"حاشیه سود سند {d.get('document_no') or sq}:",
         f"• فروش بدون مالیات: {number(d.get('revenue_ex_tax_irr')):,.0f} ریال",
         f"• بهای کالای تحویل‌شده: {number(d.get('cogs_irr')):,.0f} ریال",
         f"• سود ناخالص: {number(d.get('gross_margin_irr')):,.0f} ریال",
         f"• حاشیه سود: {number(d.get('gross_margin_pct')):.1f}٪",
         f"• مبنای هزینه: {d.get('margin_basis') or '-'}"]
    worker.trace(job,"grounded_read_complete","Grounded sales margin completed",{})
    return "\n".join(out),{"provider":"deterministic","model":"none","mode":"sales_margin_read","tools_used":tools,"patch_version":PATCH_VERSION}

def process_brief(worker:Any,job:dict[str,Any]):
    worker.trace(job,"grounded_read","Grounded trade and sales manager brief",{})
    d=worker.tool(job,"trade_manager_brief",{"limit":10},stable(int(job["id"]),"manager-brief",10))
    trade=d.get("trade",{}) if isinstance(d,dict) else {};inv=d.get("inventory",{}) if isinstance(d,dict) else {};sales=d.get("sales",{}) if isinstance(d,dict) else {}
    out=[f"Manager Brief | ریسک بازرگانی {int(trade.get('risk_count') or 0)} | کمبود موجودی {int(inv.get('shortage_count') or 0)} | فروش در معرض ریسک {int(sales.get('at_risk_count') or 0)}"]
    for r in (trade.get("rows") or [])[:5]:out.append(f"• Trade {r.get('case_no') or '-'} | {r.get('risk_level') or '-'} | {r.get('clearance_status') or '-'} | Projected {number(r.get('projected_landed_total_irr')):,.0f}")
    for r in (sales.get("at_risk") or [])[:5]:out.append(f"• Sales {r.get('document_no') or '-'} | مشتری {r.get('customer_name') or '-'} | باقیمانده {number(r.get('outstanding_quantity')):g} | رزرو {number(r.get('reserved_quantity')):g}")
    out.append("• Cash projection: فعلاً خارج از این Slice؛ تا تکمیل primitive تراکنش نقدی عددی ساخته نمی‌شود.")
    worker.trace(job,"grounded_read_complete","Grounded manager brief completed",{})
    return "\n".join(out),{"provider":"deterministic","model":"none","mode":"trade_manager_brief_read","tools_used":["trade_manager_brief"],"patch_version":PATCH_VERSION}

def install_sales_fulfillment(worker_cls:type)->None:
    if getattr(worker_cls,"_sales_fulfillment_v1_installed",False):return
    original=worker_cls.process_agent
    def patched(self:Any,job:dict[str,Any],tools_desc:list[dict[str,Any]]):
        p=str(job.get("prompt") or "");available={str(x.get("name") or "") for x in tools_desc if isinstance(x,dict)}
        if is_delivery(p) and {"search_sales_documents","search_warehouses","sales_fulfillment","deliver_sales_stock"}.issubset(available):return process_delivery(self,job,p)
        if is_reserve(p) and {"search_sales_documents","search_warehouses","sales_fulfillment","reserve_sales_stock"}.issubset(available):return process_reserve(self,job,p)
        if is_margin(p) and {"search_sales_documents","sales_margin_summary"}.issubset(available):return process_margin(self,job,p)
        if is_fulfillment(p) and {"search_sales_documents","sales_fulfillment"}.issubset(available):return process_fulfillment(self,job,p)
        if is_manager_brief(p) and "trade_manager_brief" in available:return process_brief(self,job)
        return original(self,job,tools_desc)
    worker_cls.process_agent=patched
    worker_cls._sales_fulfillment_v1_installed=True
    worker_cls._sales_fulfillment_v1_original_process_agent=original
