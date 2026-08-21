#!/usr/bin/env python3
"""ERPSMART v8.5.0 parameterized grounded read planner.

Financial values and ERP identifiers always come from server tools.
Relative Persian periods are represented by safe enums and resolved on cPanel.
No arbitrary SQL/table/column names can originate from the model or user prompt.
"""
from __future__ import annotations
import json,re,time
from typing import Any

PATCH_VERSION="v8.6.0.1"
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

def semantic_scope_of(p:str)->str:
    n=norm(p)
    if any(x in n for x in ("قطعی","confirmed","تاییدشده و نهایی","تأییدشده و نهایی","تایید شده و نهایی","تأیید شده و نهایی")):
        return "confirmed"
    if "draft" in n or "پیش نویس" in n or "پیش‌نویس" in n:
        return "draft"
    if "final" in n or "نهایی" in n:
        return "final"
    if "approved" in n or "تایید شده" in n or "تأیید شده" in n or "تاییدشده" in n or "تأییدشده" in n:
        return "approved"
    return "all"

def scope_label(scope:str)->str:
    return {
        "all":"همه وضعیت‌ها (draft + approved + final)",
        "confirmed":"قطعی (approved + final)",
        "draft":"فقط پیش‌نویس (draft)",
        "approved":"فقط تاییدشده (approved)",
        "final":"فقط نهایی (final)",
    }.get(str(scope),"همه وضعیت‌ها")

def entity_queries(p:str)->dict[str,str]:
    text=str(p or "")
    out={"party_query":"","item_query":""}
    party_patterns=(
        r"(?:مشتری|طرف\s*حساب|خریدار)\s*[«\"']([^»\"'\r\n]{2,160})[»\"']",
        r"(?:برای|به)\s+مشتری\s*[«\"']([^»\"'\r\n]{2,160})[»\"']",
    )
    item_patterns=(
        r"(?:کالا|محصول|آیتم|کالای|محصولِ)\s*[«\"']([^»\"'\r\n]{2,160})[»\"']",
        r"(?:فروش|خرید)\s+(?:کالا|محصول)\s*[«\"']([^»\"'\r\n]{2,160})[»\"']",
    )
    for pat in party_patterns:
        m=re.search(pat,text,flags=re.I)
        if m: out["party_query"]=m.group(1).strip(); break
    for pat in item_patterns:
        m=re.search(pat,text,flags=re.I)
        if m: out["item_query"]=m.group(1).strip(); break

    qs=re.findall(r"[«\"']([^»\"'\r\n]{2,160})[»\"']",text)
    n=norm(text)
    if len(qs)==2:
        if out["party_query"] and not out["item_query"] and any(x in n for x in ("کالا","محصول","آیتم")):
            other=[q for q in qs if norm(q)!=norm(out["party_query"])]
            if len(other)==1: out["item_query"]=other[0].strip()
        elif out["item_query"] and not out["party_query"] and any(x in n for x in ("مشتری","طرف حساب","خریدار")):
            other=[q for q in qs if norm(q)!=norm(out["item_query"])]
            if len(other)==1: out["party_query"]=other[0].strip()
    return out

def needs_entity_parse(p:str,entities:dict[str,str])->bool:
    n=norm(p);group=group_of(p)
    party_hint=("مشتری" in n or "طرف حساب" in n or "خریدار" in n) and group!="party"
    item_hint=("کالا" in n or "محصول" in n or "آیتم" in n) and group!="item"
    latin_tokens=[x for x in re.findall(r"\b[A-Za-z][A-Za-z0-9_.\-/]{2,}\b",str(p or "")) if x.lower() not in {"draft","final","approved","confirmed"}]
    return (party_hint and not entities.get("party_query")) or (item_hint and not entities.get("item_query")) or (bool(latin_tokens) and not entities.get("item_query") and group!="item")

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
    if any(x in n for x in ("تفکیک مشتری","به تفکیک مشتری","بر اساس مشتری","به ازای مشتری","مشتری برتر","بیشترین مشتری","برترین مشتری","پرفروش ترین مشتری","پرفروش‌ترین مشتری")):return "party"
    if any(x in n for x in ("تفکیک کالا","به تفکیک کالا","بر اساس کالا","به ازای کالا","کالای برتر","بیشترین کالا","برترین کالا","پرفروش ترین کالا","پرفروش‌ترین کالا","محصول")) and "پیدا" not in n:return "item"
    if any(x in n for x in ("تفکیک وضعیت","بر اساس وضعیت","draft و final","final و draft","پیش نویس و نهایی","پیش‌نویس و نهایی")):return "status"
    if any(x in n for x in ("ماه به ماه","ماه‌به‌ماه","تفکیک ماه","بر اساس ماه")):return "jalali_month"
    return "none"

def analytics_plan(p:str,kind:str)->dict[str,Any]:
    n=norm(p)
    top=any(x in n for x in ("بیشترین مشتری","برترین مشتری","مشتری برتر","پرفروش ترین مشتری","پرفروش‌ترین مشتری",
                              "بیشترین کالا","برترین کالا","کالای برتر","پرفروش ترین کالا","پرفروش‌ترین کالا"))
    group=group_of(p)
    scope="all" if group=="status" else semantic_scope_of(p)
    entities=entity_queries(p)
    explicit_limit=limit_of(p,0)
    ranking_limit=explicit_limit if explicit_limit>0 else (1 if top else 10)
    x={"intent":"document_analytics","kind":kind,"group_by":group,"status_scope":scope,
       "limit":ranking_limit,
       "party_query":entities.get("party_query",""),"item_query":entities.get("item_query",""),
       "needs_entity_parse":needs_entity_parse(p,entities)}
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
                "status_scope":semantic_scope_of(p),
                **entity_queries(p)}

    # Analytics cases: period, grouping, top customer/item, month trend, status split.
    entities=entity_queries(p)
    analytical_hint=(
        period_of(p).get("period")!="all" or group_of(p)!="none" or bool(entities.get("party_query")) or bool(entities.get("item_query")) or
        any(x in n for x in ("بیشترین مشتری","برترین مشتری","مشتری برتر","پرفروش ترین مشتری","پرفروش‌ترین مشتری",
                             "بیشترین کالا","برترین کالا","کالای برتر","پرفروش ترین کالا","پرفروش‌ترین کالا","قطعی"))
    )
    if sales and analytical_hint:return analytics_plan(p,"sales")
    if buys and analytical_hint:return analytics_plan(p,"purchases")
    if not sales and not buys and any(x in n for x in ("بیشترین مشتری","برترین مشتری","مشتری برتر","پرفروش ترین مشتری","پرفروش‌ترین مشتری",
                                                          "بیشترین کالا","برترین کالا","کالای برتر","پرفروش ترین کالا","پرفروش‌ترین کالا")):
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
    matches=list(re.finditer(r"(?m)^\s*\d{1,2}\s*[\)\.\-]\s*",text))
    if len(matches)>=2:
        out=[]
        for i,m in enumerate(matches):
            a=m.end();b=matches[i+1].start() if i+1<len(matches) else len(text)
            part=text[a:b].strip()
            if part:out.append(part)
        return out

    bullets=[re.sub(r"^\s*[-•]\s*","",x).strip() for x in text.splitlines() if re.match(r"^\s*[-•]\s*\S",x)]
    if len(bullets)>=2:return bullets

    semis=[x.strip() for x in re.split(r"[؛;]+",text) if x.strip()]
    if len(semis)>=2:return semis

    lines=[x.strip() for x in text.splitlines() if x.strip()]
    if len(lines)>=2 and all(len(x)<300 for x in lines):
        return lines
    return [text]

def contextualize_parts(parts:list[str])->list[str]:
    out=[];kind="";period_phrase="";scope_phrase=""
    for raw in parts:
        part=raw.strip();n=norm(part);previous_period=period_phrase
        if "فروش" in n:kind="فروش"
        elif "خرید" in n:kind="خرید"

        explicit_scope=semantic_scope_of(part)
        if explicit_scope=="confirmed":scope_phrase="قطعی"
        elif explicit_scope=="draft":scope_phrase="draft"
        elif explicit_scope=="approved":scope_phrase="approved"
        elif explicit_scope=="final":scope_phrase="final"

        if "این ماه" in n:
            period_phrase="این ماه"
        elif ("ماه قبل" in n or "ماه گذشته" in n) and "مقایسه" not in n:
            period_phrase="ماه قبل"

        if kind and "فروش" not in n and "خرید" not in n:
            part=kind+" "+part
            n=norm(part)

        if "مقایسه" in n and ("ماه قبل" in n or "ماه گذشته" in n) and "این ماه" not in n and previous_period=="این ماه":
            part=part.replace("ماه قبل","این ماه را با ماه قبل",1).replace("ماه گذشته","این ماه را با ماه قبل",1)
            n=norm(part)
            period_phrase="این ماه"
        elif period_phrase and not any(x in n for x in ("این ماه","ماه قبل","ماه گذشته","این سال","سال قبل","سال گذشته","ماه اخیر")):
            if any(x in n for x in ("مشتری برتر","برترین مشتری","پرفروش","تفکیک","کالا","مشتری")):
                part=part+" "+period_phrase
                n=norm(part)

        if scope_phrase and semantic_scope_of(part)=="all" and group_of(part)!="status":
            part=part+" "+scope_phrase
        out.append(part)
    return out

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

def llm_entity_hints(worker,job,prompt:str)->dict[str,str]:
    model=worker.model_for("agent")
    system=("Extract only entity search phrases from this ERP analytics request. Return ONLY JSON with keys party_query and item_query. "
            "Each non-empty value MUST be copied verbatim from the user prompt. Never output database IDs, prices, dates, totals or explanations. "
            "Use empty string when no specific party/item is requested. Schema: {\"party_query\":\"\",\"item_query\":\"\"}.")
    worker.trace(job,"entity_parse",f"Parsing entity hints with {model}",{"model":model,"started_epoch":time.time()})
    r=worker.ollama_chat(job,0,[{"role":"system","content":system},{"role":"user","content":prompt}],[],fast=True,model=model,num_ctx=1024,num_predict=80,temperature=0.0,timeout_seconds=90)
    x=parse_json((r.get("message") or {}).get("content"))
    out={}
    for key in ("party_query","item_query"):
        q=str(x.get(key) or "").strip()
        if q and norm(q) not in norm(prompt):raise ValueError("entity_query_not_grounded:"+key)
        out[key]=q
    return out


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
    allowed={"kind","period","months","date_from","date_to","jalali_year","jalali_month",
             "workflow_status","status_scope","group_by","limit","party_id","item_id"}
    return {k:v for k,v in plan.items() if k in allowed and v not in (None,"")}

def analytics_text(d:Any)->str:
    x=d if isinstance(d,dict) else {};kind=str(x.get("kind") or "sales")
    title="فروش" if kind=="sales" else "خرید";period=x.get("period") if isinstance(x.get("period"),dict) else {}
    summary=x.get("summary") if isinstance(x.get("summary"),dict) else {};groups=x.get("groups") if isinstance(x.get("groups"),list) else []
    filters=x.get("filters") if isinstance(x.get("filters"),dict) else {}
    label=str(period.get("label") or "همه دوره‌ها")
    scope=str(filters.get("status_scope") or "all")
    out=[f"گزارش {title} — {label}",
         f"• دامنه اسناد: {scope_label(scope)}"]
    if filters.get("party_name"):out.append(f"• طرف‌حساب: {filters.get('party_name')}")
    if filters.get("item_name"):out.append(f"• کالا/خدمت: {filters.get('item_name')}")
    out.extend([
         f"• تعداد اسناد: {int(summary.get('document_count') or 0)}",
         f"• مبلغ قبل از تخفیف: {money(summary.get('total_before_discount'))}",
         f"• تخفیف: {money(summary.get('discount_total'))}",
         f"• مالیات: {money(summary.get('tax_total'))}",
         f"• مبلغ خالص: {money(summary.get('net_total'))}"])
    if "quantity_total" in summary:
        out.append(f"• تعداد/مقدار کل ردیف‌های منطبق: {float(summary.get('quantity_total') or 0):g}")
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
    lf=l.get("filters") if isinstance(l.get("filters"),dict) else {}
    lv=float(ls.get("net_total") or 0);rv=float(rs.get("net_total") or 0);diff=lv-rv
    lp=(l.get("period") or {}).get("label") if isinstance(l.get("period"),dict) else "دوره اول"
    rp=(r.get("period") or {}).get("label") if isinstance(r.get("period"),dict) else "دوره دوم"
    metric="فروش" if kind=="sales" else "خرید"
    if rv:
        pct=diff/abs(rv)*100
        change=f"{abs(pct):.1f}٪ {'افزایش' if diff>0 else 'کاهش' if diff<0 else 'بدون تغییر'} نسبت به دوره قبل"
    else:
        change="در دوره مبنا مبلغ صفر است؛ درصد تغییر قابل محاسبه نیست."
    out=[f"مقایسه {metric}",f"• دامنه اسناد: {scope_label(str(lf.get('status_scope') or 'all'))}"]
    if lf.get("party_name"):out.append(f"• طرف‌حساب: {lf.get('party_name')}")
    if lf.get("item_name"):out.append(f"• کالا/خدمت: {lf.get('item_name')}")
    out.extend([f"• {lp}: {money(lv)}",f"• {rp}: {money(rv)}",
                f"• اختلاف: {money(abs(diff))} {'بیشتر' if diff>0 else 'کمتر' if diff<0 else ''}",
                f"• نتیجه: {change}"])
    return "\n".join(out)

def resolve_plan_entities(worker,job,prompt:str,plan:dict[str,Any])->tuple[dict[str,Any],list[str],str]:
    work=dict(plan);used=[];notes=[]
    if work.get("needs_entity_parse"):
        hints=llm_entity_hints(worker,job,prompt)
        for key in ("party_query","item_query"):
            if hints.get(key) and not work.get(key):work[key]=hints[key]

    pq=str(work.get("party_query") or "").strip()
    if pq:
        data=worker.tool(job,"search_parties",{"query":pq},f"job{job['id']}-scope-party")
        used.append("search_parties");party,reason=unique_entity(rows(data),pq)
        if party is None:
            return work,used,(f"طرف‌حساب «{pq}» به‌صورت یکتا پیدا نشد؛ گزارش محدود به طرف‌حساب اجرا نشد." if reason=="ambiguous"
                              else f"طرف‌حساب «{pq}» پیدا نشد؛ گزارش محدود به طرف‌حساب اجرا نشد.")
        work["party_id"]=int(party["id"]);notes.append(str(party.get("name") or pq))

    iq=str(work.get("item_query") or "").strip()
    if iq:
        data=worker.tool(job,"search_items",{"query":iq},f"job{job['id']}-scope-item")
        used.append("search_items");item,reason=unique_entity(rows(data),iq)
        if item is None:
            return work,used,(f"کالا/خدمت «{iq}» به‌صورت یکتا پیدا نشد؛ گزارش محدود به کالا اجرا نشد." if reason=="ambiguous"
                              else f"کالا/خدمت «{iq}» پیدا نشد؛ گزارش محدود به کالا اجرا نشد.")
        work["item_id"]=int(item["id"]);notes.append(str(item.get("name") or iq))
    return work,used,""


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
        work,entity_used,blocked=resolve_plan_entities(worker,job,str(job.get("prompt") or ""),plan);used+=entity_used
        if blocked:
            text=blocked
        else:
            args=analytics_args(work);d=worker.tool(job,"document_analytics",args,f"job{job['id']}-analytics-"+hashlib_stub(args));used.append("document_analytics");text=analytics_text(d)
    elif intent=="compare_periods":
        work,entity_used,blocked=resolve_plan_entities(worker,job,str(job.get("prompt") or ""),plan);used+=entity_used
        if blocked:
            text=blocked
        else:
            base={"kind":work["kind"],"status_scope":work.get("status_scope","all"),"group_by":"none","limit":5}
            if work.get("party_id"):base["party_id"]=work["party_id"]
            if work.get("item_id"):base["item_id"]=work["item_id"]
            left=worker.tool(job,"document_analytics",{**base,"period":work["left_period"]},f"job{job['id']}-compare-left")
            right=worker.tool(job,"document_analytics",{**base,"period":work["right_period"]},f"job{job['id']}-compare-right")
            used+=["document_analytics","document_analytics"];text=compare_text(left,right,work["kind"])
    else:raise RuntimeError("unsupported_grounded_read_intent:"+intent)
    worker.trace(job,"grounded_read_complete","Grounded ERP read completed",{"tools_used":used})
    return text,meta(worker,"grounded_read",used,source,model,metrics,{"intent":intent})

def hashlib_stub(args:dict[str,Any])->str:
    # Stable compact suffix without importing hashlib; uniqueness within a job is enough.
    s=json.dumps(args,ensure_ascii=False,sort_keys=True,separators=(",",":"))
    return str(abs(sum((i+1)*ord(ch) for i,ch in enumerate(s)))%100000000)

def process_multi(worker,job,parts:list[str]):
    parts=contextualize_parts(parts)
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
    if getattr(cls,"_read_guard_v3_installed",False):return
    # v8.4 wrapper is currently the public method. Restore its original delegate
    # so v8.5 replaces it cleanly rather than wrapping v8.4 period blocking.
    previous=cls.process_agent
    base=getattr(cls,"_read_guard_v2_original_process_agent",getattr(cls,"_read_guard_original_process_agent",previous))

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
    cls._read_guard_v3_installed=True
    cls._read_guard_v3_original_process_agent=base
