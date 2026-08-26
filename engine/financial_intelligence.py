#!/usr/bin/env python3
"""ERPSMART v9.0.0 Financial Intelligence Core.

Goal:
- Turn grounded accounting facts into deterministic management findings.
- Keep financial numbers, periods, thresholds and evidence server/tool owned.
- Let the local LLM only prioritize already-grounded finding IDs.
- No writes, no proposals, no arbitrary SQL, no ERP IDs emitted by the model.

First vertical slice:
    grounded tools
      -> deterministic metrics
      -> deterministic findings
      -> bounded LLM priority selection
      -> server-rendered Persian management report
"""
from __future__ import annotations

import json
import math
import re
import time
from typing import Any

PATCH_VERSION = "v9.0.1"
INTELLIGENCE_VERSION = "financial-intelligence-v1"

_DIGIT_TRANS = str.maketrans(
    "۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩",
    "01234567890123456789",
)

class IntelligenceError(ValueError):
    pass


def norm(value: Any) -> str:
    text=str(value or "").translate(_DIGIT_TRANS)
    text=text.replace("ي","ی").replace("ك","ک").replace("\u200c"," ")
    text=re.sub(r"\s+"," ",text).strip().lower()
    return text


def is_intelligence_candidate(prompt: str) -> bool:
    p=norm(prompt)
    if not p:
        return False
    # First v9.0 slice is strictly read-only. Mixed write requests stay on prior guards.
    write_terms=(
        "ثبت کن","بساز","ایجاد کن","پیشنهاد ثبت","پیش نویس","پیش‌نویس",
        "تایید و اجرا","تأیید و اجرا","حذف کن","ویرایش کن","پرداخت کن","دریافت کن"
    )
    if any(x in p for x in write_terms):
        return False

    direct=(
        "وضعیت مالی شرکت","تحلیل مالی شرکت","سلامت مالی شرکت",
        "ریسک های مالی","ریسک‌های مالی","financial intelligence",
        "financial health","financial analysis"
    )
    if any(x in p for x in direct):
        return True

    concepts=("فروش","خرید","مطالبات","بدهی","تراز","ریسک","نکات مهم","وضعیت مالی")
    hits=sum(1 for x in concepts if x in p)
    return hits>=3 and any(x in p for x in ("تحلیل","بررسی","ریسک","نکات","وضعیت"))


def _rows(result: Any) -> list[dict[str,Any]]:
    if isinstance(result,list):
        return [x for x in result if isinstance(x,dict)]
    if isinstance(result,dict):
        for key in ("rows","groups","items","results","data"):
            value=result.get(key)
            if isinstance(value,list):
                return [x for x in value if isinstance(x,dict)]
    return []


def _summary(result: Any) -> dict[str,Any]:
    if isinstance(result,dict) and isinstance(result.get("summary"),dict):
        return dict(result["summary"])
    return {}


def _period_key(result: Any) -> str:
    if isinstance(result,dict):
        p=result.get("period")
        if isinstance(p,dict):
            return str(p.get("key") or "")
        return str(p or "")
    return ""


def _month_groups(result: Any) -> list[dict[str,Any]]:
    if not isinstance(result,dict):
        return []
    groups=result.get("groups")
    if not isinstance(groups,list):
        return []
    out=[]
    for g in groups:
        if not isinstance(g,dict):
            continue
        key=str(g.get("key") or g.get("label") or "").strip()
        if not re.fullmatch(r"\d{4}/\d{2}",key):
            continue
        out.append({
            "key":key,
            "document_count":int(g.get("document_count") or 0),
            "net_total":float(g.get("net_total") or 0),
        })
    out.sort(key=lambda x:x["key"])
    return out


def _complete_month_pair(result: Any, jalali_today: str) -> tuple[dict[str,Any]|None,dict[str,Any]|None]:
    current=str(jalali_today or "")[:7]
    groups=[x for x in _month_groups(result) if x["key"]!=current]
    if len(groups)<2:
        return None,None
    return groups[-2],groups[-1]


def _pct_change(previous: float, current: float) -> float|None:
    previous=float(previous); current=float(current)
    if abs(previous)<=0.01:
        return None
    return (current-previous)/abs(previous)*100.0


def _share(part: float, total: float) -> float|None:
    total=float(total)
    if abs(total)<=0.01:
        return None
    return float(part)/abs(total)*100.0


def _type_aggregates(trial: list[dict[str,Any]]) -> dict[str,float]:
    out={}
    for row in trial:
        typ=str(row.get("account_type") or "unknown").strip() or "unknown"
        out[typ]=out.get(typ,0.0)+float(row.get("balance") or 0)
    return out


def _top_balances(trial: list[dict[str,Any]], limit: int=6) -> list[dict[str,Any]]:
    rows=[]
    for r in trial:
        bal=float(r.get("balance") or 0)
        if abs(bal)<=0.01:
            continue
        rows.append({
            "id":int(r.get("id") or 0),
            "code":str(r.get("code") or ""),
            "name":str(r.get("name") or ""),
            "account_type":str(r.get("account_type") or ""),
            "balance":bal,
            "abs_balance":abs(bal),
        })
    rows.sort(key=lambda x:x["abs_balance"],reverse=True)
    return rows[:max(1,int(limit))]


def collect_tool_plan() -> list[tuple[str,dict[str,Any],str]]:
    """Server-owned read-only plan. No LLM tool construction."""
    return [
        ("financial_analysis_bundle",{},"bundle"),
        ("trial_balance",{},"trial"),
        ("document_analytics",{
            "kind":"sales","period":"rolling_jalali_months","months":4,
            "status_scope":"confirmed","group_by":"jalali_month","limit":8
        },"sales_months"),
        ("document_analytics",{
            "kind":"purchases","period":"rolling_jalali_months","months":4,
            "status_scope":"confirmed","group_by":"jalali_month","limit":8
        },"purchase_months"),
        ("document_analytics",{
            "kind":"sales","period":"previous_jalali_month",
            "status_scope":"confirmed","group_by":"party","limit":5
        },"sales_parties"),
        ("document_analytics",{
            "kind":"purchases","period":"previous_jalali_month",
            "status_scope":"confirmed","group_by":"party","limit":5
        },"purchase_parties"),
        ("document_analytics",{
            "kind":"sales","period":"all",
            "status_scope":"all","group_by":"status","limit":10
        },"sales_all"),
        ("document_analytics",{
            "kind":"sales","period":"all",
            "status_scope":"confirmed","group_by":"none","limit":5
        },"sales_confirmed"),
        ("document_analytics",{
            "kind":"purchases","period":"all",
            "status_scope":"all","group_by":"status","limit":10
        },"purchases_all"),
        ("document_analytics",{
            "kind":"purchases","period":"all",
            "status_scope":"confirmed","group_by":"none","limit":5
        },"purchases_confirmed"),
    ]


def build_metrics(results: dict[str,Any], jalali_today: str) -> dict[str,Any]:
    trial=_rows(results.get("trial"))
    bundle=results.get("bundle") if isinstance(results.get("bundle"),dict) else {}
    bundle_trial=bundle.get("trial_balance") if isinstance(bundle.get("trial_balance"),dict) else {}

    total_debit=float(bundle_trial.get("total_debit") or sum(float(r.get("debit") or 0) for r in trial))
    total_credit=float(bundle_trial.get("total_credit") or sum(float(r.get("credit") or 0) for r in trial))
    difference=float(bundle_trial.get("difference") if bundle_trial.get("difference") is not None else total_debit-total_credit)

    sales_prev,sales_last=_complete_month_pair(results.get("sales_months"),jalali_today)
    purch_prev,purch_last=_complete_month_pair(results.get("purchase_months"),jalali_today)

    sales_party_groups=_rows(results.get("sales_parties"))
    sales_party_summary=_summary(results.get("sales_parties"))
    sales_party_total=float(sales_party_summary.get("net_total") or 0)
    top_customer=sales_party_groups[0] if sales_party_groups else None
    top_customer_share=_share(float((top_customer or {}).get("net_total") or 0),sales_party_total)

    purchase_party_groups=_rows(results.get("purchase_parties"))
    purchase_party_summary=_summary(results.get("purchase_parties"))
    purchase_party_total=float(purchase_party_summary.get("net_total") or 0)
    top_vendor=purchase_party_groups[0] if purchase_party_groups else None
    top_vendor_share=_share(float((top_vendor or {}).get("net_total") or 0),purchase_party_total)

    sales_all=_summary(results.get("sales_all"))
    sales_confirmed=_summary(results.get("sales_confirmed"))
    purchases_all=_summary(results.get("purchases_all"))
    purchases_confirmed=_summary(results.get("purchases_confirmed"))

    sales_draft_amount=max(0.0,float(sales_all.get("net_total") or 0)-float(sales_confirmed.get("net_total") or 0))
    sales_draft_docs=max(0,int(sales_all.get("document_count") or 0)-int(sales_confirmed.get("document_count") or 0))
    purchase_draft_amount=max(0.0,float(purchases_all.get("net_total") or 0)-float(purchases_confirmed.get("net_total") or 0))
    purchase_draft_docs=max(0,int(purchases_all.get("document_count") or 0)-int(purchases_confirmed.get("document_count") or 0))

    return {
        "jalali_today":jalali_today,
        "trial":{
            "total_debit":total_debit,
            "total_credit":total_credit,
            "difference":difference,
            "balanced":abs(difference)<=0.01,
            "account_count":len(trial),
            "type_balances":_type_aggregates(trial),
            "top_balances":_top_balances(trial,6),
        },
        "sales_trend":{
            "previous":sales_prev,
            "latest_complete":sales_last,
            "change_percent":_pct_change(
                float((sales_prev or {}).get("net_total") or 0),
                float((sales_last or {}).get("net_total") or 0)
            ) if sales_prev and sales_last else None,
        },
        "purchase_trend":{
            "previous":purch_prev,
            "latest_complete":purch_last,
            "change_percent":_pct_change(
                float((purch_prev or {}).get("net_total") or 0),
                float((purch_last or {}).get("net_total") or 0)
            ) if purch_prev and purch_last else None,
        },
        "customer_concentration":{
            "period":str((results.get("sales_parties") or {}).get("period",{}).get("label","")) if isinstance((results.get("sales_parties") or {}).get("period"),dict) else "",
            "total":sales_party_total,
            "top_party":{
                "name":str((top_customer or {}).get("label") or ""),
                "net_total":float((top_customer or {}).get("net_total") or 0),
                "document_count":int((top_customer or {}).get("document_count") or 0),
            } if top_customer else None,
            "share_percent":top_customer_share,
        },
        "vendor_concentration":{
            "period":str((results.get("purchase_parties") or {}).get("period",{}).get("label","")) if isinstance((results.get("purchase_parties") or {}).get("period"),dict) else "",
            "total":purchase_party_total,
            "top_party":{
                "name":str((top_vendor or {}).get("label") or ""),
                "net_total":float((top_vendor or {}).get("net_total") or 0),
                "document_count":int((top_vendor or {}).get("document_count") or 0),
            } if top_vendor else None,
            "share_percent":top_vendor_share,
        },
        "draft_exposure":{
            "sales":{
                "document_count":sales_draft_docs,
                "net_total":sales_draft_amount,
                "share_percent":_share(sales_draft_amount,float(sales_all.get("net_total") or 0)),
            },
            "purchases":{
                "document_count":purchase_draft_docs,
                "net_total":purchase_draft_amount,
                "share_percent":_share(purchase_draft_amount,float(purchases_all.get("net_total") or 0)),
            },
        },
    }


def build_findings(metrics: dict[str,Any]) -> list[dict[str,Any]]:
    findings=[]

    trial=metrics["trial"]
    if trial["balanced"]:
        findings.append({
            "id":"trial_balanced","severity":"info","category":"integrity",
            "title":"تراز ثبت‌های قطعی متوازن است",
            "evidence":{"difference":trial["difference"]},
        })
    else:
        findings.append({
            "id":"trial_imbalance","severity":"critical","category":"integrity",
            "title":"تراز ثبت‌های قطعی نامتوازن است",
            "evidence":{"difference":trial["difference"]},
        })

    for prefix,label,key in (
        ("sales","فروش","sales_trend"),
        ("purchases","خرید","purchase_trend"),
    ):
        t=metrics[key]; pct=t.get("change_percent")
        if pct is None:
            findings.append({
                "id":f"{prefix}_trend_insufficient","severity":"info","category":"trend",
                "title":f"برای روند {label} دو ماه کامل قابل مقایسه در بازه فعلی وجود ندارد",
                "evidence":{},
            })
        else:
            sev="warning" if pct<=-20 else "info"
            fid=f"{prefix}_complete_month_change"
            findings.append({
                "id":fid,"severity":sev,"category":"trend",
                "title":f"تغییر {label} بین دو ماه کامل اخیر",
                "evidence":{
                    "previous_month":t["previous"]["key"],
                    "previous_total":t["previous"]["net_total"],
                    "latest_month":t["latest_complete"]["key"],
                    "latest_total":t["latest_complete"]["net_total"],
                    "change_percent":pct,
                },
            })

    for prefix,label,key,warn,crit in (
        ("customer","مشتری","customer_concentration",50.0,70.0),
        ("vendor","تأمین‌کننده","vendor_concentration",60.0,80.0),
    ):
        c=metrics[key]; share=c.get("share_percent"); top=c.get("top_party")
        if share is not None and top:
            sev="critical" if share>=crit else ("warning" if share>=warn else "info")
            findings.append({
                "id":f"{prefix}_concentration","severity":sev,"category":"concentration",
                "title":f"تمرکز {label} برتر در دوره کامل قبلی",
                "evidence":{
                    "party":top["name"],"party_total":top["net_total"],
                    "period_total":c["total"],"share_percent":share,
                    "period":c.get("period",""),
                },
            })

    for prefix,label in (("sales","فروش"),("purchases","خرید")):
        d=metrics["draft_exposure"][prefix]
        share=d.get("share_percent")
        if d["document_count"]>0 or d["net_total"]>0.01:
            sev="warning" if share is not None and share>=20.0 else "info"
            findings.append({
                "id":f"{prefix}_draft_exposure","severity":sev,"category":"pipeline",
                "title":f"اسناد {label} هنوز در وضعیت غیرقطعی وجود دارند",
                "evidence":{
                    "document_count":d["document_count"],
                    "net_total":d["net_total"],
                    "share_percent":share,
                },
            })

    top=metrics["trial"]["top_balances"]
    if top:
        findings.append({
            "id":"largest_account_balances","severity":"info","category":"balances",
            "title":"بزرگ‌ترین مانده‌های حساب‌های قطعی",
            "evidence":{"accounts":[
                {"code":x["code"],"name":x["name"],"account_type":x["account_type"],"balance":x["balance"]}
                for x in top[:5]
            ]},
        })

    return findings


def priority_schema(ids: list[str]) -> dict[str,Any]:
    if not ids:
        ids=["trial_balanced"]
    return {
        "type":"object",
        "properties":{
            "priority_findings":{
                "type":"array","items":{"type":"string","enum":ids},
                "uniqueItems":True,"minItems":1,"maxItems":min(5,len(ids))
            }
        },
        "required":["priority_findings"],
        "additionalProperties":False,
    }


def priority_prompt(findings: list[dict[str,Any]]) -> str:
    slim=[
        {"id":f["id"],"severity":f["severity"],"category":f["category"],"title":f["title"]}
        for f in findings
    ]
    return (
        "You are prioritizing already-grounded accounting findings for management. "
        "Choose the most decision-relevant finding IDs only. "
        "Do not create numbers, facts, tools, ERP IDs, advice text, or new finding IDs. "
        "Prefer critical/warning items, then material trend/concentration items. "
        "Return only JSON matching the schema.\nFINDINGS="
        +json.dumps(slim,ensure_ascii=False,separators=(",",":"))
    )


def parse_priority(raw: Any, findings: list[dict[str,Any]]) -> list[str]:
    allowed=[f["id"] for f in findings]
    try:
        obj=json.loads(str(raw or "").strip())
    except Exception as e:
        raise IntelligenceError("intelligence_priority_json_invalid") from e
    if not isinstance(obj,dict) or set(obj)!={"priority_findings"}:
        raise IntelligenceError("intelligence_priority_shape_invalid")
    vals=obj.get("priority_findings")
    if not isinstance(vals,list) or not vals:
        raise IntelligenceError("intelligence_priority_empty")
    out=[]
    for x in vals:
        x=str(x)
        if x not in allowed:
            raise IntelligenceError("intelligence_priority_unknown_id")
        if x not in out:
            out.append(x)
    return out[:5]


def fallback_priority(findings: list[dict[str,Any]]) -> list[str]:
    rank={"critical":0,"warning":1,"info":2}
    ordered=sorted(enumerate(findings),key=lambda x:(rank.get(x[1].get("severity"),9),x[0]))
    return [f["id"] for _,f in ordered[:5]]


def canonical_priority(findings: list[dict[str,Any]], selected: list[str], limit: int=5) -> list[str]:
    """Enforce severity-first management priority on grounded finding IDs."""
    by_id={f["id"]:f for f in findings}
    selected=[x for x in selected if x in by_id]

    def ordered_for(severity: str) -> list[str]:
        out=[]
        for x in selected:
            if by_id[x].get("severity")==severity and x not in out:
                out.append(x)
        for f in findings:
            if f.get("severity")==severity and f["id"] not in out:
                out.append(f["id"])
        return out

    result=[]
    for sev in ("critical","warning","info"):
        for fid in ordered_for(sev):
            if fid not in result:
                result.append(fid)
            if len(result)>=limit:
                return result
    for x in selected:
        if x not in result:
            result.append(x)
        if len(result)>=limit:
            break
    return result


def _fmt_money(value: Any) -> str:
    return f"{int(round(float(value or 0))):,} ریال"


def _fmt_pct(value: Any) -> str:
    if value is None:
        return "ناموجود"
    return f"{float(value):+.1f}٪"


def _finding_line(f: dict[str,Any]) -> str:
    e=f.get("evidence") or {}
    fid=f["id"]
    badge={"critical":"بحرانی","warning":"هشدار","info":"اطلاعات"}.get(f.get("severity"),"اطلاعات")
    if fid=="trial_balanced":
        return f"[{badge}] تراز قطعی متوازن است؛ اختلاف {_fmt_money(e.get('difference'))}."
    if fid=="trial_imbalance":
        return f"[{badge}] تراز قطعی نامتوازن است؛ اختلاف {_fmt_money(e.get('difference'))}."
    if fid in ("sales_complete_month_change","purchases_complete_month_change"):
        label="فروش" if fid.startswith("sales_") else "خرید"
        return (
            f"[{badge}] {label} قطعی از {e.get('previous_month')} با {_fmt_money(e.get('previous_total'))} "
            f"به {e.get('latest_month')} با {_fmt_money(e.get('latest_total'))} رسیده؛ تغییر {_fmt_pct(e.get('change_percent'))}."
        )
    if fid=="customer_concentration":
        return (
            f"[{badge}] تمرکز مشتری: {e.get('party')} برابر {_fmt_money(e.get('party_total'))} "
            f"از {_fmt_money(e.get('period_total'))} فروش دوره است؛ سهم {float(e.get('share_percent') or 0):.1f}٪."
        )
    if fid=="vendor_concentration":
        return (
            f"[{badge}] تمرکز تأمین‌کننده: {e.get('party')} برابر {_fmt_money(e.get('party_total'))} "
            f"از {_fmt_money(e.get('period_total'))} خرید دوره است؛ سهم {float(e.get('share_percent') or 0):.1f}٪."
        )
    if fid in ("sales_draft_exposure","purchases_draft_exposure"):
        label="فروش" if fid.startswith("sales_") else "خرید"
        share=e.get("share_percent")
        share_text=f" ({float(share):.1f}٪ از مبلغ ثبت‌شده)" if share is not None else ""
        return (
            f"[{badge}] {e.get('document_count',0)} سند {label} غیرقطعی به مبلغ {_fmt_money(e.get('net_total'))}"
            f"{share_text} وجود دارد."
        )
    if fid=="largest_account_balances":
        acc=e.get("accounts") or []
        parts=[f"{x.get('code')} {x.get('name')}: {_fmt_money(x.get('balance'))}" for x in acc[:5]]
        return f"[{badge}] بزرگ‌ترین مانده‌های قطعی: "+" • ".join(parts)
    if fid.endswith("_trend_insufficient"):
        return f"[{badge}] {f.get('title')}."
    return f"[{badge}] {f.get('title')}."


def render_report(metrics: dict[str,Any], findings: list[dict[str,Any]], priority_ids: list[str]) -> str:
    by_id={f["id"]:f for f in findings}
    ordered=[by_id[x] for x in priority_ids if x in by_id]
    remaining=[f for f in findings if f["id"] not in priority_ids]

    lines=[
        "گزارش هوشمندی مالی — فقط بر مبنای داده‌های قطعی/ابزارهای سرور",
        "",
        "اولویت‌های مدیریتی:",
    ]
    for i,f in enumerate(ordered,1):
        lines.append(f"{i}. {_finding_line(f)}")

    lines+=["","سایر یافته‌های grounded:"]
    for f in remaining:
        lines.append("• "+_finding_line(f))

    top=metrics["trial"]["top_balances"]
    if top:
        lines+=["","خلاصه مانده‌های مهم:"]
        for x in top[:5]:
            sign="بدهکار" if x["balance"]>=0 else "بستانکار"
            lines.append(f"• {x['code']} {x['name']}: {_fmt_money(abs(x['balance']))} {sign}")

    lines+=["","کنترل کیفیت داده:"]
    lines.append(
        f"• جمع بدهکار قطعی: {_fmt_money(metrics['trial']['total_debit'])} "
        f"• جمع بستانکار قطعی: {_fmt_money(metrics['trial']['total_credit'])} "
        f"• اختلاف: {_fmt_money(metrics['trial']['difference'])}"
    )
    lines.append(
        "• این گزارش سود خالص، جریان نقد یا نسبت‌های مالی‌ای را که داده کافی برایشان وجود ندارد حدس نمی‌زند."
    )
    return "\n".join(lines)


def execute_intelligence(worker: Any, job: dict[str,Any], tools_desc: list[dict[str,Any]]) -> tuple[str,dict[str,Any]]:
    required={"financial_analysis_bundle","trial_balance","document_analytics"}
    available={str(x.get("name") or "") for x in tools_desc if isinstance(x,dict)}
    missing=sorted(required-available)
    if missing:
        raise IntelligenceError("financial_intelligence_tools_missing:"+",".join(missing))

    worker.trace(job,"financial_intelligence_candidate","Broad financial intelligence request selected",{
        "version":INTELLIGENCE_VERSION,
    })

    results={}
    tools_used=[]
    plan=collect_tool_plan()
    for idx,(name,args,key) in enumerate(plan,1):
        worker.trace(job,"financial_intelligence_tool",f"Reading grounded financial dataset {idx}/{len(plan)}: {name}",{
            "dataset":key,"tool":name,"argument_keys":sorted(args.keys())
        })
        call_id=f"fi-v90-{job['id']}-{idx}-{key}"
        results[key]=worker.tool(job,name,args,call_id)
        tools_used.append(name)

    jalali_today=str(((job.get("context") or {}).get("jalali_today") or "")).strip()
    metrics=build_metrics(results,jalali_today)
    findings=build_findings(metrics)
    if not findings:
        raise IntelligenceError("financial_intelligence_no_findings")

    model=worker.model_for("agent")
    allowed_ids=[f["id"] for f in findings]
    worker.trace(job,"financial_intelligence_llm",f"Prioritizing grounded financial findings with {model}",{
        "model":model,"finding_ids":allowed_ids,"started_epoch":time.time()
    })

    llm_metrics={}
    fallback=False
    try:
        response=worker.ollama_chat(
            job,0,
            [
                {"role":"system","content":priority_prompt(findings)},
                {"role":"user","content":"Prioritize the grounded findings for a management financial review."},
            ],
            [],
            fast=True,
            model=model,
            num_ctx=1024,
            num_predict=96,
            temperature=0.0,
            timeout_seconds=90,
            response_format=priority_schema(allowed_ids),
            think_override=False,
        )
        llm_metrics=dict(response.get("_metrics") or {})
        model_priority=parse_priority((response.get("message") or {}).get("content"),findings)
        priority=canonical_priority(findings,model_priority,5)
        worker.trace(job,"financial_intelligence_prioritized","Grounded financial findings prioritized with deterministic severity gate",{
            "model_priority_ids":model_priority,
            "priority_ids":priority,
        })
    except Exception as e:
        fallback=True
        priority=canonical_priority(findings,fallback_priority(findings),5)
        worker.trace(job,"financial_intelligence_priority_fallback","LLM priority selection failed; deterministic severity order used",{
            "reason":str(e),"priority_ids":priority,
        })

    text=render_report(metrics,findings,priority)
    worker.trace(job,"financial_intelligence_complete","Financial Intelligence report completed",{
        "finding_count":len(findings),
        "priority_count":len(priority),
        "fallback":fallback,
    })
    return text,{
        "provider":"ollama+deterministic" if not fallback else "deterministic_fallback",
        "model":model if not fallback else "none",
        "mode":"financial_intelligence",
        "version":INTELLIGENCE_VERSION,
        "tools_used":tools_used,
        "finding_count":len(findings),
        "priority_findings":priority,
        "priority_fallback":fallback,
        "metrics":llm_metrics,
        "financial_metrics":metrics,
        "trace":list(getattr(worker,"current_trace",[]) or [])[-50:],
    }


def install_financial_intelligence(Worker: Any) -> None:
    if getattr(Worker,"_financial_intelligence_v1_installed",False):
        return
    old=Worker.process_agent

    def process_agent(self: Any, job: dict[str,Any], tools_desc: list[dict[str,Any]]):
        prompt=str(job.get("prompt") or "")
        if not is_intelligence_candidate(prompt):
            return old(self,job,tools_desc)
        try:
            return execute_intelligence(self,job,tools_desc)
        except IntelligenceError as e:
            self.trace(job,"financial_intelligence_blocked","Financial Intelligence request could not be grounded",{
                "reason":str(e)
            })
            return (
                "برای تحلیل مالی جامع، داده یا ابزار Grounded کافی در دسترس نبود؛ هیچ عددی حدس زده نشد."
            ),{
                "provider":"deterministic_block",
                "model":"none",
                "mode":"financial_intelligence_blocked",
                "blocked_reason":str(e),
                "tools_used":[],
            }

    Worker.process_agent=process_agent
    Worker._financial_intelligence_v1_installed=True
