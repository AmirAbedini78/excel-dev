#!/usr/bin/env python3
"""ERPSMART v9.2.0 — Proactive Accounting Agent Core.

This is a proactive *recommendation* layer, not an autonomous mutation layer.

Grounded accounting history
  -> v9.1 deterministic forecast/risk findings
  -> extra deterministic AR/AP burden signals
  -> server-owned next-best-action candidates
  -> bounded Qwen recommendation-ID prioritization
  -> deterministic severity/impact gate
  -> action handoff guidance

No Proposal, voucher, invoice or database mutation is created by this module.
Existing v8.9 Action Orchestrator remains the only first write bridge and still
requires explicit user parameters + human approval.
"""
from __future__ import annotations

import hashlib
import json
import re
import time
from typing import Any

import forecast_risk as FR

PATCH_VERSION="v9.2.0"
PROACTIVE_VERSION="proactive-accounting-agent-v1"

class ProactiveError(ValueError):
    pass


def norm(value: Any) -> str:
    return FR.norm(value)


def is_proactive_candidate(prompt: str) -> bool:
    p=norm(prompt)
    if not p:
        return False

    explicit_write=(
        "ثبت کن","سند بساز","سند ایجاد کن","فاکتور بساز","فاکتور ایجاد کن",
        "پیشنهاد ثبت","پرداخت کن","دریافت ثبت","تایید و اجرا","تأیید و اجرا",
    )
    if any(x in p for x in explicit_write):
        return False

    proactive_terms=(
        "پیش دستانه","پیش‌دستانه","خودت بررسی کن","خودت تحلیل کن",
        "اقدام های بعدی","اقدام‌های بعدی","اقدامات بعدی",
        "اقدام های پیشنهادی","اقدام‌های پیشنهادی","next best action",
        "proactive","الان چه کار کنم","چه کارهایی باید انجام",
    )
    financial_terms=("حسابداری","مالی","فروش","خرید","مطالبات","بدهی","تراز","شرکت")
    return any(x in p for x in proactive_terms) and any(x in p for x in financial_terms)


def _trial_rows(result: Any) -> list[dict[str,Any]]:
    return FR._rows(result)


def _commercial_balance(rows: list[dict[str,Any]], kind: str) -> dict[str,Any]:
    matches=[]
    total=0.0
    for row in rows:
        name=norm(row.get("name"))
        english=name
        if kind=="receivables":
            matched=(("دریافتنی" in name and "تجاری" in name) or "accounts receivable" in english)
            raw=float(row.get("balance") or 0)
            magnitude=max(0.0,raw)
        else:
            matched=(("پرداختنی" in name and "تجاری" in name) or "accounts payable" in english)
            raw=float(row.get("balance") or 0)
            magnitude=max(0.0,-raw)
        if not matched or magnitude<=0.01:
            continue
        total+=magnitude
        matches.append({
            "code":str(row.get("code") or ""),
            "name":str(row.get("name") or ""),
            "balance":raw,
            "magnitude":magnitude,
        })
    return {"available":bool(matches),"amount":total,"accounts":matches}


def _latest_complete_total(metrics: dict[str,Any], key: str) -> float:
    series=list(metrics.get(key) or [])
    return float(series[-1].get("net_total") or 0) if series else 0.0


def _ratio(amount: float, denominator: float) -> float|None:
    if abs(float(denominator))<=0.01:
        return None
    return float(amount)/abs(float(denominator))


def build_extra_signals(results: dict[str,Any], metrics: dict[str,Any]) -> list[dict[str,Any]]:
    rows=_trial_rows(results.get("trial"))
    ar=_commercial_balance(rows,"receivables")
    ap=_commercial_balance(rows,"payables")
    latest_sales=_latest_complete_total(metrics,"sales_series")
    latest_purchases=_latest_complete_total(metrics,"purchase_series")
    ar_ratio=_ratio(ar["amount"],latest_sales) if ar["available"] else None
    ap_ratio=_ratio(ap["amount"],latest_purchases) if ap["available"] else None

    signals=[]
    if ar["available"]:
        sev="critical" if ar_ratio is not None and ar_ratio>=2.5 else ("warning" if ar_ratio is not None and ar_ratio>=1.5 else "info")
        signals.append({
            "id":"receivables_burden",
            "severity":sev,
            "category":"liquidity_attention",
            "title":"بار مطالبات تجاری نسبت به فروش ماه کامل اخیر",
            "evidence":{
                "amount":ar["amount"],
                "latest_complete_sales":latest_sales,
                "months_equivalent":ar_ratio,
                "accounts":ar["accounts"],
                "heuristic_thresholds":{"warning":1.5,"critical":2.5},
            },
        })
    if ap["available"]:
        sev="critical" if ap_ratio is not None and ap_ratio>=2.5 else ("warning" if ap_ratio is not None and ap_ratio>=1.5 else "info")
        signals.append({
            "id":"payables_burden",
            "severity":sev,
            "category":"liquidity_attention",
            "title":"بار بدهی تجاری نسبت به خرید ماه کامل اخیر",
            "evidence":{
                "amount":ap["amount"],
                "latest_complete_purchases":latest_purchases,
                "months_equivalent":ap_ratio,
                "accounts":ap["accounts"],
                "heuristic_thresholds":{"warning":1.5,"critical":2.5},
            },
        })
    return signals


def _finding_map(findings: list[dict[str,Any]]) -> dict[str,dict[str,Any]]:
    return {str(x.get("id")):x for x in findings if isinstance(x,dict) and x.get("id")}


def build_recommendations(findings: list[dict[str,Any]], extra: list[dict[str,Any]]) -> list[dict[str,Any]]:
    by=_finding_map(findings)
    recommendations=[]

    def add(rid: str, severity: str, impact: int, title: str, why: str,
            source_ids: list[str], next_step: str, action_bridge: dict[str,Any]|None=None):
        recommendations.append({
            "id":rid,
            "severity":severity,
            "impact_score":int(impact),
            "title":title,
            "why":why,
            "source_finding_ids":source_ids,
            "next_step":next_step,
            "action_bridge":action_bridge or {"proposal_ready":False},
        })

    trial=by.get("trial_integrity")
    if trial and trial.get("severity")=="critical":
        e=trial.get("evidence") or {}
        add(
            "reconcile_trial_imbalance","critical",100,
            "ابتدا عدم توازن ثبت‌های قطعی را بررسی و رفع کن",
            f"اختلاف تراز قطعی {int(round(float(e.get('difference') or 0))):,} ریال است.",
            ["trial_integrity"],
            "تا قبل از روشن شدن علت اختلاف، اقدام مالی پیشنهادی دیگری را نهایی نکن.",
        )

    for prefix,label in (("sales","فروش"),("purchase","خرید")):
        f=by.get(f"{prefix}_latest_month_shift")
        if not f:
            continue
        e=f.get("evidence") or {}
        pct=e.get("change_percent")
        if pct is None:
            continue
        pct=float(pct)
        if pct<=-30:
            add(
                f"investigate_{prefix}_decline",str(f.get("severity") or "warning"),85,
                f"علت افت {label} قطعی را بررسی کن",
                f"{label} ماه کامل اخیر نسبت به ماه قبل {abs(pct):.1f}٪ کاهش یافته است.",
                [f["id"]],
                "علت عملیاتی/فصلی/تأمین/فروش را با اسناد همان دو ماه تطبیق بده؛ صرف کاهش به معنی مشکل قطعی نیست.",
            )
        elif pct>=50:
            add(
                f"validate_{prefix}_spike",str(f.get("severity") or "warning"),75,
                f"افزایش غیرعادی {label} را راستی‌آزمایی کن",
                f"{label} ماه کامل اخیر نسبت به ماه قبل {pct:.1f}٪ افزایش یافته است.",
                [f["id"]],
                "اسناد و طرف‌حساب‌های اصلی این تغییر را بررسی کن تا افزایش واقعی از خطای ثبت جدا شود.",
            )

    for f in findings:
        fid=str(f.get("id") or "")
        sev=str(f.get("severity") or "info")
        e=f.get("evidence") or {}
        if fid=="sales_draft_exposure_risk":
            share=e.get("share_percent")
            add(
                "review_nonfinal_sales",sev,70 if sev!="info" else 52,
                "اسناد فروش غیرقطعی را تعیین تکلیف کن",
                f"{int(e.get('document_count') or 0)} سند فروش غیرقطعی با سهم {float(share or 0):.1f}٪ از مبلغ ثبت‌شده وجود دارد.",
                [fid],
                "اسناد را از نظر تکمیل مدارک، تایید یا حذف اشتباه بررسی کن؛ این Agent خودش وضعیت سند را تغییر نمی‌دهد.",
            )
        elif fid=="purchase_draft_exposure_risk":
            share=e.get("share_percent")
            add(
                "review_nonfinal_purchases",sev,66 if sev!="info" else 48,
                "اسناد خرید غیرقطعی را تعیین تکلیف کن",
                f"{int(e.get('document_count') or 0)} سند خرید غیرقطعی با سهم {float(share or 0):.1f}٪ وجود دارد.",
                [fid],
                "قبل از اتکا به تحلیل خرید، اسناد معلق را بررسی کن.",
            )
        elif fid=="customer_concentration_risk" and sev in ("warning","critical"):
            add(
                "review_customer_concentration",sev,72,
                "ریسک تمرکز فروش روی مشتری برتر را بررسی کن",
                f"سهم مشتری برتر {float(e.get('share_percent') or 0):.1f}٪ است.",
                [fid],
                "وابستگی فروش و سناریوی افت سفارش این مشتری را بررسی کن.",
            )
        elif fid=="vendor_concentration_risk" and sev in ("warning","critical"):
            add(
                "review_vendor_concentration",sev,74,
                "وابستگی به تأمین‌کننده برتر را بررسی کن",
                f"سهم تأمین‌کننده برتر {float(e.get('share_percent') or 0):.1f}٪ است.",
                [fid],
                "ریسک جایگزینی تأمین‌کننده و اثر توقف تأمین را بررسی کن.",
            )
        elif fid.endswith("_robust_anomaly"):
            add(
                "investigate_"+fid,sev,88,
                "کاندید ناهنجاری آماری را با اسناد واقعی بررسی کن",
                "آخرین ماه کامل نسبت به baseline تاریخی کاندید ناهنجاری شده است.",
                [fid],
                "این سیگنال اثبات خطا/تقلب نیست؛ اسناد و رخدادهای واقعی دوره را تطبیق بده.",
            )
        elif fid.endswith("_high_volatility"):
            add(
                "review_"+fid,sev,78,
                "علت نوسان بالای روند را بررسی کن",
                f"ضریب تغییرات مشاهده‌شده {float(e.get('coefficient_of_variation') or 0)*100:.1f}٪ است.",
                [fid],
                "تغییرات فصلی، پروژه‌ای و ثبت‌های غیرتکراری را از روند پایه جدا کن.",
            )

    for s in extra:
        sid=s["id"]; sev=s["severity"]; e=s.get("evidence") or {}
        if sid=="receivables_burden" and sev in ("warning","critical"):
            ratio=e.get("months_equivalent")
            add(
                "prepare_receivables_collection_review",sev,94,
                "برنامه وصول مطالبات تجاری را در اولویت قرار بده",
                f"مطالبات تجاری تقریباً {float(ratio or 0):.2f} برابر فروش ماه کامل اخیر است.",
                [sid],
                "ابتدا مشتری/مانده‌های واقعی را بررسی کن. برای ساخت Proposal دریافت، مشتری، مبلغ ریالی و حساب‌های بدهکار/بستانکار باید صریحاً توسط کاربر تعیین شوند.",
                {
                    "proposal_ready":False,
                    "existing_bridge":"v8.9 receipt action orchestrator",
                    "required_user_inputs":["customer","amount_rial","debit_account","credit_account"],
                    "human_approval_required":True,
                },
            )
        elif sid=="payables_burden" and sev in ("warning","critical"):
            ratio=e.get("months_equivalent")
            add(
                "review_payables_schedule",sev,96,
                "برنامه پرداخت بدهی‌های تجاری را بازبینی کن",
                f"بدهی تجاری تقریباً {float(ratio or 0):.2f} برابر خرید ماه کامل اخیر است.",
                [sid],
                "سررسیدها، نقدینگی و اولویت پرداخت را بررسی کن. v9.2 هیچ پرداخت یا سند پرداختی ایجاد نمی‌کند.",
            )

    # Data quality next step if forecasts are still low-confidence.
    low=[x for x in findings if str(x.get("id") or "").endswith("_forecast_low_confidence")]
    if low:
        add(
            "improve_forecast_history","info",30,
            "تاریخچه بیشتری برای Forecast جمع کن",
            "حداقل یکی از Forecastها هنوز اعتماد پایین دارد.",
            [x["id"] for x in low],
            "با افزایش ماه‌های کامل واقعی، دقت/اعتماد Forecast را دوباره ارزیابی کن.",
        )

    # Stable de-dup.
    unique={}
    for r in recommendations:
        unique.setdefault(r["id"],r)
    return list(unique.values())


def recommendation_schema(ids: list[str]) -> dict[str,Any]:
    if not ids:
        raise ProactiveError("proactive_recommendations_empty")
    return {
        "type":"object",
        "properties":{
            "priority_actions":{
                "type":"array","items":{"type":"string","enum":ids},
                "uniqueItems":True,"minItems":1,"maxItems":min(6,len(ids)),
            }
        },
        "required":["priority_actions"],
        "additionalProperties":False,
    }


def recommendation_prompt(recs: list[dict[str,Any]]) -> str:
    slim=[
        {"id":r["id"],"severity":r["severity"],"impact_score":r["impact_score"],"title":r["title"]}
        for r in recs
    ]
    return (
        "Prioritize only the supplied grounded accounting next-best-action IDs. "
        "Do not create amounts, parties, accounts, ERP IDs, tools, proposals, vouchers, "
        "financial facts, actions, explanations or new IDs. Return schema JSON only. ACTIONS="
        +json.dumps(slim,ensure_ascii=False,separators=(",",":"))
    )


def parse_recommendation_priority(raw: Any, recs: list[dict[str,Any]]) -> list[str]:
    allowed=[r["id"] for r in recs]
    try:
        obj=json.loads(str(raw or "").strip())
    except Exception as e:
        raise ProactiveError("proactive_priority_json_invalid") from e
    if not isinstance(obj,dict) or set(obj)!={"priority_actions"}:
        raise ProactiveError("proactive_priority_shape_invalid")
    vals=obj.get("priority_actions")
    if not isinstance(vals,list) or not vals:
        raise ProactiveError("proactive_priority_empty")
    out=[]
    for x in vals:
        x=str(x)
        if x not in allowed:
            raise ProactiveError("proactive_priority_unknown_id")
        if x not in out:
            out.append(x)
    return out[:6]


def canonical_priority(recs: list[dict[str,Any]], selected: list[str], limit: int=6) -> list[str]:
    by={r["id"]:r for r in recs}
    selected=[x for x in selected if x in by]
    severity_rank={"critical":0,"warning":1,"info":2}

    out=[]
    for sev in ("critical","warning","info"):
        tier=[r for r in recs if r.get("severity")==sev]
        # Higher deterministic impact first. LLM only breaks exact-impact ties.
        selected_pos={x:i for i,x in enumerate(selected)}
        tier.sort(key=lambda r:(-int(r.get("impact_score") or 0), selected_pos.get(r["id"],9999)))
        for r in tier:
            if r["id"] not in out:
                out.append(r["id"])
            if len(out)>=limit:
                return out
    return out


def _fmt_money(v: Any) -> str:
    return f"{int(round(float(v or 0))):,} ریال"


def _action_line(r: dict[str,Any]) -> str:
    badge={"critical":"بحرانی","warning":"هشدار","info":"اطلاعات"}.get(r.get("severity"),"اطلاعات")
    return f"[{badge}] {r['title']} — {r['why']} اقدام بعدی: {r['next_step']}"


def render_report(recs: list[dict[str,Any]], priority: list[str], extra: list[dict[str,Any]]) -> str:
    by={r["id"]:r for r in recs}
    chosen=[by[x] for x in priority if x in by]
    remaining=[r for r in recs if r["id"] not in priority]

    lines=[
        "پایش پیش‌دستانه حسابداری — Grounded / Recommendation-only",
        "",
        "اقدام‌های بعدی پیشنهادی:",
    ]
    for i,r in enumerate(chosen,1):
        lines.append(f"{i}. {_action_line(r)}")

    if remaining:
        lines+=["","اقدام‌های کم‌اولویت‌تر:"]
        for r in remaining:
            lines.append("• "+_action_line(r))

    bridges=[r for r in recs if (r.get("action_bridge") or {}).get("existing_bridge")]
    if bridges:
        lines+=["","پل امن به Action:"]
        for r in bridges:
            b=r["action_bridge"]
            lines.append(
                "• "+r["title"]+": برای تبدیل این توصیه به Proposal، ورودی‌های صریح "
                +"، ".join(b.get("required_user_inputs") or [])
                +" لازم است و تایید انسانی اجباری است."
            )

    lines+=["","مرز ایمنی:"]
    lines.append("• این مسیر هیچ Proposal، سند، فاکتور، پرداخت یا دریافت ایجاد نکرده است.")
    lines.append("• توصیه‌ها از داده‌های Grounded و قواعد deterministic ساخته شده‌اند؛ مدل فقط ID توصیه‌های موجود را اولویت‌بندی می‌کند.")
    lines.append("• نسبت مطالبات/بدهی به ماه اخیر یک heuristic مدیریتی است، نه استاندارد حسابداری یا پیش‌بینی جریان نقد.")
    lines.append("• تصمیم نهایی و هر Mutation مالی همچنان نیازمند کاربر و Human Approval است.")
    return "\n".join(lines)


def execute_proactive(worker: Any, job: dict[str,Any], tools_desc: list[dict[str,Any]]) -> tuple[str,dict[str,Any]]:
    required={"trial_balance","document_analytics"}
    available={str(x.get("name") or "") for x in tools_desc if isinstance(x,dict)}
    missing=sorted(required-available)
    if missing:
        raise ProactiveError("proactive_tools_missing:"+",".join(missing))

    jalali_today=str(((job.get("context") or {}).get("jalali_today") or "")).strip()
    FR._current_month(jalali_today)

    worker.trace(job,"proactive_candidate","Proactive accounting review selected",{
        "version":PROACTIVE_VERSION,
    })

    results={}; used=[]; plan=FR.collect_tool_plan()
    for idx,(name,args,key) in enumerate(plan,1):
        worker.trace(job,"proactive_tool",f"Reading grounded proactive dataset {idx}/{len(plan)}: {name}",{
            "dataset":key,"tool":name,"argument_keys":sorted(args.keys()),
        })
        results[key]=worker.tool(job,name,args,f"pa-v92-{job['id']}-{idx}-{key}")
        used.append(name)

    metrics=FR.build_metrics(results,jalali_today)
    findings=FR.build_findings(metrics)
    extra=build_extra_signals(results,metrics)
    recs=build_recommendations(findings,extra)

    if not recs:
        worker.trace(job,"proactive_no_action","No material next-best action generated from current grounded findings",{})
        return (
            "در پایش فعلی اقدام فوری و Grounded با اولویت هشدار/بحرانی پیدا نشد. "
            "هیچ عملیات مالی اجرا نشد."
        ),{
            "provider":"deterministic",
            "model":"none",
            "mode":"proactive_accounting_no_action",
            "tools_used":used,
            "recommendation_count":0,
        }

    worker.trace(job,"proactive_recommendations_built","Server-built grounded next-best-action candidates",{
        "recommendation_ids":[r["id"] for r in recs],
    })

    model=worker.model_for("agent")
    ids=[r["id"] for r in recs]
    worker.trace(job,"proactive_llm",f"Prioritizing grounded next-best-action IDs with {model}",{
        "model":model,"recommendation_ids":ids,"started_epoch":time.time(),
    })

    fallback=False; llm_metrics={}; model_priority=[]
    try:
        resp=worker.ollama_chat(
            job,0,
            [
                {"role":"system","content":recommendation_prompt(recs)},
                {"role":"user","content":"Prioritize the grounded accounting next-best-action IDs."},
            ],
            [],
            fast=True,
            model=model,
            num_ctx=1024,
            num_predict=96,
            temperature=0.0,
            timeout_seconds=90,
            response_format=recommendation_schema(ids),
            think_override=False,
        )
        llm_metrics=dict(resp.get("_metrics") or {})
        model_priority=parse_recommendation_priority((resp.get("message") or {}).get("content"),recs)
        priority=canonical_priority(recs,model_priority,6)
        worker.trace(job,"proactive_prioritized","Grounded next-best actions prioritized with deterministic severity/impact gate",{
            "model_priority_ids":model_priority,
            "priority_ids":priority,
        })
    except Exception as e:
        fallback=True
        priority=canonical_priority(recs,[],6)
        worker.trace(job,"proactive_priority_fallback","Priority selector failed; deterministic severity/impact gate used",{
            "reason":str(e),"priority_ids":priority,
        })

    text=render_report(recs,priority,extra)
    worker.trace(job,"proactive_complete","Proactive accounting recommendation report completed",{
        "recommendation_count":len(recs),
        "priority_count":len(priority),
        "priority_fallback":fallback,
        "proposal_created":False,
    })

    feedback_keys={
        r["id"]:"pa-"+hashlib.sha256(
            json.dumps({"v":PROACTIVE_VERSION,"id":r["id"],"sources":r["source_finding_ids"]},
                       ensure_ascii=False,sort_keys=True).encode()
        ).hexdigest()[:16]
        for r in recs
    }

    return text,{
        "provider":"ollama+deterministic" if not fallback else "deterministic_fallback",
        "model":model if not fallback else "none",
        "mode":"proactive_accounting",
        "version":PROACTIVE_VERSION,
        "tools_used":used,
        "recommendations":recs,
        "priority_actions":priority,
        "model_priority_actions":model_priority,
        "priority_fallback":fallback,
        "proposal_created":False,
        "feedback_keys":feedback_keys,
        "forecast_metrics":metrics,
        "metrics":llm_metrics,
        "trace":list(getattr(worker,"current_trace",[]) or [])[-50:],
    }


def install_proactive_agent(Worker: Any) -> None:
    if getattr(Worker,"_proactive_accounting_v1_installed",False):
        return
    old=Worker.process_agent

    def process_agent(self: Any, job: dict[str,Any], tools_desc: list[dict[str,Any]]):
        prompt=str(job.get("prompt") or "")
        if not is_proactive_candidate(prompt):
            return old(self,job,tools_desc)
        try:
            return execute_proactive(self,job,tools_desc)
        except ProactiveError as e:
            self.trace(job,"proactive_blocked","Proactive accounting request could not be grounded",{
                "reason":str(e),
            })
            return (
                "برای پایش پیش‌دستانه داده Grounded کافی یا زمینه زمانی معتبر در دسترس نبود؛ "
                "هیچ اقدام یا Proposal ساخته نشد."
            ),{
                "provider":"deterministic_block",
                "model":"none",
                "mode":"proactive_accounting_blocked",
                "blocked_reason":str(e),
                "proposal_created":False,
                "tools_used":[],
            }

    Worker.process_agent=process_agent
    Worker._proactive_accounting_v1_installed=True
