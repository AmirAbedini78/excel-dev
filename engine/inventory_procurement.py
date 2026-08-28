#!/usr/bin/env python3
"""ERPSMART v10.1 Inventory + Procurement deterministic Agent slice."""
from __future__ import annotations
import re, json, hashlib
from typing import Any

DIGITS=str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩","01234567890123456789")
PATCH_VERSION="v10.1-inventory-procurement-r1.1"

def norm(x:Any)->str:
    s=str(x or "").translate(DIGITS).replace("ي","ی").replace("ك","ک").replace("\u200c"," ")
    return re.sub(r"\s+"," ",s).strip().lower()

def number(value:Any)->float:
    try:return float(value or 0)
    except (TypeError,ValueError):return 0.0

def rows(x:Any)->list[dict[str,Any]]:
    if isinstance(x,dict) and isinstance(x.get("rows"),list):return [r for r in x["rows"] if isinstance(r,dict)]
    if isinstance(x,list):return [r for r in x if isinstance(r,dict)]
    return []

def stable(job_id:int,label:str,value:Any)->str:
    raw=json.dumps(value,ensure_ascii=False,sort_keys=True,separators=(",",":")) if not isinstance(value,str) else value
    return f"job{job_id}-{label}-"+hashlib.sha256(raw.encode("utf-8")).hexdigest()[:16]

def resolve_unique(candidates:list[dict[str,Any]],query:str,keys:tuple[str,...])->dict[str,Any]|None:
    q=norm(query);exact=[r for r in candidates if any(norm(r.get(k))==q for k in keys if r.get(k) is not None)]
    if len(exact)==1:return exact[0]
    if len(candidates)==1:return candidates[0]
    return None

def quoted_after(prompt:str,label:str)->str:
    m=re.search(re.escape(label)+r"\s*[«\"']([^»\"'\r\n]{1,190})[»\"']",str(prompt or ""),re.I)
    return m.group(1).strip() if m else ""

def purchase_no(prompt:str)->str:
    q=quoted_after(prompt,"سند خرید")
    if q:return q
    m=re.search(r"\b(?:AI-PUR|PUR)-[A-Za-z0-9_-]{3,120}\b",str(prompt or ""),re.I)
    return m.group(0) if m else ""

def warehouse_query(prompt:str)->str:return quoted_after(prompt,"انبار")

def receipt_line_request(prompt:str)->tuple[float,str]:
    p=str(prompt or "").translate(DIGITS)
    m=re.search(r"(?:دریافت|رسید)\s+(\d+(?:\.\d+)?)\s*(?:عدد|واحد|تا)?\s*[«\"']([^»\"'\r\n]{2,190})[»\"']",p,re.I)
    if m:return float(m.group(1)),m.group(2).strip()
    return 0.0,""

def is_receipt_create(p:str)->bool:
    n=norm(p);return "انبار" in n and "سند خرید" in n and any(x in n for x in ("دریافت","رسید")) and any(x in n for x in ("آماده کن","بساز","ثبت کن","ایجاد کن"))

def is_warehouse_list(p:str)->bool:
    n=norm(p);return "انبار" in n and any(x in n for x in ("لیست","فهرست","چه انبار","انبارهای فعال","انبارها را")) and not is_receipt_create(p)

def is_inventory_read(p:str)->bool:
    n=norm(p);return "موجودی" in n and not any(x in n for x in ("ثبت کن","بساز","ایجاد کن"))

def is_replenishment_read(p:str)->bool:
    n=norm(p);return any(x in n for x in ("نقطه سفارش","زیر حداقل","کمبود موجودی","پیشنهاد خرید","پیشنهاد تامین","پیشنهاد تأمین")) and not is_receipt_create(p)

def is_pipeline_read(p:str)->bool:
    n=norm(p);return ("خرید" in n and any(x in n for x in ("ورودی مورد انتظار","ورودی های باز","ورودی‌های باز","خریدهای باز","باقیمانده دریافت","در انتظار دریافت"))) and not is_receipt_create(p)

def blocked(worker:Any,job:dict[str,Any],text:str,tools:list[str]):
    return text,{"provider":"deterministic","model":"none","mode":"guarded_inventory_receipt_blocked","tools_used":tools,"patch_version":PATCH_VERSION}

def process_warehouse_list(worker:Any,job:dict[str,Any]):
    worker.trace(job,"grounded_read","Grounded warehouse list",{})
    result=worker.tool(job,"search_warehouses",{"query":""},stable(int(job["id"]),"warehouses","all"));rr=rows(result)
    text="انبار فعال پیدا نشد." if not rr else "انبارهای فعال:\n"+"\n".join(f"• {r.get('code') or '-'} | {r.get('name') or '-'} | {r.get('warehouse_type') or '-'}" for r in rr[:30])
    worker.trace(job,"grounded_read_complete","Grounded warehouse list completed",{"rows":len(rr)})
    return text,{"provider":"deterministic","model":"none","mode":"inventory_warehouses_read","tools_used":["search_warehouses"],"patch_version":PATCH_VERSION}

def process_inventory(worker:Any,job:dict[str,Any],prompt:str):
    tools=[];item_id=None;warehouse_id=None;q=""
    qs=re.findall(r"[«\"']([^»\"'\r\n]{2,190})[»\"']",str(prompt or ""));wh=warehouse_query(prompt)
    if wh:
        rw=worker.tool(job,"search_warehouses",{"query":wh},stable(int(job["id"]),"inventory-wh",wh));tools.append("search_warehouses");w=resolve_unique(rows(rw),wh,("name","code"))
        if not w:return f"انبار «{wh}» به‌صورت یکتا پیدا نشد.",{"provider":"deterministic","model":"none","mode":"inventory_position_read","tools_used":tools,"patch_version":PATCH_VERSION}
        warehouse_id=int(w["id"])
    candidates=[x for x in qs if norm(x)!=norm(wh)]
    if candidates:q=candidates[0]
    if q:
        ri=worker.tool(job,"search_items",{"query":q},stable(int(job["id"]),"inventory-item",q));tools.append("search_items");item=resolve_unique(rows(ri),q,("name","code","barcode"))
        if not item:return f"کالا «{q}» به‌صورت یکتا پیدا نشد.",{"provider":"deterministic","model":"none","mode":"inventory_position_read","tools_used":tools,"patch_version":PATCH_VERSION}
        item_id=int(item["id"])
    args={"limit":100};
    if item_id:args["item_id"]=item_id
    if warehouse_id:args["warehouse_id"]=warehouse_id
    worker.trace(job,"grounded_read","Grounded inventory position",{"item_scoped":bool(item_id),"warehouse_scoped":bool(warehouse_id)})
    result=worker.tool(job,"inventory_position",args,stable(int(job["id"]),"inventory-position",args));tools.append("inventory_position");rr=rows(result)
    if not rr:text="موجودی قابل گزارش پیدا نشد."
    else:
        out=["وضعیت موجودی:"]
        for r in rr[:30]:out.append(f"• {r.get('code') or '-'} | {r.get('name') or '-'} | موجود {number(r.get('on_hand')):g} | رزرو {number(r.get('reserved')):g} | قابل استفاده {number(r.get('available')):g} | ورودی مورد انتظار {number(r.get('expected_inbound')):g} | پیش‌بینی {number(r.get('projected_available')):g}")
        text="\n".join(out)
    worker.trace(job,"grounded_read_complete","Grounded inventory position completed",{"rows":len(rr)})
    return text,{"provider":"deterministic","model":"none","mode":"inventory_position_read","tools_used":tools,"patch_version":PATCH_VERSION}

def process_replenishment(worker:Any,job:dict[str,Any]):
    worker.trace(job,"grounded_read","Grounded replenishment risk",{})
    result=worker.tool(job,"replenishment_risk",{"limit":30},stable(int(job["id"]),"replenishment",30));rr=rows(result)
    if not rr:text="کالایی زیر نقطه سفارش ثبت‌شده دیده نشد."
    else:text="کمبود و پیشنهاد تأمین:\n"+"\n".join(f"• {r.get('code') or '-'} | {r.get('name') or '-'} | قابل استفاده {number(r.get('available')):g} | ورودی {number(r.get('expected_inbound')):g} | حداقل {number(r.get('min_stock')):g} | پیشنهاد خرید {number(r.get('suggested_replenishment')):g}" for r in rr[:30])
    worker.trace(job,"grounded_read_complete","Grounded replenishment risk completed",{"rows":len(rr)})
    return text,{"provider":"deterministic","model":"none","mode":"inventory_replenishment_read","tools_used":["replenishment_risk"],"patch_version":PATCH_VERSION}

def process_pipeline(worker:Any,job:dict[str,Any]):
    worker.trace(job,"grounded_read","Grounded procurement inbound pipeline",{})
    result=worker.tool(job,"purchase_pipeline",{"open_only":True,"limit":50},stable(int(job["id"]),"purchase-pipeline",50));rr=rows(result)
    if not rr:text="ورودی خرید بازی وجود ندارد."
    else:text="ورودی‌های مورد انتظار خرید:\n"+"\n".join(f"• {r.get('document_no') or '-'} | {r.get('supplier_name') or '-'} | {r.get('item_name') or '-'} | سفارش {number(r.get('ordered_qty')):g} | پذیرفته {number(r.get('accepted_qty')):g} | باقیمانده {number(r.get('expected_inbound')):g}" for r in rr[:50])
    worker.trace(job,"grounded_read_complete","Grounded procurement inbound pipeline completed",{"rows":len(rr)})
    return text,{"provider":"deterministic","model":"none","mode":"procurement_pipeline_read","tools_used":["purchase_pipeline"],"patch_version":PATCH_VERSION}

def process_receipt(worker:Any,job:dict[str,Any],prompt:str):
    tools=[];docq=purchase_no(prompt);whq=warehouse_query(prompt);qty,itemq=receipt_line_request(prompt)
    worker.trace(job,"guarded_route","Warehouse receipt -> guarded proposal workflow",{})
    if not docq or not whq or qty<=0 or not itemq:return blocked(worker,job,"برای رسید انبار باید سند خرید، مقدار/کالا و انبار صریح باشند؛ هیچ Proposal ساخته نشد.",tools)
    rd=worker.tool(job,"search_purchase_documents",{"query":docq},stable(int(job["id"]),"receipt-doc",docq));tools.append("search_purchase_documents");doc=resolve_unique(rows(rd),docq,("document_no",))
    if not doc:return blocked(worker,job,f"سند خرید «{docq}» به‌صورت یکتا پیدا نشد؛ هیچ Proposal ساخته نشد.",tools)
    rw=worker.tool(job,"search_warehouses",{"query":whq},stable(int(job["id"]),"receipt-wh",whq));tools.append("search_warehouses");wh=resolve_unique(rows(rw),whq,("name","code"))
    if not wh:return blocked(worker,job,f"انبار «{whq}» به‌صورت یکتا پیدا نشد؛ هیچ Proposal ساخته نشد.",tools)
    pp=worker.tool(job,"purchase_pipeline",{"purchase_doc_id":int(doc["id"]),"open_only":True,"limit":100},stable(int(job["id"]),"receipt-pipeline",int(doc["id"])));tools.append("purchase_pipeline");rr=rows(pp)
    matches=[r for r in rr if norm(r.get("item_name"))==norm(itemq) or norm(r.get("item_code"))==norm(itemq)]
    if len(matches)!=1:
        loose=[r for r in rr if norm(itemq) in norm(r.get("item_name")) or norm(r.get("item_name")) in norm(itemq)]
        matches=loose if len(loose)==1 else matches
    if len(matches)!=1:return blocked(worker,job,f"کالای «{itemq}» در ورودی باز این سند به‌صورت یکتا پیدا نشد؛ هیچ Proposal ساخته نشد.",tools)
    line=matches[0];remaining=float(line.get("expected_inbound") or 0)
    if qty>remaining+1e-9:return blocked(worker,job,f"مقدار درخواست‌شده {qty:g} از باقیمانده دریافت {remaining:g} بیشتر است؛ هیچ Proposal ساخته نشد.",tools)
    args={"purchase_doc_id":int(doc["id"]),"warehouse_id":int(wh["id"]),"lines":[{"purchase_line_id":int(line["purchase_line_id"]),"accepted_qty":qty,"rejected_qty":0}]}
    worker.trace(job,"proposal_request","Creating server-side warehouse receipt proposal",{"human_approval_required":True})
    pr=worker.tool(job,"create_warehouse_receipt",args,stable(int(job["id"]),"receipt-proposal",args));tools.append("create_warehouse_receipt")
    if not isinstance(pr,dict) or int(pr.get("proposal_id") or 0)<=0:return blocked(worker,job,"Control Plane ایجاد Proposal رسید انبار را تأیید نکرد؛ هیچ موجودی تغییر نکرد.",tools)
    pid=int(pr["proposal_id"]);worker.trace(job,"proposal_created","Warehouse receipt proposal created; awaiting human approval",{"proposal_id":pid,"human_approval_required":True})
    text=f"Proposal #{pid} برای دریافت {qty:g} × {line.get('item_name') or itemq} از سند {doc.get('document_no') or docq} در انبار {wh.get('name') or whq} آماده شد. تا قبل از تأیید انسانی هیچ رسید یا Stock Movement ساخته نمی‌شود."
    return text,{"provider":"guarded_tool_orchestrator","model":"none","mode":"guarded_inventory_receipt_proposal","tools_used":tools,"proposal_id":pid,"proposal_status":"awaiting_human_approval","awaiting_human_approval":True,"patch_version":PATCH_VERSION}

def install_inventory_procurement(worker_cls:type)->None:
    if getattr(worker_cls,"_inventory_procurement_v1_installed",False):return
    original=worker_cls.process_agent
    def patched(self:Any,job:dict[str,Any],tools_desc:list[dict[str,Any]]):
        prompt=str(job.get("prompt") or "");available={str(x.get("name") or "") for x in tools_desc if isinstance(x,dict)}
        if is_receipt_create(prompt):
            needed={"search_purchase_documents","search_warehouses","purchase_pipeline","create_warehouse_receipt"}
            if not needed.issubset(available):return blocked(self,job,"Toolهای رسید انبار هنوز کامل روی Control Plane فعال نیستند.",[])
            return process_receipt(self,job,prompt)
        if is_warehouse_list(prompt) and "search_warehouses" in available:return process_warehouse_list(self,job)
        if is_replenishment_read(prompt) and "replenishment_risk" in available:return process_replenishment(self,job)
        if is_pipeline_read(prompt) and "purchase_pipeline" in available:return process_pipeline(self,job)
        if is_inventory_read(prompt) and "inventory_position" in available and "search_items" in available:return process_inventory(self,job,prompt)
        return original(self,job,tools_desc)
    worker_cls.process_agent=patched;worker_cls._inventory_procurement_v1_installed=True;worker_cls._inventory_procurement_v1_original_process_agent=original
