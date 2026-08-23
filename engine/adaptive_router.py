#!/usr/bin/env python3
"""ERPSMART v8.7 adaptive semantic read router.

Caches validated READ PLANS, never financial answers.
Exact normalized prompt keys are used in v1 to avoid unsafe semantic over-generalization.
All business IDs and financial facts still come from fresh server tools.
"""
from __future__ import annotations
import hashlib,json,re,time
from typing import Any
import read_guard as rg

PATCH_VERSION="v8.7.0"
PLANNER_VERSION="adaptive-read-v1"
FALLBACK_CONTRACT_VERSION="erp-read-v86.1"

SAFE_READ_TERMS=(
    "فروش","خرید","مشتری","طرف حساب","طرف‌حساب","کالا","محصول","آیتم",
    "تراز","مانده","گردش","گزارش","سند","فاکتور","صورتحساب","مالی","درآمد"
)
DEEP_TERMS=("تحلیل عمیق","ریسک","سناریو","پیش بینی","پیش‌بینی","forecast")
ALLOWED_INTENTS={
    "company_snapshot","sales_total","purchase_total","totals",
    "recent_sales","recent_purchases","recent_both","trial_balance",
    "party_search","party_ledger","item_search",
    "document_analytics","compare_periods",
}
ALLOWED_KEYS={
    "intent","query","limit","kind","period","months","date_from","date_to",
    "jalali_year","jalali_month","status_scope","group_by",
    "party_query","item_query","left_period","right_period","needs_entity_parse",
}
PERIODS={
    "all","current_jalali_month","previous_jalali_month","current_jalali_year",
    "previous_jalali_year","rolling_jalali_months","custom","custom_jalali_month",
}
SCOPES={"all","confirmed","draft","approved","final"}
GROUPS={"none","party","item","jalali_month","status"}

def normalize_prompt(prompt:str)->str:
    return rg.norm(prompt)

def route_key(prompt:str)->str:
    return hashlib.sha256(normalize_prompt(prompt).encode("utf-8")).hexdigest()

def contract_version(job:dict[str,Any])->str:
    ctx=job.get("context") if isinstance(job.get("context"),dict) else {}
    v=str((ctx or {}).get("ai_route_contract_version") or FALLBACK_CONTRACT_VERSION).strip()
    return v[:64] or FALLBACK_CONTRACT_VERSION

def safe_candidate(prompt:str)->bool:
    n=rg.norm(prompt)
    if not n:return False
    if any(x in n for x in rg.WRITE):return False
    if any(x in n for x in DEEP_TERMS):return False
    return any(x in n for x in SAFE_READ_TERMS)

def built_in_handles(prompt:str)->bool:
    parts=rg.split_multi(prompt)
    if len(parts)>1:
        contextual=rg.contextualize_parts(parts)
        return bool(contextual) and all(rg.route(p) is not None for p in contextual)
    return rg.route(prompt) is not None

def _grounded_substring(value:str,prompt:str)->bool:
    return bool(value) and rg.norm(value) in rg.norm(prompt)

def sanitize_plan(plan:Any,prompt:str)->dict[str,Any]:
    if not isinstance(plan,dict):raise ValueError("adaptive_plan_not_object")
    unknown=set(plan)-ALLOWED_KEYS
    if unknown:raise ValueError("adaptive_plan_unknown_keys:"+",".join(sorted(unknown)))
    if any(k.endswith("_id") for k in plan):raise ValueError("adaptive_plan_ids_forbidden")

    intent=str(plan.get("intent") or "").strip()
    if intent not in ALLOWED_INTENTS:raise ValueError("adaptive_plan_intent_not_allowed")
    out={"intent":intent}

    det_period=rg.period_of(prompt)
    det_scope=rg.semantic_scope_of(prompt)
    det_entities=rg.entity_queries(prompt)
    det_group=rg.group_of(prompt)
    constrained=(det_period.get("period")!="all" or det_scope!="all" or
                 bool(det_entities.get("party_query")) or bool(det_entities.get("item_query")) or det_group!="none")

    if intent in {"company_snapshot","sales_total","purchase_total","totals","trial_balance",
                  "recent_sales","recent_purchases","recent_both"} and constrained:
        raise ValueError("adaptive_simple_intent_would_drop_constraints")
    if intent=="party_ledger" and det_period.get("period")!="all":
        raise ValueError("adaptive_ledger_period_not_supported")

    if intent in {"party_search","party_ledger","item_search"}:
        q=str(plan.get("query") or "").strip()
        if not _grounded_substring(q,prompt):raise ValueError("adaptive_query_not_grounded")
        out["query"]=q
        out["limit"]=max(1,min(20,int(plan.get("limit") or 5)))
        return out

    if intent in {"recent_sales","recent_purchases","recent_both"}:
        requested=rg.limit_of(prompt,0)
        lim=requested if requested>0 else max(1,min(20,int(plan.get("limit") or 5)))
        out["query"]="";out["limit"]=lim
        return out

    if intent in {"company_snapshot","sales_total","purchase_total","totals","trial_balance"}:
        return {**out,"query":"","limit":5}

    kind=str(plan.get("kind") or "").strip()
    if kind not in {"sales","purchases"}:raise ValueError("adaptive_kind_invalid")
    n=rg.norm(prompt)
    if "فروش" in n and kind!="sales":raise ValueError("adaptive_kind_conflict_sales")
    if "خرید" in n and kind!="purchases":raise ValueError("adaptive_kind_conflict_purchases")
    out["kind"]=kind

    scope=str(plan.get("status_scope") or "all").strip()
    if scope not in SCOPES:raise ValueError("adaptive_scope_invalid")
    deterministic_scope=rg.semantic_scope_of(prompt)
    if deterministic_scope!="all" and scope!=deterministic_scope:raise ValueError("adaptive_scope_conflict")
    if deterministic_scope=="all" and scope!="all":raise ValueError("adaptive_scope_not_grounded")
    out["status_scope"]=scope

    for ek in ("party_query","item_query"):
        q=str(plan.get(ek) or "").strip()
        if q:
            if not _grounded_substring(q,prompt):raise ValueError("adaptive_entity_not_grounded:"+ek)
            out[ek]=q
        else:out[ek]=""

    det_entities=rg.entity_queries(prompt)
    for ek in ("party_query","item_query"):
        if det_entities.get(ek) and rg.norm(out.get(ek))!=rg.norm(det_entities[ek]):
            raise ValueError("adaptive_entity_conflict:"+ek)

    det_period=rg.period_of(prompt)
    period=str(plan.get("period") or det_period.get("period") or "all").strip()
    if period not in PERIODS:raise ValueError("adaptive_period_invalid")
    if det_period.get("period")!="all" and period!=det_period.get("period"):
        raise ValueError("adaptive_period_conflict")
    if det_period.get("period")=="all" and period!="all":
        raise ValueError("adaptive_period_not_grounded")
    out["period"]=period

    if period=="rolling_jalali_months":
        det_m=int(det_period.get("months") or rg.month_count_of(prompt,0) or 0)
        pm=int(plan.get("months") or det_m or 3)
        if det_m and pm!=det_m:raise ValueError("adaptive_month_count_conflict")
        out["months"]=max(1,min(24,det_m or pm))
    elif period=="custom":
        for key in ("date_from","date_to"):
            val=str(plan.get(key) or det_period.get(key) or "").strip()
            if not val or rg.norm(val) not in rg.norm(prompt):raise ValueError("adaptive_custom_date_not_grounded:"+key)
            out[key]=val
    elif period=="custom_jalali_month":
        y=int(plan.get("jalali_year") or det_period.get("jalali_year") or 0)
        m=int(plan.get("jalali_month") or det_period.get("jalali_month") or 0)
        if y!=int(det_period.get("jalali_year") or 0) or m!=int(det_period.get("jalali_month") or 0):
            raise ValueError("adaptive_jalali_month_conflict")
        out["jalali_year"]=y;out["jalali_month"]=m

    if intent=="document_analytics":
        group=str(plan.get("group_by") or "none").strip()
        if group not in GROUPS:raise ValueError("adaptive_group_invalid")
        det_group=rg.group_of(prompt)
        if det_group!="none" and group!=det_group:raise ValueError("adaptive_group_conflict")
        if det_group=="none" and group!="none":raise ValueError("adaptive_group_not_grounded")
        out["group_by"]=group
        requested=rg.limit_of(prompt,0)
        out["limit"]=requested if requested>0 else max(1,min(50,int(plan.get("limit") or 10)))
        out["needs_entity_parse"]=False
        return out

    if intent=="compare_periods":
        lp=str(plan.get("left_period") or "").strip();rp=str(plan.get("right_period") or "").strip()
        if lp not in PERIODS or rp not in PERIODS or lp=="all" or rp=="all":
            raise ValueError("adaptive_compare_period_invalid")
        # v1 only allows the grounded current-vs-previous-month comparison.
        if not ("این ماه" in n and ("ماه قبل" in n or "ماه گذشته" in n)):
            raise ValueError("adaptive_compare_not_grounded")
        if (lp,rp)!=("current_jalali_month","previous_jalali_month"):
            raise ValueError("adaptive_compare_conflict")
        out["left_period"]=lp;out["right_period"]=rp
        return out

    raise ValueError("adaptive_plan_unhandled")

def planner_prompt()->str:
    return (
        "Return ONLY one JSON object describing a READ-ONLY ERP plan. Never answer the user. "
        "Never output database IDs, SQL, financial values, or invented dates. "
        "Allowed intents: company_snapshot,sales_total,purchase_total,totals,recent_sales,recent_purchases,recent_both,"
        "trial_balance,party_search,party_ledger,item_search,document_analytics,compare_periods. "
        "For document_analytics use kind sales|purchases; period all|current_jalali_month|previous_jalali_month|"
        "current_jalali_year|previous_jalali_year|rolling_jalali_months|custom|custom_jalali_month; "
        "status_scope all|confirmed|draft|approved|final; group_by none|party|item|jalali_month|status. "
        "Entity query strings and explicit dates MUST be copied verbatim from the user's text. "
        "For compare_periods v1 supports only current_jalali_month versus previous_jalali_month. "
        "If the request cannot safely map to one allowed read plan return {\"intent\":\"unsupported\"}."
    )

def llm_plan(worker,job,prompt:str)->tuple[dict[str,Any],dict[str,Any],str]:
    model=worker.model_for("agent")
    worker.trace(job,"adaptive_plan_llm",f"Planning unknown read request with {model}",{"model":model,"started_epoch":time.time()})
    response=worker.ollama_chat(
        job,0,[{"role":"system","content":planner_prompt()},{"role":"user","content":prompt}],[],
        fast=True,model=model,num_ctx=1280,num_predict=180,temperature=0.0,timeout_seconds=90
    )
    raw=str((response.get("message") or {}).get("content") or "").strip()
    a=raw.find("{");b=raw.rfind("}")
    if a<0 or b<a:raise ValueError("adaptive_json_missing")
    parsed=json.loads(raw[a:b+1])
    if str(parsed.get("intent") or "")=="unsupported":raise ValueError("adaptive_unsupported")
    return sanitize_plan(parsed,prompt),dict(response.get("_metrics") or {}),model

def cache_lookup(worker,job,key:str,contract:str)->dict[str,Any]|None:
    result=worker.tool(job,"semantic_route_lookup",{
        "route_key":key,"planner_version":PLANNER_VERSION,"contract_version":contract
    },f"job{job['id']}-route-lookup")
    if not isinstance(result,dict) or not result.get("hit"):return None
    plan=result.get("plan")
    if not isinstance(plan,dict):raise ValueError("adaptive_cached_plan_invalid")
    return {"plan":plan,"route_id":int(result.get("route_id") or 0)}

def remember(worker,job,key:str,contract:str,plan:dict[str,Any])->None:
    worker.tool(job,"semantic_route_remember",{
        "route_key":key,"planner_version":PLANNER_VERSION,"contract_version":contract,
        "plan":plan,"source":"llm_validated","confidence":0.90
    },f"job{job['id']}-route-remember")

def feedback(worker,job,key:str,contract:str,success:bool)->None:
    try:
        worker.tool(job,"semantic_route_feedback",{
            "route_key":key,"planner_version":PLANNER_VERSION,"contract_version":contract,
            "success":bool(success)
        },f"job{job['id']}-route-feedback")
    except Exception as e:
        print(f"[route feedback warning] {type(e).__name__}: {e}",flush=True)

def decorate(meta:dict[str,Any],mode:str,cache_state:str,model:str="none",metrics:dict[str,Any]|None=None)->dict[str,Any]:
    out=dict(meta or {})
    out.update({
        "provider":"adaptive_semantic_router",
        "mode":mode,
        "route_cache":cache_state,
        "planner_version":PLANNER_VERSION,
        "planner_model":model,
        "planner_metrics":metrics or {},
        "patch_version":PATCH_VERSION,
    })
    # UI historically reads meta.model. Preserve deterministic "none" but expose
    # the real adaptive planner model whenever one actually ran.
    if model and model!="none":
        out["model"]=model
    return out

def install_adaptive_router(cls:type)->None:
    if getattr(cls,"_adaptive_router_v1_installed",False):return
    base=cls.process_agent

    def patched(self,job,tools_desc):
        prompt=str(job.get("prompt") or "")
        if not safe_candidate(prompt) or built_in_handles(prompt):
            return base(self,job,tools_desc)

        key=route_key(prompt);contract=contract_version(job)
        self.trace(job,"adaptive_cache_lookup","Checking validated read-plan cache",{
            "route_key_prefix":key[:12],"planner_version":PLANNER_VERSION,"contract_version":contract
        })

        try:
            cached=cache_lookup(self,job,key,contract)
        except Exception as e:
            self.trace(job,"adaptive_cache_unavailable","Plan cache lookup unavailable; continuing with planner",{
                "reason":(type(e).__name__+": "+str(e))[:300]
            })
            cached=None

        if cached:
            try:
                plan=sanitize_plan(cached["plan"],prompt)
                self.trace(job,"adaptive_cache_hit",f"Using learned read plan: {plan['intent']}",{"intent":plan["intent"]})
                text,meta=rg.execute_one(self,job,plan,"cache","none",{})
                feedback(self,job,key,contract,True)
                return text,decorate(meta,"adaptive_cache_read","hit")
            except ValueError as e:
                feedback(self,job,key,contract,False)
                self.trace(job,"adaptive_cache_reject","Cached plan failed validation and was penalized",{
                    "reason":(type(e).__name__+": "+str(e))[:300]
                })
            except Exception as e:
                self.trace(job,"adaptive_cache_reject","Cached plan execution failed transiently; fresh planner will retry without penalizing cache",{
                    "reason":(type(e).__name__+": "+str(e))[:300]
                })

        self.trace(job,"adaptive_cache_miss","No reusable validated read plan found",{})
        try:
            plan,metrics,model=llm_plan(self,job,prompt)
            self.trace(job,"adaptive_plan_validated",f"Validated new read plan: {plan['intent']}",{"intent":plan["intent"]})
            text,meta=rg.execute_one(self,job,plan,"adaptive_llm",model,metrics)
            try:
                remember(self,job,key,contract,plan)
                self.trace(job,"adaptive_route_learned","Validated read plan stored for future reuse",{
                    "route_key_prefix":key[:12],"intent":plan["intent"]
                })
                cache_state="learned"
            except Exception as e:
                self.trace(job,"adaptive_learn_warning","Answer succeeded but plan cache write failed",{
                    "reason":(type(e).__name__+": "+str(e))[:300]
                })
                cache_state="miss_unstored"
            return text,decorate(meta,"adaptive_llm_read",cache_state,model,metrics)
        except Exception as e:
            self.trace(job,"adaptive_delegate","Adaptive planner could not safely resolve request; delegating to existing agent",{
                "reason":(type(e).__name__+": "+str(e))[:300]
            })
            return base(self,job,tools_desc)

    cls.process_agent=patched
    cls._adaptive_router_v1_installed=True
    cls._adaptive_router_v1_original_process_agent=base
