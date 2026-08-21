#!/usr/bin/env python3
from __future__ import annotations
import json,re,time
from typing import Any

PATCH_VERSION="v8.4.0"
DIGITS=str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩","01234567890123456789")
WRITE=("بساز","ایجاد کن","ثبت کن","حذف کن","ویرایش کن","تغییر بده","نهایی کن","تایید کن","تأیید کن","create","delete","update","approve")
PERIOD=("امروز","دیروز","این هفته","هفته قبل","هفته گذشته","این ماه","ماه قبل","ماه گذشته","این سال","سال قبل","سال گذشته","مرداد","شهریور","مهر","آبان","آذر","دی","بهمن","اسفند","فروردین","اردیبهشت","خرداد","تیر")

def norm(x:Any)->str:
    s=str(x or "").translate(DIGITS).replace("ي","ی").replace("ك","ک").replace("\u200c"," ")
    return re.sub(r"\s+"," ",s).strip().lower()

def money(x:Any)->str:
    try:return f"{float(x):,.0f} ریال"
    except:return "نامشخص"

def quoted(p:str)->str:
    m=re.search(r"[«\"']([^»\"'\r\n]{2,160})[»\"']",str(p or ""))
    return m.group(1).strip() if m else ""

def limit_of(p:str,default=5)->int:
    s=str(p or "").translate(DIGITS)
    for pat in (r"(?:آخرین|اخیر)\s+(\d{1,2})\b",r"(\d{1,2})\s+(?:فروش|خرید|فاکتور|رکورد)\b"):
        m=re.search(pat,s)
        if m:return max(1,min(20,int(m.group(1))))
    words={"یک":1,"دو":2,"سه":3,"چهار":4,"پنج":5,"شش":6,"هفت":7,"هشت":8,"نه":9,"ده":10}
    n=norm(p)
    for w,v in words.items():
        if re.search(rf"\b{w}\s+(?:فروش|خرید|فاکتور|رکورد)",n): return v
    return default

def has_period(p:str)->bool:
    n=norm(p);return any(norm(x) in n for x in PERIOD)

def route(p:str):
    n=norm(p)
    if not n or any(x in n for x in WRITE):return None
    if any(x in n for x in ("تحلیل عمیق","ریسک","سناریو","پیش بینی","پیش‌بینی")):return None
    q=quoted(p)
    if any(x in n for x in ("مانده حساب","گردش حساب","گردش مشتری","مانده مشتری","دفتر مشتری")):
        return {"intent":"party_ledger","query":q,"limit":5}
    if ("مشتری" in n or "طرف حساب" in n or "تامین کننده" in n or "تأمین کننده" in n) and any(x in n for x in ("پیدا","جستجو","مشخصات","اطلاعات")):
        return {"intent":"party_search","query":q,"limit":5}
    if any(x in n for x in ("کالا","محصول","آیتم","بارکد")) and any(x in n for x in ("پیدا","جستجو","مشخصات","اطلاعات")):
        return {"intent":"item_search","query":q,"limit":5}
    if "تراز آزمایشی" in n or ("تراز" in n and any(x in n for x in ("بدهکار","بستانکار","حساب"))):
        return {"intent":"trial_balance","query":"","limit":5}
    recent=any(x in n for x in ("آخرین","اخیر","جدیدترین")); sales="فروش" in n; buys="خرید" in n
    if recent and sales and buys:return {"intent":"recent_both","query":"","limit":limit_of(p)}
    if recent and sales:return {"intent":"recent_sales","query":"","limit":limit_of(p)}
    if recent and buys:return {"intent":"recent_purchases","query":"","limit":limit_of(p)}
    total=any(x in n for x in ("مجموع","کل","چقدر","چقدره","مقدار","جمع"))
    if total and sales and buys:return {"intent":"totals","query":"","limit":5}
    if total and sales:return {"intent":"sales_total","query":"","limit":5}
    if total and buys:return {"intent":"purchase_total","query":"","limit":5}
    if any(x in n for x in ("وضعیت شرکت","خلاصه شرکت","آمار شرکت","اطلاعات شرکت")):
        return {"intent":"company_snapshot","query":"","limit":5}
    return None

def rows(x):
    if isinstance(x,list):return [r for r in x if isinstance(r,dict)]
    if isinstance(x,dict):
        for k in ("rows","items","results","data"):
            if isinstance(x.get(k),list):return [r for r in x[k] if isinstance(r,dict)]
    return []

def unique_entity(rs,q):
    nq=norm(q); exact=[]; contains=[]
    for r in rs:
        vals=[norm(r.get(k)) for k in ("name","code","national_id","mobile","barcode") if r.get(k)]
        if any(v==nq for v in vals):exact.append(r)
        elif norm(r.get("name")) and (nq in norm(r.get("name")) or norm(r.get("name")) in nq):contains.append(r)
    pool=exact or contains; d={}
    for r in pool:
        try:i=int(r.get("id"))
        except:continue
        if i>0:d[i]=r
    if len(d)==1:return next(iter(d.values())),""
    return None,("not_found" if not d else "ambiguous")

def parse_json(text):
    s=str(text or "").strip();a=s.find("{");b=s.rfind("}")
    if a<0 or b<a:raise ValueError("intent_json_missing")
    x=json.loads(s[a:b+1])
    if not isinstance(x,dict):raise ValueError("intent_json_root")
    return x

def llm_plan(worker,job,prompt):
    model=worker.model_for("agent")
    system=("Return ONLY JSON for a READ-ONLY ERP intent. Allowed intents: company_snapshot,sales_total,purchase_total,totals,"
            "recent_sales,recent_purchases,recent_both,trial_balance,party_search,party_ledger,item_search,unsupported. "
            "Never output database IDs, facts or financial numbers. For entity intents, query must be copied verbatim from the user prompt. "
            "Use unsupported for writes or date-range requests. Schema: {\"intent\":\"...\",\"query\":\"\",\"limit\":5}.")
    worker.trace(job,"read_intent_parse",f"Parsing read intent with {model}",{"model":model,"started_epoch":time.time()})
    r=worker.ollama_chat(job,0,[{"role":"system","content":system},{"role":"user","content":prompt}],[],fast=True,model=model,num_ctx=1024,num_predict=80,temperature=0.0,timeout_seconds=90)
    x=parse_json((r.get("message") or {}).get("content"))
    allowed={"company_snapshot","sales_total","purchase_total","totals","recent_sales","recent_purchases","recent_both","trial_balance","party_search","party_ledger","item_search","unsupported"}
    intent=str(x.get("intent") or "")
    if intent not in allowed:raise ValueError("intent_not_allowed")
    q=str(x.get("query") or "").strip()
    if intent in {"party_search","party_ledger","item_search"} and (not q or norm(q) not in norm(prompt)):raise ValueError("query_not_grounded")
    try:lim=max(1,min(20,int(x.get("limit") or 5)))
    except:lim=5
    return {"intent":intent,"query":q,"limit":lim},dict(r.get("_metrics") or {}),model

def snapshot_text(d,mode):
    d=d if isinstance(d,dict) else {}; c=d.get("company") or {};cnt=d.get("counts") or {};t=d.get("totals") or {};name=c.get("name") or "شرکت انتخاب‌شده"
    if mode=="sales_total":return f"فروش ثبت‌شده {name}: {money(t.get('sales'))}"
    if mode=="purchase_total":return f"خرید ثبت‌شده {name}: {money(t.get('purchases'))}"
    if mode=="totals":return f"فروش ثبت‌شده {name}: {money(t.get('sales'))}\nخرید ثبت‌شده {name}: {money(t.get('purchases'))}"
    return (f"خلاصه {name}\n• فروش ثبت‌شده: {money(t.get('sales'))}\n• خرید ثبت‌شده: {money(t.get('purchases'))}\n"
            f"• طرف‌حساب‌ها: {int(cnt.get('parties') or 0)}\n• کالا/خدمت: {int(cnt.get('items') or 0)}\n"
            f"• فاکتورهای فروش: {int(cnt.get('sales') or 0)}\n• اسناد خرید: {int(cnt.get('purchases') or 0)}\n• اسناد حسابداری: {int(cnt.get('vouchers') or 0)}")

def recent_text(rs,title,lim):
    rs=rs[:lim]
    if not rs:return title+": رکوردی پیدا نشد."
    out=[title]
    for r in rs:out.append(f"• {r.get('document_date') or '-'} | {r.get('document_no') or '-'} | {r.get('party_name') or '-'} | {money(r.get('net_total'))} | {r.get('workflow_status') or '-'}")
    return "\n".join(out)

def trial_text(rs):
    debit=sum(float(r.get("debit") or 0) for r in rs); credit=sum(float(r.get("credit") or 0) for r in rs); diff=debit-credit
    nz=[r for r in rs if abs(float(r.get("balance") or 0))>.01];nz.sort(key=lambda r:abs(float(r.get("balance") or 0)),reverse=True)
    out=["تراز آزمایشی",f"• جمع بدهکار: {money(debit)}",f"• جمع بستانکار: {money(credit)}",f"• اختلاف: {money(diff)}",f"• وضعیت جمع: {'متوازن' if abs(diff)<=.01 else 'دارای مغایرت'}"]
    for r in nz[:8]:out.append(f"• {r.get('code') or '-'} {r.get('name') or '-'}: {money(r.get('balance'))}")
    return "\n".join(out)

def execute(worker,job,plan,source="deterministic",model="none",metrics=None):
    metrics=metrics or {}; intent=plan["intent"];q=str(plan.get("query") or "");lim=max(1,min(20,int(plan.get("limit") or 5)));used=[]
    if has_period(str(job.get("prompt") or "")):
        text="این درخواست بازه زمانی مشخص دارد، اما Toolهای فعلی هنوز فیلتر بازه تاریخ را پشتیبانی نمی‌کنند؛ برای جلوگیری از گزارش اشتباه، عدد تقریبی یا ساختگی ارائه نشد."
        return text,meta(worker,"grounded_read_unsupported",used,source,model,metrics,{"blocked_reason":"date_range_tool_not_available","intent":intent})
    worker.trace(job,"grounded_read_route",f"Grounded read intent: {intent}",{"intent":intent,"parser_source":source})
    if intent in {"company_snapshot","sales_total","purchase_total","totals"}:
        d=worker.tool(job,"company_snapshot",{},f"job{job['id']}-read-company");used.append("company_snapshot");text=snapshot_text(d,intent)
    elif intent=="recent_sales":
        d=worker.tool(job,"recent_sales",{"limit":lim},f"job{job['id']}-read-sales");used.append("recent_sales");text=recent_text(rows(d),f"آخرین {lim} فروش",lim)
    elif intent=="recent_purchases":
        d=worker.tool(job,"recent_purchases",{"limit":lim},f"job{job['id']}-read-purchases");used.append("recent_purchases");text=recent_text(rows(d),f"آخرین {lim} خرید",lim)
    elif intent=="recent_both":
        a=worker.tool(job,"recent_sales",{"limit":lim},f"job{job['id']}-read-sales");b=worker.tool(job,"recent_purchases",{"limit":lim},f"job{job['id']}-read-purchases");used+=["recent_sales","recent_purchases"];text=recent_text(rows(a),f"آخرین {lim} فروش",lim)+"\n\n"+recent_text(rows(b),f"آخرین {lim} خرید",lim)
    elif intent=="trial_balance":
        d=worker.tool(job,"trial_balance",{},f"job{job['id']}-read-trial");used.append("trial_balance");text=trial_text(rows(d))
    elif intent=="party_search":
        if not q:text="نام/کد طرف‌حساب در درخواست مشخص نیست."
        else:
            d=worker.tool(job,"search_parties",{"query":q},f"job{job['id']}-read-party");used.append("search_parties");rs=rows(d)
            text=(f"طرف‌حساب «{q}» پیدا نشد." if not rs else "\n".join([f"نتایج طرف‌حساب برای «{q}»:"]+[f"• {r.get('name') or '-'} | کد: {r.get('code') or '-'} | نوع: {r.get('party_type') or '-'} | موبایل: {r.get('mobile') or '-'}" for r in rs[:10]]))
    elif intent=="item_search":
        if not q:text="نام/کد کالا یا خدمت در درخواست مشخص نیست."
        else:
            d=worker.tool(job,"search_items",{"query":q},f"job{job['id']}-read-item");used.append("search_items");rs=rows(d)
            text=(f"کالا/خدمت «{q}» پیدا نشد." if not rs else "\n".join([f"نتایج کالا/خدمت برای «{q}»:"]+[f"• {r.get('name') or '-'} | کد: {r.get('code') or '-'} | نوع: {r.get('item_type') or '-'} | بارکد: {r.get('barcode') or '-'}" for r in rs[:10]]))
    elif intent=="party_ledger":
        if not q:text="نام/کد طرف‌حساب برای دریافت گردش حساب مشخص نیست."
        else:
            s=worker.tool(job,"search_parties",{"query":q},f"job{job['id']}-read-ledger-party");used.append("search_parties");party,reason=unique_entity(rows(s),q)
            if party is None:text=f"طرف‌حساب «{q}» {'به‌صورت یکتا پیدا نشد' if reason=='ambiguous' else 'پیدا نشد'}؛ گردش حساب اجرا نشد."
            else:
                pid=int(party["id"]);d=worker.tool(job,"party_ledger",{"party_id":pid},f"job{job['id']}-read-ledger-{pid}");used.append("party_ledger")
                rr=rows(d);out=[f"گردش حساب: {party.get('name') or q}",f"مانده فعلی بر اساس آرتیکل‌های تایید/نهایی: {money((d or {}).get('balance') if isinstance(d,dict) else None)}"]
                for r in rr[-8:]:out.append(f"• {r.get('voucher_date') or '-'} | {r.get('voucher_no') or '-'} | بدهکار {money(r.get('debit'))} | بستانکار {money(r.get('credit'))} | مانده جاری {money(r.get('running_balance'))}")
                text="\n".join(out)
    else:raise RuntimeError("unsupported_grounded_read_intent:"+intent)
    worker.trace(job,"grounded_read_complete","Grounded ERP read completed",{"tools_used":used})
    return text,meta(worker,"grounded_read",used,source,model,metrics,{"intent":intent})

def meta(worker,mode,used,source,model,metrics,extra):
    with worker.progress_lock:trace=list(worker.current_trace[-50:])
    x={"provider":"grounded_read_guard","model":model if source=="llm" else "none","mode":mode,"tools_used":used,"parser_source":source,"metrics":metrics,"trace":trace,"patch_version":PATCH_VERSION}
    x.update(extra);return x

def install_read_guard(cls:type)->None:
    if getattr(cls,"_read_guard_v1_installed",False):return
    original=cls.process_agent
    def patched(self,job,tools_desc):
        prompt=str(job.get("prompt") or "");plan=route(prompt);source="deterministic";model="none";metrics={}
        if plan is None:
            n=norm(prompt)
            if any(x in n for x in WRITE) or any(x in n for x in ("تحلیل عمیق","ریسک","سناریو","پیش بینی","پیش‌بینی")):return original(self,job,tools_desc)
            try:plan,metrics,model=llm_plan(self,job,prompt);source="llm"
            except Exception:return original(self,job,tools_desc)
        if plan.get("intent")=="unsupported":return original(self,job,tools_desc)
        return execute(self,job,plan,source,model,metrics)
    cls.process_agent=patched;cls._read_guard_v1_installed=True;cls._read_guard_original_process_agent=original
