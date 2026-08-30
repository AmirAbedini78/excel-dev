#!/usr/bin/env python3
from __future__ import annotations
import hashlib,json,re
from typing import Any
DIGITS=str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩","01234567890123456789")
PATCH_VERSION="v10.4-crm-lite-r1"
def norm(x:Any)->str:
    return re.sub(r"\s+"," ",str(x or "").translate(DIGITS).replace("ي","ی").replace("ك","ک").replace("\u200c"," ")).strip().lower()
def rows(x:Any)->list[dict[str,Any]]:
    if isinstance(x,dict) and isinstance(x.get("rows"),list):return [r for r in x["rows"] if isinstance(r,dict)]
    if isinstance(x,list):return [r for r in x if isinstance(r,dict)]
    return []
def stable(j:int,l:str,v:Any)->str:
    raw=json.dumps(v,ensure_ascii=False,sort_keys=True,separators=(",",":")) if not isinstance(v,str) else v
    return f"job{j}-{l}-"+hashlib.sha256(raw.encode()).hexdigest()[:16]
def quoted_after(p:str,l:str)->str:
    m=re.search(re.escape(l)+r"\s*[«\"']([^»\"'\r\n]{1,190})[»\"']",str(p or ""),re.I);return m.group(1).strip() if m else ""
def customer(p:str)->str:return quoted_after(p,"مشتری") or quoted_after(p,"طرف حساب")
def unique(c:list[dict[str,Any]],q:str):
    n=norm(q);e=[r for r in c if any(norm(r.get(k))==n for k in ("name","code") if r.get(k) is not None)]
    if len(e)==1:return e[0]
    return c[0] if len(c)==1 else None
def blocked(t,tools,m):return t,{"provider":"deterministic","model":"none","mode":m,"tools_used":tools,"patch_version":PATCH_VERSION}
def find_party(w,j,q,tools):
    r=w.tool(j,"search_parties",{"query":q},stable(int(j["id"]),"crm-party",q));tools.append("search_parties");return unique(rows(r),q)
def date_token(p):
    m=re.search(r"(?<!\d)(1[34]\d{2}[/-]\d{1,2}[/-]\d{1,2}|20\d{2}-\d{1,2}-\d{1,2})(?!\d)",str(p or "").translate(DIGITS));return m.group(1) if m else ""
def amount(p):
    s=str(p or "").translate(DIGITS).replace(",","");m=re.search(r"مبلغ\s+(\d+(?:\.\d+)?)",s);return float(m.group(1)) if m else 0.0
def prob(p):
    s=str(p or "").translate(DIGITS);m=re.search(r"(\d+(?:\.\d+)?)\s*%",s);return max(0,min(100,float(m.group(1)))) if m else 50.0
def action(n):return any(x in n for x in ("آماده کن","ثبت کن","ایجاد کن","بساز"))
def is360(p):
    n=norm(p);return "مشتری" in n and any(x in n for x in ("360","۳۶۰","نمای مشتری","وضعیت مشتری"))
def ispipe(p):
    n=norm(p);return any(x in n for x in ("pipeline","پایپ لاین","پایپ‌لاین")) and any(x in n for x in ("فروش","crm"))
def isfollow(p):
    n=norm(p);return "پیگیری" in n and any(x in n for x in ("امروز","هفته","crm","سررسید")) and not action(n)
def isact(p):
    n=norm(p);return "مشتری" in n and any(x in n for x in ("پیگیری","تماس","جلسه","ایمیل","پیام")) and action(n)
def isopp(p):
    n=norm(p);return "مشتری" in n and "فرصت" in n and action(n)
def process360(w,j,p):
    tools=[];q=customer(p)
    if not q:return blocked("نام مشتری را داخل « » مشخص کن.",tools,"crm_customer_360_read")
    party=find_party(w,j,q,tools)
    if not party:return blocked(f"مشتری «{q}» یکتا پیدا نشد.",tools,"crm_customer_360_read")
    w.trace(j,"grounded_read","Grounded CRM Customer 360",{})
    d=w.tool(j,"crm_customer_360",{"party_id":int(party["id"])},stable(int(j["id"]),"crm360",party["id"]));tools.append("crm_customer_360")
    f=d.get("financial",{});c=d.get("crm",{});pp=d.get("party",{})
    out=[f"Customer 360 | {pp.get('name') or q}",f"• مانده: {float(f.get('current_balance_irr') or 0):,.0f} ریال | {f.get('balance_nature') or '-'}",f"• فروش: {float(f.get('recorded_sales_net_irr') or 0):,.0f} ریال | {int(f.get('sales_document_count') or 0)} سند",f"• تحویل‌نشده: {float(f.get('outstanding_sales_quantity') or 0):g}",f"• Pipeline باز: {float(c.get('open_pipeline_irr') or 0):,.0f} ریال | وزنی {float(c.get('weighted_pipeline_irr') or 0):,.0f} ریال"]
    nxt=c.get("next_followup");out.append(f"• پیگیری بعدی: {nxt.get('due_date')} | {nxt.get('subject')}" if isinstance(nxt,dict) else "• پیگیری بعدی: ثبت نشده")
    w.trace(j,"grounded_read_complete","Grounded CRM Customer 360 completed",{})
    return "\n".join(out),{"provider":"deterministic","model":"none","mode":"crm_customer_360_read","tools_used":tools,"patch_version":PATCH_VERSION}
def process_pipeline(w,j):
    w.trace(j,"grounded_read","Grounded CRM pipeline",{});d=w.tool(j,"crm_pipeline_summary",{},stable(int(j["id"]),"crm-pipeline","all"))
    out=[f"CRM Pipeline | باز {int(d.get('open_count') or 0)} | {float(d.get('open_amount_irr') or 0):,.0f} ریال | وزنی {float(d.get('weighted_amount_irr') or 0):,.0f} ریال"]
    for r in (d.get("rows") or [])[:10]:out.append(f"• {r.get('stage')} | {int(r.get('opportunity_count') or 0)} | {float(r.get('amount_irr') or 0):,.0f} ریال")
    w.trace(j,"grounded_read_complete","Grounded CRM pipeline completed",{});return "\n".join(out),{"provider":"deterministic","model":"none","mode":"crm_pipeline_read","tools_used":["crm_pipeline_summary"],"patch_version":PATCH_VERSION}
def process_follow(w,j):
    w.trace(j,"grounded_read","Grounded CRM follow-up queue",{});d=w.tool(j,"crm_followup_queue",{"days":7},stable(int(j["id"]),"crm-follow",7))
    out=[f"پیگیری CRM | عقب‌افتاده {int(d.get('overdue_count') or 0)} | امروز {int(d.get('today_count') or 0)} | آینده {int(d.get('upcoming_count') or 0)}"]
    for r in (d.get("rows") or [])[:10]:out.append(f"• {r.get('due_date')} | {r.get('party_name')} | {r.get('subject')} | {r.get('bucket')}")
    w.trace(j,"grounded_read_complete","Grounded CRM follow-up queue completed",{});return "\n".join(out),{"provider":"deterministic","model":"none","mode":"crm_followup_read","tools_used":["crm_followup_queue"],"patch_version":PATCH_VERSION}
def process_activity(w,j,p):
    tools=[];q=customer(p);subject=quoted_after(p,"موضوع");due=date_token(p);w.trace(j,"guarded_route","CRM activity -> guarded proposal workflow",{})
    if not q or not subject:return blocked("مشتری و موضوع را داخل « » مشخص کن؛ Proposal ساخته نشد.",tools,"guarded_crm_activity_blocked")
    party=find_party(w,j,q,tools)
    if not party:return blocked(f"مشتری «{q}» یکتا پیدا نشد.",tools,"guarded_crm_activity_blocked")
    n=norm(p);typ="call" if "تماس" in n else ("meeting" if "جلسه" in n else ("email" if "ایمیل" in n else ("message" if "پیام" in n else "task")))
    args={"party_id":int(party["id"]),"activity_type":typ,"subject":subject}
    if due:args["due_date"]=due
    w.trace(j,"proposal_request","Creating CRM activity proposal",{"human_approval_required":True});pr=w.tool(j,"create_crm_activity",args,stable(int(j["id"]),"crm-act",args));tools.append("create_crm_activity")
    if not isinstance(pr,dict) or int(pr.get("proposal_id") or 0)<=0:return blocked("Proposal پیگیری CRM ساخته نشد.",tools,"guarded_crm_activity_blocked")
    pid=int(pr["proposal_id"]);w.trace(j,"proposal_created","CRM activity proposal created",{"proposal_id":pid,"human_approval_required":True})
    return f"Proposal #{pid} برای پیگیری «{subject}» آماده شد. تا تأیید انسانی ثبت نمی‌شود.",{"provider":"guarded_tool_orchestrator","model":"none","mode":"guarded_crm_activity_proposal","tools_used":tools,"proposal_id":pid,"proposal_status":"awaiting_human_approval","awaiting_human_approval":True,"patch_version":PATCH_VERSION}
def process_opp(w,j,p):
    tools=[];q=customer(p);title=quoted_after(p,"فرصت فروش") or quoted_after(p,"فرصت");due=date_token(p);w.trace(j,"guarded_route","CRM opportunity -> guarded proposal workflow",{})
    if not q or not title:return blocked("مشتری و عنوان فرصت را داخل « » مشخص کن؛ Proposal ساخته نشد.",tools,"guarded_crm_opportunity_blocked")
    party=find_party(w,j,q,tools)
    if not party:return blocked(f"مشتری «{q}» یکتا پیدا نشد.",tools,"guarded_crm_opportunity_blocked")
    args={"party_id":int(party["id"]),"title":title,"stage":"qualification","amount_irr":amount(p),"probability":prob(p)}
    if due:args["expected_close_date"]=due
    w.trace(j,"proposal_request","Creating CRM opportunity proposal",{"human_approval_required":True});pr=w.tool(j,"create_crm_opportunity",args,stable(int(j["id"]),"crm-opp",args));tools.append("create_crm_opportunity")
    if not isinstance(pr,dict) or int(pr.get("proposal_id") or 0)<=0:return blocked("Proposal فرصت CRM ساخته نشد.",tools,"guarded_crm_opportunity_blocked")
    pid=int(pr["proposal_id"]);w.trace(j,"proposal_created","CRM opportunity proposal created",{"proposal_id":pid,"human_approval_required":True})
    return f"Proposal #{pid} برای فرصت «{title}» آماده شد. تا تأیید انسانی ثبت نمی‌شود.",{"provider":"guarded_tool_orchestrator","model":"none","mode":"guarded_crm_opportunity_proposal","tools_used":tools,"proposal_id":pid,"proposal_status":"awaiting_human_approval","awaiting_human_approval":True,"patch_version":PATCH_VERSION}
def install_crm_lite(worker_cls:type)->None:
    if getattr(worker_cls,"_crm_lite_v1_installed",False):return
    original=worker_cls.process_agent
    def patched(self,j,tools_desc):
        p=str(j.get("prompt") or "");available={str(x.get("name") or "") for x in tools_desc if isinstance(x,dict)}
        if isopp(p) and {"search_parties","create_crm_opportunity"}.issubset(available):return process_opp(self,j,p)
        if isact(p) and {"search_parties","create_crm_activity"}.issubset(available):return process_activity(self,j,p)
        if is360(p) and {"search_parties","crm_customer_360"}.issubset(available):return process360(self,j,p)
        if ispipe(p) and "crm_pipeline_summary" in available:return process_pipeline(self,j)
        if isfollow(p) and "crm_followup_queue" in available:return process_follow(self,j)
        return original(self,j,tools_desc)
    worker_cls.process_agent=patched;worker_cls._crm_lite_v1_installed=True;worker_cls._crm_lite_v1_original_process_agent=original
