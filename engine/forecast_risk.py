#!/usr/bin/env python3
from __future__ import annotations
import json, re, statistics, time
from typing import Any

PATCH_VERSION = "v9.1.0"
FORECAST_VERSION = "forecast-risk-anomaly-v1"
_DIGIT_TRANS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩","01234567890123456789")

class ForecastError(ValueError):
    pass

def norm(value: Any) -> str:
    text=str(value or "").translate(_DIGIT_TRANS)
    text=text.replace("ي","ی").replace("ك","ک").replace("\u200c"," ")
    return re.sub(r"\s+"," ",text).strip().lower()

def is_forecast_candidate(prompt: str) -> bool:
    p=norm(prompt)
    if not p:
        return False
    write_terms=("ثبت کن","بساز","ایجاد کن","پیشنهاد ثبت","پیش نویس","پیش‌نویس",
                 "تایید و اجرا","تأیید و اجرا","حذف کن","ویرایش کن","پرداخت کن","دریافت کن")
    if any(x in p for x in write_terms):
        return False
    strong=("پیش بینی","پیش‌بینی","forecast","ریسک","ناهنجاری","anomaly","ماه کامل جاری","آینده مالی")
    financial=("فروش","خرید","مالی","مطالبات","بدهی","تراز","شرکت","درآمد","هزینه")
    return any(x in p for x in strong) and any(x in p for x in financial)

def collect_tool_plan() -> list[tuple[str,dict[str,Any],str]]:
    return [
        ("trial_balance",{},"trial"),
        ("document_analytics",{"kind":"sales","period":"rolling_jalali_months","months":12,"status_scope":"confirmed","group_by":"jalali_month","limit":18},"sales_months"),
        ("document_analytics",{"kind":"purchases","period":"rolling_jalali_months","months":12,"status_scope":"confirmed","group_by":"jalali_month","limit":18},"purchase_months"),
        ("document_analytics",{"kind":"sales","period":"previous_jalali_month","status_scope":"confirmed","group_by":"party","limit":5},"sales_parties"),
        ("document_analytics",{"kind":"purchases","period":"previous_jalali_month","status_scope":"confirmed","group_by":"party","limit":5},"purchase_parties"),
        ("document_analytics",{"kind":"sales","period":"all","status_scope":"all","group_by":"status","limit":10},"sales_all"),
        ("document_analytics",{"kind":"sales","period":"all","status_scope":"confirmed","group_by":"none","limit":5},"sales_confirmed"),
        ("document_analytics",{"kind":"purchases","period":"all","status_scope":"all","group_by":"status","limit":10},"purchases_all"),
        ("document_analytics",{"kind":"purchases","period":"all","status_scope":"confirmed","group_by":"none","limit":5},"purchases_confirmed"),
    ]

def _rows(result: Any) -> list[dict[str,Any]]:
    if isinstance(result,list):
        return [x for x in result if isinstance(x,dict)]
    if isinstance(result,dict):
        for key in ("rows","groups","items","results","data"):
            v=result.get(key)
            if isinstance(v,list):
                return [x for x in v if isinstance(x,dict)]
    return []

def _summary(result: Any) -> dict[str,Any]:
    if isinstance(result,dict) and isinstance(result.get("summary"),dict):
        return dict(result["summary"])
    return {}

def _month_index(key: str) -> int:
    m=re.fullmatch(r"(\d{4})/(\d{2})",str(key or "").strip())
    if not m:
        raise ForecastError("jalali_month_key_invalid")
    y=int(m.group(1)); mo=int(m.group(2))
    if not 1<=mo<=12:
        raise ForecastError("jalali_month_invalid")
    return y*12+(mo-1)

def _month_key(index: int) -> str:
    return f"{index//12:04d}/{index%12+1:02d}"

def _current_month(jalali_today: str) -> str:
    text=str(jalali_today or "").translate(_DIGIT_TRANS)
    m=re.match(r"(\d{4})/(\d{2})",text)
    if not m:
        raise ForecastError("jalali_today_required")
    key=f"{int(m.group(1)):04d}/{int(m.group(2)):02d}"
    _month_index(key)
    return key

def _month_series(result: Any, jalali_today: str) -> list[dict[str,Any]]:
    current_idx=_month_index(_current_month(jalali_today))
    out=[]
    for g in _rows(result):
        key=str(g.get("key") or g.get("label") or "").strip()
        try:
            idx=_month_index(key)
        except ForecastError:
            continue
        if idx>=current_idx:
            continue
        out.append({"key":key,"index":idx,"document_count":int(g.get("document_count") or 0),"net_total":float(g.get("net_total") or 0)})
    out.sort(key=lambda x:x["index"])
    return out[-12:]

def _linear_forecast(series: list[dict[str,Any]], target_key: str) -> dict[str,Any]:
    if len(series)<3:
        return {"available":False,"reason":"حداقل سه ماه کامل دارای داده لازم است.","observations":len(series),"target_month":target_key}
    xs=[float(x["index"]-series[0]["index"]) for x in series]
    ys=[float(x["net_total"]) for x in series]
    xbar=sum(xs)/len(xs); ybar=sum(ys)/len(ys)
    denom=sum((x-xbar)**2 for x in xs)
    slope=0.0 if denom<=1e-12 else sum((x-xbar)*(y-ybar) for x,y in zip(xs,ys))/denom
    intercept=ybar-slope*xbar
    tx=float(_month_index(target_key)-series[0]["index"])
    forecast=max(0.0,intercept+slope*tx)
    fitted=[intercept+slope*x for x in xs]
    residuals=[y-yh for y,yh in zip(ys,fitted)]
    mae=sum(abs(r) for r in residuals)/len(residuals)
    ss_res=sum(r*r for r in residuals); ss_tot=sum((y-ybar)**2 for y in ys)
    r2=None if ss_tot<=1e-12 else max(-1.0,min(1.0,1.0-ss_res/ss_tot))
    scale=max(abs(forecast),abs(ybar),1.0)
    residual_ratio=mae/scale
    n=len(series)
    if n>=6 and residual_ratio<=0.15 and (r2 is None or r2>=0.55):
        confidence="high"; margin=max(mae*1.5,forecast*0.08)
    elif n>=4 and residual_ratio<=0.30 and (r2 is None or r2>=0.20):
        confidence="medium"; margin=max(mae*1.75,forecast*0.12)
    else:
        confidence="low"; margin=max(mae*2.0,forecast*0.20)
    last=series[-1]["net_total"]
    change=None if abs(last)<=0.01 else (forecast-last)/abs(last)*100.0
    return {
        "available":True,"method":"deterministic_linear_trend","target_month":target_key,"observations":n,
        "history_start":series[0]["key"],"history_end":series[-1]["key"],"forecast":forecast,
        "range_low":max(0.0,forecast-margin),"range_high":max(0.0,forecast+margin),
        "range_semantics":"approximate_error_band_not_confidence_interval",
        "slope_per_month":slope,"mae":mae,"r2":r2,"confidence":confidence,
        "change_from_latest_complete_percent":change,
    }

def _pct_change(previous: float,current: float) -> float|None:
    if abs(float(previous))<=0.01:
        return None
    return (float(current)-float(previous))/abs(float(previous))*100.0

def _cv(series: list[dict[str,Any]]) -> float|None:
    vals=[float(x["net_total"]) for x in series]
    if len(vals)<3:
        return None
    mean=sum(vals)/len(vals)
    if abs(mean)<=0.01:
        return None
    return statistics.pstdev(vals)/abs(mean)

def _robust_latest_anomaly(series: list[dict[str,Any]]) -> dict[str,Any]:
    if len(series)<4:
        return {"available":False,"reason":"حداقل چهار ماه کامل برای MAD anomaly لازم است."}
    latest=float(series[-1]["net_total"])
    base=[float(x["net_total"]) for x in series[:-1]]
    med=statistics.median(base)
    mad=statistics.median([abs(x-med) for x in base])
    if mad<=0.01:
        pct=None if abs(med)<=0.01 else (latest-med)/abs(med)*100.0
        return {"available":True,"method":"median_deviation_fallback","latest_month":series[-1]["key"],
                "latest":latest,"baseline_median":med,"score":None,"deviation_percent":pct,
                "flagged":pct is not None and abs(pct)>=40.0}
    score=0.6745*(latest-med)/mad
    return {"available":True,"method":"robust_mad","latest_month":series[-1]["key"],
            "latest":latest,"baseline_median":med,"score":score,"deviation_percent":None,
            "flagged":abs(score)>=3.5}

def _share(part: float,total: float) -> float|None:
    if abs(float(total))<=0.01:
        return None
    return float(part)/abs(float(total))*100.0

def _concentration(result: Any) -> dict[str,Any]:
    total=float(_summary(result).get("net_total") or 0)
    groups=_rows(result); top=groups[0] if groups else None
    top_total=float((top or {}).get("net_total") or 0)
    return {"total":total,"top_party":str((top or {}).get("label") or "") if top else "",
            "top_total":top_total,"share_percent":_share(top_total,total)}

def _draft_exposure(a_result: Any,c_result: Any) -> dict[str,Any]:
    a=_summary(a_result); c=_summary(c_result)
    docs=max(0,int(a.get("document_count") or 0)-int(c.get("document_count") or 0))
    amount=max(0.0,float(a.get("net_total") or 0)-float(c.get("net_total") or 0))
    return {"document_count":docs,"net_total":amount,"share_percent":_share(amount,float(a.get("net_total") or 0))}

def _trial_metrics(rows: list[dict[str,Any]]) -> dict[str,Any]:
    debit=sum(float(x.get("debit") or 0) for x in rows)
    credit=sum(float(x.get("credit") or 0) for x in rows)
    diff=debit-credit
    return {"total_debit":debit,"total_credit":credit,"difference":diff,"balanced":abs(diff)<=0.01}

def build_metrics(results: dict[str,Any],jalali_today: str) -> dict[str,Any]:
    target=_current_month(jalali_today)
    sales=_month_series(results.get("sales_months"),jalali_today)
    purchases=_month_series(results.get("purchase_months"),jalali_today)
    return {
        "jalali_today":jalali_today,"forecast_target_month":target,
        "sales_series":sales,"purchase_series":purchases,
        "sales_forecast":_linear_forecast(sales,target),
        "purchase_forecast":_linear_forecast(purchases,target),
        "sales_volatility_cv":_cv(sales),"purchase_volatility_cv":_cv(purchases),
        "sales_latest_anomaly":_robust_latest_anomaly(sales),
        "purchase_latest_anomaly":_robust_latest_anomaly(purchases),
        "customer_concentration":_concentration(results.get("sales_parties")),
        "vendor_concentration":_concentration(results.get("purchase_parties")),
        "sales_draft_exposure":_draft_exposure(results.get("sales_all"),results.get("sales_confirmed")),
        "purchase_draft_exposure":_draft_exposure(results.get("purchases_all"),results.get("purchases_confirmed")),
        "trial":_trial_metrics(_rows(results.get("trial"))),
    }

def _shift_severity(pct: float|None) -> str:
    if pct is None: return "info"
    a=abs(pct)
    if a>=50: return "critical"
    if a>=30: return "warning"
    return "info"

def build_findings(metrics: dict[str,Any]) -> list[dict[str,Any]]:
    f=[]
    for prefix,label in (("sales","فروش"),("purchase","خرید")):
        fc=metrics[f"{prefix}_forecast"]
        if fc.get("available"):
            f.append({"id":f"{prefix}_forecast","severity":"info","category":"forecast",
                      "title":f"پیش‌بینی ماه کامل جاری برای {label}","evidence":fc})
            if fc.get("confidence")=="low":
                f.append({"id":f"{prefix}_forecast_low_confidence","severity":"info","category":"forecast_quality",
                          "title":f"اعتماد پیش‌بینی {label} پایین است",
                          "evidence":{"observations":fc.get("observations"),"mae":fc.get("mae"),"r2":fc.get("r2")}})
        else:
            f.append({"id":f"{prefix}_forecast_insufficient","severity":"info","category":"forecast_quality",
                      "title":f"داده کافی برای پیش‌بینی {label} وجود ندارد",
                      "evidence":{"observations":fc.get("observations",0),"reason":fc.get("reason")}})
    for prefix,label,key in (("sales","فروش","sales_series"),("purchase","خرید","purchase_series")):
        series=metrics[key]
        if len(series)>=2:
            pct=_pct_change(series[-2]["net_total"],series[-1]["net_total"])
            f.append({"id":f"{prefix}_latest_month_shift","severity":_shift_severity(pct),"category":"trend_risk",
                      "title":f"تغییر {label} در آخرین ماه کامل",
                      "evidence":{"previous_month":series[-2]["key"],"previous_total":series[-2]["net_total"],
                                  "latest_month":series[-1]["key"],"latest_total":series[-1]["net_total"],
                                  "change_percent":pct}})
    for prefix,label,key in (("sales","فروش","sales_latest_anomaly"),("purchase","خرید","purchase_latest_anomaly")):
        an=metrics[key]
        if an.get("available") and an.get("flagged"):
            f.append({"id":f"{prefix}_robust_anomaly","severity":"warning","category":"anomaly_candidate",
                      "title":f"کاندید ناهنجاری در {label} آخرین ماه کامل","evidence":an})
    for prefix,label,key in (("sales","فروش","sales_volatility_cv"),("purchase","خرید","purchase_volatility_cv")):
        cv=metrics[key]
        if cv is not None and cv>=0.35:
            f.append({"id":f"{prefix}_high_volatility","severity":"critical" if cv>=0.60 else "warning",
                      "category":"volatility","title":f"نوسان ماهانه {label} بالاست",
                      "evidence":{"coefficient_of_variation":cv}})
    c=metrics["customer_concentration"]; cs=c.get("share_percent")
    if cs is not None:
        f.append({"id":"customer_concentration_risk","severity":"critical" if cs>=70 else ("warning" if cs>=50 else "info"),
                  "category":"concentration","title":"تمرکز فروش روی مشتری برتر","evidence":c})
    v=metrics["vendor_concentration"]; vs=v.get("share_percent")
    if vs is not None:
        f.append({"id":"vendor_concentration_risk","severity":"critical" if vs>=80 else ("warning" if vs>=60 else "info"),
                  "category":"concentration","title":"تمرکز خرید روی تأمین‌کننده برتر","evidence":v})
    for prefix,label,key in (("sales","فروش","sales_draft_exposure"),("purchase","خرید","purchase_draft_exposure")):
        d=metrics[key]
        if d["document_count"]>0 or d["net_total"]>0.01:
            sh=d.get("share_percent")
            f.append({"id":f"{prefix}_draft_exposure_risk","severity":"warning" if sh is not None and sh>=20 else "info",
                      "category":"pipeline","title":f"مواجهه با اسناد {label} غیرقطعی","evidence":d})
    t=metrics["trial"]
    f.append({"id":"trial_integrity","severity":"critical" if not t["balanced"] else "info","category":"integrity",
              "title":"کنترل تراز ثبت‌های قطعی","evidence":t})
    return f

def priority_schema(ids: list[str]) -> dict[str,Any]:
    if not ids: raise ForecastError("forecast_findings_empty")
    return {"type":"object","properties":{"priority_findings":{"type":"array","items":{"type":"string","enum":ids},
            "uniqueItems":True,"minItems":1,"maxItems":min(6,len(ids))}},
            "required":["priority_findings"],"additionalProperties":False}

def priority_prompt(findings: list[dict[str,Any]]) -> str:
    slim=[{"id":x["id"],"severity":x["severity"],"category":x["category"],"title":x["title"]} for x in findings]
    return ("Prioritize only the supplied grounded forecast/risk finding IDs. Do not create facts, numbers, "
            "thresholds, tools, ERP IDs, explanations, actions or new IDs. Return schema JSON only. FINDINGS="
            +json.dumps(slim,ensure_ascii=False,separators=(",",":")))

def parse_priority(raw: Any,findings: list[dict[str,Any]]) -> list[str]:
    allowed=[x["id"] for x in findings]
    try: obj=json.loads(str(raw or "").strip())
    except Exception as e: raise ForecastError("forecast_priority_json_invalid") from e
    if not isinstance(obj,dict) or set(obj)!={"priority_findings"}: raise ForecastError("forecast_priority_shape_invalid")
    vals=obj.get("priority_findings")
    if not isinstance(vals,list) or not vals: raise ForecastError("forecast_priority_empty")
    out=[]
    for x in vals:
        x=str(x)
        if x not in allowed: raise ForecastError("forecast_priority_unknown_id")
        if x not in out: out.append(x)
    return out[:6]

def canonical_priority(findings: list[dict[str,Any]],selected: list[str],limit: int=6) -> list[str]:
    by={x["id"]:x for x in findings}; selected=[x for x in selected if x in by]
    out=[]
    for sev in ("critical","warning","info"):
        tier=[x for x in selected if by[x].get("severity")==sev]
        tier += [x["id"] for x in findings if x.get("severity")==sev and x["id"] not in tier]
        for fid in tier:
            if fid not in out: out.append(fid)
            if len(out)>=limit: return out
    return out

def _fmt_money(v: Any) -> str: return f"{int(round(float(v or 0))):,} ریال"
def _fmt_pct(v: Any) -> str: return "ناموجود" if v is None else f"{float(v):+.1f}٪"
def _conf(v: str) -> str: return {"high":"بالا","medium":"متوسط","low":"پایین"}.get(str(v),"نامشخص")

def _finding_line(x: dict[str,Any]) -> str:
    e=x.get("evidence") or {}; fid=x["id"]; badge={"critical":"بحرانی","warning":"هشدار","info":"اطلاعات"}.get(x.get("severity"),"اطلاعات")
    if fid in ("sales_forecast","purchase_forecast"):
        label="فروش" if fid.startswith("sales") else "خرید"
        return (f"[{badge}] پیش‌بینی {label} ماه کامل {e.get('target_month')}: {_fmt_money(e.get('forecast'))}؛ "
                f"دامنه تقریبی {_fmt_money(e.get('range_low'))} تا {_fmt_money(e.get('range_high'))}؛ "
                f"اعتماد {_conf(e.get('confidence'))} با {e.get('observations')} ماه کامل.")
    if fid.endswith("_forecast_low_confidence"):
        return f"[{badge}] {x.get('title')}؛ تاریخچه/خطای مدل اجازه اعتماد بالاتر نمی‌دهد."
    if fid.endswith("_forecast_insufficient"):
        return f"[{badge}] {x.get('title')}؛ ماه‌های کامل: {e.get('observations',0)}."
    if fid.endswith("_latest_month_shift"):
        label="فروش" if fid.startswith("sales") else "خرید"
        return (f"[{badge}] {label} قطعی {e.get('previous_month')} → {e.get('latest_month')}: "
                f"{_fmt_money(e.get('previous_total'))} → {_fmt_money(e.get('latest_total'))}؛ تغییر {_fmt_pct(e.get('change_percent'))}.")
    if fid.endswith("_robust_anomaly"):
        label="فروش" if fid.startswith("sales") else "خرید"
        score=e.get("score"); extra=f"؛ MAD score={float(score):.2f}" if score is not None else ""
        return f"[{badge}] کاندید ناهنجاری {label} در {e.get('latest_month')}{extra}."
    if fid.endswith("_high_volatility"):
        return f"[{badge}] {x.get('title')}؛ CV={float(e.get('coefficient_of_variation') or 0)*100:.1f}٪."
    if fid=="customer_concentration_risk":
        return f"[{badge}] تمرکز مشتری: {e.get('top_party')} سهم {float(e.get('share_percent') or 0):.1f}٪ دارد."
    if fid=="vendor_concentration_risk":
        return f"[{badge}] تمرکز تأمین‌کننده: {e.get('top_party')} سهم {float(e.get('share_percent') or 0):.1f}٪ دارد."
    if fid.endswith("_draft_exposure_risk"):
        label="فروش" if fid.startswith("sales") else "خرید"
        sh=e.get("share_percent"); suffix=f"؛ سهم {float(sh):.1f}٪" if sh is not None else ""
        return f"[{badge}] {e.get('document_count')} سند {label} غیرقطعی به مبلغ {_fmt_money(e.get('net_total'))}{suffix}."
    if fid=="trial_integrity":
        return f"[{badge}] تراز قطعی: بدهکار {_fmt_money(e.get('total_debit'))}، بستانکار {_fmt_money(e.get('total_credit'))}، اختلاف {_fmt_money(e.get('difference'))}."
    return f"[{badge}] {x.get('title')}."

def render_report(metrics: dict[str,Any],findings: list[dict[str,Any]],priority: list[str]) -> str:
    by={x["id"]:x for x in findings}; chosen=[by[x] for x in priority if x in by]
    remaining=[x for x in findings if x["id"] not in priority]
    lines=["گزارش پیش‌بینی، ریسک و ناهنجاری مالی — Grounded / Read-only","",
           f"هدف پیش‌بینی: برآورد ماه کامل {metrics['forecast_target_month']} بر اساس ماه‌های کامل قبلی.","",
           "اولویت‌های مدیریتی:"]
    for i,x in enumerate(chosen,1): lines.append(f"{i}. {_finding_line(x)}")
    if remaining:
        lines+=["","سایر سیگنال‌های Grounded:"]
        lines += ["• "+_finding_line(x) for x in remaining]
    lines+=["","محدودیت مدل:",
            "• دامنه پیش‌بینی، بازه اطمینان آماری رسمی نیست؛ دامنه تقریبی خطا بر پایه MAE و اندازه تاریخچه است.",
            "• ماه جاری ناقص وارد آموزش Trend نمی‌شود؛ پیش‌بینی ماه کامل جاری از ماه‌های کامل قبلی ساخته می‌شود.",
            "• کاندید ناهنجاری به معنی خطا یا تقلب قطعی نیست و نیازمند بررسی حسابدار/مدیر مالی است.",
            "• بدون داده Grounded کافی، سود خالص، جریان نقد، ورشکستگی یا ریسک اعتباری حدس زده نمی‌شود."]
    return "\n".join(lines)

def execute_forecast(worker: Any,job: dict[str,Any],tools_desc: list[dict[str,Any]]) -> tuple[str,dict[str,Any]]:
    required={"trial_balance","document_analytics"}
    available={str(x.get("name") or "") for x in tools_desc if isinstance(x,dict)}
    missing=sorted(required-available)
    if missing: raise ForecastError("forecast_tools_missing:"+",".join(missing))
    jalali_today=str(((job.get("context") or {}).get("jalali_today") or "")).strip()
    _current_month(jalali_today)
    worker.trace(job,"forecast_risk_candidate","Forecast / risk / anomaly request selected",{"version":FORECAST_VERSION})
    results={}; used=[]; plan=collect_tool_plan()
    for idx,(name,args,key) in enumerate(plan,1):
        worker.trace(job,"forecast_risk_tool",f"Reading grounded predictive dataset {idx}/{len(plan)}: {name}",
                     {"dataset":key,"tool":name,"argument_keys":sorted(args.keys())})
        results[key]=worker.tool(job,name,args,f"fr-v91-{job['id']}-{idx}-{key}")
        used.append(name)
    metrics=build_metrics(results,jalali_today); findings=build_findings(metrics)
    if not findings: raise ForecastError("forecast_no_findings")
    model=worker.model_for("agent"); ids=[x["id"] for x in findings]
    worker.trace(job,"forecast_risk_llm",f"Prioritizing grounded forecast/risk findings with {model}",
                 {"model":model,"finding_ids":ids,"started_epoch":time.time()})
    fallback=False; llm_metrics={}; model_priority=[]
    try:
        resp=worker.ollama_chat(job,0,[{"role":"system","content":priority_prompt(findings)},
                                      {"role":"user","content":"Prioritize the grounded forecast/risk finding IDs."}],
                               [],fast=True,model=model,num_ctx=1024,num_predict=112,temperature=0.0,timeout_seconds=90,
                               response_format=priority_schema(ids),think_override=False)
        llm_metrics=dict(resp.get("_metrics") or {})
        model_priority=parse_priority((resp.get("message") or {}).get("content"),findings)
        priority=canonical_priority(findings,model_priority,6)
        worker.trace(job,"forecast_risk_prioritized","Grounded forecast/risk findings prioritized with deterministic severity gate",
                     {"model_priority_ids":model_priority,"priority_ids":priority})
    except Exception as e:
        fallback=True; priority=canonical_priority(findings,[],6)
        worker.trace(job,"forecast_risk_priority_fallback","Priority selector failed; deterministic severity gate used",
                     {"reason":str(e),"priority_ids":priority})
    text=render_report(metrics,findings,priority)
    worker.trace(job,"forecast_risk_complete","Forecast / risk / anomaly report completed",
                 {"finding_count":len(findings),"priority_count":len(priority),"priority_fallback":fallback})
    return text,{"provider":"ollama+deterministic" if not fallback else "deterministic_fallback",
                 "model":model if not fallback else "none","mode":"forecast_risk_anomaly","version":FORECAST_VERSION,
                 "tools_used":used,"finding_count":len(findings),"priority_findings":priority,
                 "model_priority_findings":model_priority,"priority_fallback":fallback,
                 "forecast_metrics":metrics,"metrics":llm_metrics,
                 "trace":list(getattr(worker,"current_trace",[]) or [])[-50:]}

def install_forecast_risk(Worker: Any) -> None:
    if getattr(Worker,"_forecast_risk_v1_installed",False): return
    old=Worker.process_agent
    def process_agent(self: Any,job: dict[str,Any],tools_desc: list[dict[str,Any]]):
        prompt=str(job.get("prompt") or "")
        if not is_forecast_candidate(prompt): return old(self,job,tools_desc)
        try: return execute_forecast(self,job,tools_desc)
        except ForecastError as e:
            self.trace(job,"forecast_risk_blocked","Forecast/risk request could not be grounded",{"reason":str(e)})
            return "برای پیش‌بینی/ریسک Grounded داده کافی یا زمینه زمانی معتبر در دسترس نبود؛ هیچ عددی حدس زده نشد.",{
                "provider":"deterministic_block","model":"none","mode":"forecast_risk_blocked",
                "blocked_reason":str(e),"tools_used":[]}
    Worker.process_agent=process_agent
    Worker._forecast_risk_v1_installed=True
