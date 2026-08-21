#!/usr/bin/env python3
"""ERPSMART v8.5.0 parameterized grounded read planner.

Financial values and ERP identifiers always come from server tools.
Relative Persian periods are represented by safe enums and resolved on cPanel.
No arbitrary SQL/table/column names can originate from the model or user prompt.
"""
from __future__ import annotations
import json,re,time
from typing import Any

PATCH_VERSION="v8.5.0"
DIGITS=str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩","01234567890123456789")
WRITE=("بساز","ایجاد کن","ثبت کن","حذف کن","ویرایش کن","تغییر بده","نهایی کن","تایید کن","تأیید کن","create","delete","update","approve")
MONTHS={"فروردین":1,"اردیبهشت":2,"خرداد":3,"تیر":4,"مرداد":5,"شهریور":6,"مهر":7,"آبان":8,"آذر":9,"دی":10,"بهمن":11,"اسفند":12}
NUMWORDS={"یک":1,"دو":2,"سه":3,"چهار":4,"پنج":5,"شش":6,"هفت":7,"هشت":8,"نه":9,"ده":10,"یازده":11,"دوازده":12}

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
    for pat in (r"(?:آخرین|اخیر|برتر)\s+(\d{1,2})\b",r"(\d{1,2})\s+(?:فروش|خرید|فاکتور|رکورد|مشتری|کالا|ماه)\b"):
        m=re.search(pat,s)
        if m:return max(1,min(50,int(m.group(1))))
    n=norm(p)
    for w,v in NUMWORDS.items():
        if re.search(rf"\b{w}\s+(?:فروش|خرید|فاکتور|رکورد|مشتری|کالا|ماه)",n):return v
    return default

def month_count_of(p:str,default=3)->int:
    s=str(p or "").translate(DIGITS);n=norm(s)
    m=re.search(r"(\d{1,2})\s*ماه",s)
    if m:return max(1,min(24,int(m.group(1))))
    for w,v in NUMWORDS.items():
        if re.search(rf"\b{w}\s*ماه",n):return max(1,min(24,v))
    return default

def status_of(p:str)->str:
    n=norm(p)
    if "draft" in n or "پیش نویس" in n or "پیش‌نویس" in n:return "draft"
    if "final" in n or "نهایی" in n:return "final"
    if "approved" in n or "تایید شده" in n or "تأیید شده" in n:return "approved"
    return "all"

def period_of(p:str)->dict[str,Any]:
    s=str(p or "").translate(DIGITS);n=norm(s)
    # explicit Jalali/Gregorian date range
    dates=re.findall(r"(?<!\d)((?:13|14)\d{2}[/-]\d{1,2}[/-]\d{1,2}|20\d{2}-\d{1,2}-\d{1,2})(?!\d)",s)
    if len(dates)>=2 and any(x in n for x in ("از ","تا ","بازه","بین")):
        return {"period":"custom","date_from":dates[0],"date_to":dates[1]}
    if "ماه قبل" in n or "ماه گذشته" in n:return {"period":"previous_jalali_month"}
    if "این ماه" in n or "ماه جاری" in n:return {"period":"current_jalali_month"}
    if "سال قبل" in n or "سال گذشته" in n:return {"period":"previous_jalali_year"}
    if "این سال" in n or "سال جاری" in n:return {"period":"current_jalali_year"}
    if "ماه اخیر" in n or "ماه گذشته" in n or re.search(r"(?:\d+|یک|دو|سه|چهار|پنج|شش|هفت|هشت|نه|ده|یازده|دوازده)\s*ماه",n):
        return {"period":"rolling_jalali_months","months":month_count_of(p)}
    # Named Jalali month plus explicit year, e.g. مرداد 1405
    for name,mno in MONTHS.items():
        if name in n:
            ym=re.search(rf"{name}\s*((?:13|14)\d{{2}})",s)
            if ym:
                y=int(ym.group(1))
                return {"period":"custom_jalali_month","jalali_year":y,"jalali_month":mno}
    return {"period":"all"}

def group_of(p:str)->str:
    n=norm(p)
    if any(x in n for x in ("تفکیک مشتری","به تفکیک مشتری","بر اساس مشتری","به ازای مشتری","مشتری برتر","بیشترین مشتری","برترین مشتری")):return "party"
    if any(x in n for x in ("تفکیک کالا","به تفکیک کالا","بر اساس کالا","به ازای کالا","کالای برتر","بیشترین کالا","برترین کالا","محصول")) and "پیدا" not in n:return "item"
    if any(x in n for x in ("تفکیک وضعیت","بر اساس وضعیت","draft و final","final و draft","پیش نویس و نهایی","پیش‌نویس و نهایی")):return "status"
    if any(x in n for x in ("ماه به ماه","ماه‌به‌ماه","تفکیک ماه","بر اساس ماه")):return "jalali_month"
    return "none"

def analytics_plan(p:str,kind:str)->dict[str,Any]:
    n=norm(p)
    top=any(x in n for x in ("بیشترین مشتری","برترین مشتری","مشتری برتر","بیشترین کالا","برترین کالا","کالای برتر"))
    group=group_of(p)
    status="all" if group=="status" else status_of(p)
    x={"intent":"document_analytics","kind":kind,"group_by":group,"workflow_status":status,"limit":1 if top else limit_of(p,10)}
    x.update(period_of(p))
    return x

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

    sales="فروش" in n or "فاکتور فروش" in n
    buys="خرید" in n or "فاکتور خرید" in n

    # deterministic two-period comparison
    if "مقایسه" in n and (sales or buys) and (("این ماه" in n and ("ماه قبل" in n or "ماه گذشته" in n))):
        return {"intent":"compare_periods","kind":"sales" if sales else "purchases",
                "left_period":"current_jalali_month","right_period":"previous_jalali_month",
                "workflow_status":status_of(p)}

    # Analytics cases: period, grouping, top customer/item, month trend, status split.
    analytical_hint=(
        period_of(p).get("period")!="all" or group_of(p)!="none" or
        any(x in n for x in ("بیشترین مشتری","برترین مشتری","مشتری برتر","بیشترین کالا","برترین کالا","کالای برتر"))
    )
    if sales and analytical_hint:return analytics_plan(p,"sales")
    if buys and analytical_hint:return analytics_plan(p,"purchases")
    if not sales and not buys and any(x in n for x in ("بیشترین مشتری","برترین مشتری","مشتری برتر")):
        return analytics_plan(p,"sales")

    recent=any(x in n for x in ("آخرین","اخیر","جدیدترین"))
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

def split_multi(prompt:str)->list[str]:
    text=str(prompt or "").strip()
    if not text:return []
    # Numbered lines such as "1) ..." / "2- ...".
    matches=list(re.finditer(r"(?m)^\s*\d{1,2}\s*[\)\.\-]\s*",text))
    if len(matches)>=2:
        out=[]
        for i,m in enumerate(matches):
            start=m.end();end=matches[i+1].start() if i+1<len(matches) else len(text)
            part=text[start:end].strip()
            if part:out.append(part)
        return out
    # Bullet lines only when at least two non-empty bullet entries exist.
    parts=[re.sub(r"^\s*[-•]\s*","",x).strip() for x in text.splitlines() if re.match(r"^\s*[-•]\s*\S",x)]
    return parts if len(parts)>=2 else [text]

def rows(x):
    if isinstance(x,list):return [r for r in x if isinstance(r,dict)]
    if isinstance(x,dict):
        for k in ("rows","items","results","data"):
            if isinstance(x.get(k),list):return [r for r in x[k] if isinstance(r,dict)]
    return []

def unique_entity(rs,q):
    nq=norm(q);exact=[];contains=[]
    for r in rs:
        vals=[norm(r.get(k)) for k in ("name","code","national_id","mobile","barcode") if r.get(k)]
        if any(v==nq for v in vals):exact.append(r)
        elif norm(r.get("name")) and (nq in norm(r.get("name")) or norm(r.get("name")) in nq):contains.append(r)
    pool=exact or contains;d={}
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
    # Fallback remains intentionally conservative. Parameterized financial queries
    # are handled deterministically above; Qwen only classifies old read intents.
    model=worker.model_for("agent")
    system=("Return ONLY JSON for a READ-ONLY ERP intent. Allowed intents: company_snapshot,sales_total,purchase_total,totals,"
            "recent_sales,recent_purchases,recent_both,trial_balance,party_search,party_ledger,item_search,unsupported. "
            "Never output database IDs, facts or financial numbers. For entity intents, query must be copied verbatim from the user prompt. "
            "Use unsupported for writes or date/grouped analytics that you cannot classify. Schema: {\"intent\":\"...\",\"query\":\"\",\"limit\":5}.")
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
    d=d if isinstance(d,dict) else {};c=d.get("company") or {};cnt=d.get("counts") or {};t=d.get("totals") or {};name=c.get("name") or "شرکت انتخاب‌شده"
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
    debit=sum(float(r.get("debit") or 0) for r in rs);credit=sum(float(r.get("credit") or 0) for r in rs);diff=debit-credit
    nz=[r for r in rs if abs(float(r.get("balance") or 0))>.01];nz.sort(key=lambda r:abs(float(r.get("balance") or 0)),reverse=True)
    out=["تراز آزمایشی",f"• جمع بدهکار: {money(debit)}",f"• جمع بستانکار: {money(credit)}",f"• اختلاف: {money(diff)}",f"• وضعیت جمع: {'متوازن' if abs(diff)<=.01 else 'دارای مغایرت'}"]
    for r in nz[:8]:out.append(f"• {r.get('code') or '-'} {r.get('name') or '-'}: {money(r.get('balance'))}")
    return "\n".join(out)

def analytics_args(plan:dict[str,Any])->dict[str,Any]:
    allowed={"kind","period","months","date_from","date_to","jalali_year","jalali_month","workflow_status","group_by","limit"}
    return {k:v for k,v in plan.items() if k in allowed and v not in (None,"")}

def analytics_text(d:Any)->str:
    x=d if isinstance(d,dict) else {};kind=str(x.get("kind") or "sales")
    title="فروش" if kind=="sales" else "خرید";period=x.get("period") if isinstance(x.get("period"),dict) else {}
    summary=x.get("summary") if isinstance(x.get("summary"),dict) else {};groups=x.get("groups") if isinstance(x.get("groups"),list) else []
    label=str(period.get("label") or "همه دوره‌ها")
    out=[f"گزارش {title} — {label}",
         f"• تعداد اسناد: {int(summary.get('document_count') or 0)}",
         f"• مبلغ قبل از تخفیف: {money(summary.get('total_before_discount'))}",
         f"• تخفیف: {money(summary.get('discount_total'))}",
         f"• مالیات: {money(summary.get('tax_total'))}",
         f"• مبلغ خالص: {money(summary.get('net_total'))}"]
    if groups:
        out.append("تفکیک:")
        for g in groups:
            name=str(g.get("label") or g.get("name") or g.get("key") or "-")
            if "line_total" in g:
                out.append(f"• {name}: {money(g.get('line_total'))} | تعداد {float(g.get('quantity') or 0):g} | {int(g.get('document_count') or 0)} سند")
            else:
                out.append(f"• {name}: {money(g.get('net_total'))} | {int(g.get('document_count') or 0)} سند")
    return "\n".join(out)

def compare_text(left:Any,right:Any,kind:str)->str:
    l=left if isinstance(left,dict) else {};r=right if isinstance(right,dict) else {}
    ls=l.get("summary") if isinstance(l.get("summary"),dict) else {};rs=r.get("summary") if isinstance(r.get("summary"),dict) else {}
    lv=float(ls.get("net_total") or 0);rv=float(rs.get("net_total") or 0);diff=lv-rv
    lp=(l.get("period") or {}).get("label") if isinstance(l.get("period"),dict) else "دوره اول"
    rp=(r.get("period") or {}).get("label") if isinstance(r.get("period"),dict) else "دوره دوم"
    metric="فروش" if kind=="sales" else "خرید"
    if rv:
        pct=diff/abs(rv)*100
        change=f"{abs(pct):.1f}٪ {'افزایش' if diff>0 else 'کاهش' if diff<0 else 'بدون تغییر'} نسبت به دوره قبل"
    else:
        change="در دوره مبنا مبلغ صفر است؛ درصد تغییر قابل محاسبه نیست."
    return (f"مقایسه {metric}\n• {lp}: {money(lv)}\n• {rp}: {money(rv)}\n"
            f"• اختلاف: {money(abs(diff))} {'بیشتر' if diff>0 else 'کمتر' if diff<0 else ''}\n• نتیجه: {change}")

def meta(worker,mode,used,source="deterministic",model="none",metrics=None,extra=None):
    with worker.progress_lock:trace=list(worker.current_trace[-50:])
    x={"provider":"grounded_parameterized_read","model":model if source=="llm" else "none","mode":mode,"tools_used":used,"parser_source":source,"metrics":metrics or {},"trace":trace,"patch_version":PATCH_VERSION}
    if extra:x.update(extra)
    return x

def execute_one(worker,job,plan,source="deterministic",model="none",metrics=None):
    intent=plan["intent"];q=str(plan.get("query") or "");lim=max(1,min(50,int(plan.get("limit") or 5)));used=[]
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
    elif intent=="document_analytics":
        args=analytics_args(plan);d=worker.tool(job,"document_analytics",args,f"job{job['id']}-analytics-"+hashlib_stub(args));used.append("document_analytics");text=analytics_text(d)
    elif intent=="compare_periods":
        base={"kind":plan["kind"],"workflow_status":plan.get("workflow_status","all"),"group_by":"none","limit":5}
        left=worker.tool(job,"document_analytics",{**base,"period":plan["left_period"]},f"job{job['id']}-compare-left")
        right=worker.tool(job,"document_analytics",{**base,"period":plan["right_period"]},f"job{job['id']}-compare-right")
        used+=["document_analytics","document_analytics"];text=compare_text(left,right,plan["kind"])
    else:raise RuntimeError("unsupported_grounded_read_intent:"+intent)
    worker.trace(job,"grounded_read_complete","Grounded ERP read completed",{"tools_used":used})
    return text,meta(worker,"grounded_read",used,source,model,metrics,{"intent":intent})

def hashlib_stub(args:dict[str,Any])->str:
    # Stable compact suffix without importing hashlib; uniqueness within a job is enough.
    s=json.dumps(args,ensure_ascii=False,sort_keys=True,separators=(",",":"))
    return str(abs(sum((i+1)*ord(ch) for i,ch in enumerate(s)))%100000000)

def process_multi(worker,job,parts:list[str]):
    texts=[];all_used=[];intents=[]
    for idx,part in enumerate(parts,1):
        plan=route(part)
        if plan is None:
            return None
        subjob=dict(job);subjob["prompt"]=part
        worker.trace(job,"multi_read_step",f"Executing read subtask {idx}/{len(parts)}",{"prompt":part[:200],"intent":plan.get("intent")})
        text,m=execute_one(worker,subjob,plan)
        texts.append(f"{idx}) {part}\n{text}")
        all_used.extend(m.get("tools_used") or []);intents.append(plan.get("intent"))
    return "\n\n".join(texts),meta(worker,"grounded_multi_read",all_used,extra={"intents":intents,"subtasks":len(parts)})

def install_read_guard(cls:type)->None:
    if getattr(cls,"_read_guard_v2_installed",False):return
    # v8.4 wrapper is currently the public method. Restore its original delegate
    # so v8.5 replaces it cleanly rather than wrapping v8.4 period blocking.
    previous=cls.process_agent
    base=getattr(cls,"_read_guard_original_process_agent",previous)

    def patched(self,job,tools_desc):
        prompt=str(job.get("prompt") or "")
        n=norm(prompt)
        if any(x in n for x in WRITE) or any(x in n for x in ("تحلیل عمیق","ریسک","سناریو","پیش بینی","پیش‌بینی")):
            return base(self,job,tools_desc)

        parts=split_multi(prompt)
        if len(parts)>1:
            multi=process_multi(self,job,parts)
            if multi is not None:return multi

        plan=route(prompt);source="deterministic";model="none";metrics={}
        if plan is None:
            try:plan,metrics,model=llm_plan(self,job,prompt);source="llm"
            except Exception:return base(self,job,tools_desc)
        if plan.get("intent")=="unsupported":return base(self,job,tools_desc)
        return execute_one(self,job,plan,source,model,metrics)

    cls.process_agent=patched
    cls._read_guard_v2_installed=True
    cls._read_guard_v2_original_process_agent=base
