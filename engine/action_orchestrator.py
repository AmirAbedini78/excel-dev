#!/usr/bin/env python3
"""ERPSMART v8.9.0 Accounting Action Orchestrator.

First safe vertical slice:
- Read a named customer and its real ledger.
- Evaluate a deterministic debtor condition.
- Resolve debit/credit accounts only from the real trial balance.
- Build a balanced receipt-voucher proposal.
- Stop at human approval. No voucher is created by the worker itself.

The LLM is restricted to selecting a server-grounded action goal ID.
It never creates ERP IDs, account IDs, party IDs, money, tool names,
voucher lines, dates, or approval decisions.
"""
from __future__ import annotations

import hashlib
import json
import re
import time
from typing import Any

PATCH_VERSION = "v8.9.0"
PLANNER_VERSION = "accounting-action-v1"
ACTION_GOAL = "prepare_receipt_voucher_if_debtor"

_DIGIT_TRANS = str.maketrans(
    "۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩",
    "01234567890123456789",
)

class ActionPlanError(ValueError):
    pass

class ActionBlocked(RuntimeError):
    """Safe user-displayable block with no proposal side effect."""


def norm(value: Any) -> str:
    text=str(value or "").translate(_DIGIT_TRANS)
    text=text.replace("ي","ی").replace("ك","ک").replace("\u200c"," ")
    text=re.sub(r"\s+"," ",text).strip().lower()
    return text


def is_action_candidate(prompt: str) -> bool:
    p=norm(prompt)
    if not p:
        return False
    # v8.9.0 intentionally does not intercept the existing invoice guard.
    if "فاکتور" in p or "invoice" in p:
        return False
    has_receipt=("دریافت" in p or "وصول" in p or "receipt" in p)
    has_condition=("اگر بدهکار" in p or "در صورت بدهکار" in p)
    has_proposal=any(x in p for x in ("پیشنهاد","پیش نویس","پیش‌نویس","آماده کن","prepare"))
    has_ledger=("مانده" in p or "گردش" in p)
    return has_receipt and has_condition and has_proposal and has_ledger


def _quoted_after(prompt: str, labels: tuple[str,...]) -> str:
    text=str(prompt or "").translate(_DIGIT_TRANS)
    for label in labels:
        # Persian guillemets, English quotes, or single quotes.
        m=re.search(
            re.escape(label)+r"\s*[«\"']\s*([^»\"'\r\n]+?)\s*[»\"']",
            text,
            flags=re.IGNORECASE,
        )
        if m:
            return m.group(1).strip()
    return ""


def _amount_rial(prompt: str) -> int:
    text=str(prompt or "").translate(_DIGIT_TRANS)
    # Prefer a number immediately associated with ریال.
    matches=re.findall(r"(?<!\d)(\d[\d,٬ ]{0,30}\d|\d)\s*ریال",text)
    if len(matches)!=1:
        raise ActionPlanError("action_amount_must_be_one_explicit_rial_value")
    raw=matches[0].replace(",","").replace("٬","").replace(" ","")
    amount=int(raw)
    if amount<=0:
        raise ActionPlanError("action_amount_must_be_positive")
    return amount


def parse_grounded_spec(prompt: str) -> dict[str,Any]:
    p=norm(prompt)
    if any(x in p for x in ("party_id","account_id","voucher_id","sql")):
        raise ActionPlanError("action_direct_identifier_forbidden")

    party=_quoted_after(prompt,("مشتری","طرف حساب","طرف‌حساب"))
    debit=_quoted_after(prompt,("حساب بدهکار","بدهکار"))
    credit=_quoted_after(prompt,("حساب بستانکار","بستانکار"))
    if not party:
        raise ActionPlanError("action_customer_phrase_required")
    if not debit:
        raise ActionPlanError("action_debit_account_phrase_required")
    if not credit:
        raise ActionPlanError("action_credit_account_phrase_required")
    if norm(debit)==norm(credit):
        raise ActionPlanError("action_accounts_must_be_distinct")

    amount=_amount_rial(prompt)
    return {
        "party_query":party,
        "debit_account_query":debit,
        "credit_account_query":credit,
        "amount_rial":amount,
    }


def goal_schema() -> dict[str,Any]:
    return {
        "type":"object",
        "properties":{
            "goal":{"type":"string","enum":[ACTION_GOAL]}
        },
        "required":["goal"],
        "additionalProperties":False,
    }


def goal_prompt() -> str:
    return (
        "Select the single grounded accounting action goal required by the user. "
        "Return only JSON matching the supplied schema. "
        "Do not output tools, IDs, account IDs, party IDs, dates, amounts, voucher lines, "
        "conditions, SQL, or explanations. The server/worker owns all financial arguments. "
        f"AVAILABLE_GOAL={ACTION_GOAL}"
    )


def parse_goal(raw: Any) -> str:
    text=str(raw or "").strip()
    try:
        obj=json.loads(text)
    except Exception as e:
        raise ActionPlanError("action_goal_json_invalid") from e
    if not isinstance(obj,dict) or set(obj)!={"goal"}:
        raise ActionPlanError("action_goal_shape_invalid")
    goal=str(obj.get("goal") or "")
    if goal!=ACTION_GOAL:
        raise ActionPlanError("action_goal_not_allowed")
    return goal


def _rows(result: Any) -> list[dict[str,Any]]:
    if isinstance(result,list):
        return [x for x in result if isinstance(x,dict)]
    if isinstance(result,dict):
        for key in ("rows","items","results","data"):
            value=result.get(key)
            if isinstance(value,list):
                return [x for x in value if isinstance(x,dict)]
    return []


def _resolve_unique(rows: list[dict[str,Any]], query: str, fields: tuple[str,...]) -> tuple[dict[str,Any]|None,str,list[dict[str,Any]]]:
    q=norm(query)
    exact=[]
    contains=[]
    for row in rows:
        values=[norm(row.get(f)) for f in fields if row.get(f) not in (None,"")]
        if any(v==q for v in values):
            exact.append(row); continue
        name=norm(row.get("name"))
        code=norm(row.get("code"))
        if (name and (q in name or name in q)) or (code and q==code):
            contains.append(row)

    candidates=exact or contains
    unique={}
    for row in candidates:
        try:
            rid=int(row.get("id"))
        except Exception:
            continue
        if rid>0:
            unique[rid]=row
    vals=list(unique.values())
    if len(vals)==1:
        return vals[0],"",vals
    if not vals:
        return None,"not_found",[]
    return None,"ambiguous",vals


def _choices(rows: list[dict[str,Any]], limit: int=6) -> str:
    out=[]
    for row in rows[:limit]:
        code=str(row.get("code") or "").strip()
        name=str(row.get("name") or "").strip()
        label=(" ".join(x for x in (code,name) if x)).strip()
        if label and label not in out:
            out.append(label)
    return "، ".join(out)


def _stable_call_id(job_id: int, spec: dict[str,Any], party_id: int, debit_id: int, credit_id: int) -> str:
    payload={
        "v":PATCH_VERSION,
        "job_id":int(job_id),
        "goal":ACTION_GOAL,
        "party_id":int(party_id),
        "debit_account_id":int(debit_id),
        "credit_account_id":int(credit_id),
        "amount_rial":int(spec["amount_rial"]),
    }
    raw=json.dumps(payload,sort_keys=True,separators=(",",":"))
    return "action-v89-"+hashlib.sha256(raw.encode()).hexdigest()[:40]


def _fmt_rial(value: Any) -> str:
    return f"{int(round(float(value or 0))):,} ریال"


def execute_receipt_action(worker: Any, job: dict[str,Any], prompt: str, tools_desc: list[dict[str,Any]]) -> tuple[str,dict[str,Any]]:
    required={"search_parties","party_ledger","trial_balance","create_voucher_draft"}
    available={str(x.get("name") or "") for x in tools_desc if isinstance(x,dict)}
    missing=sorted(required-available)
    if missing:
        raise ActionBlocked("ابزار مالی لازم برای این اقدام در Control Plane موجود نیست: "+", ".join(missing))

    spec=parse_grounded_spec(prompt)
    model=worker.model_for("agent")
    worker.trace(job,"action_candidate","Conditional accounting receipt action selected",{
        "planner_version":PLANNER_VERSION,
        "amount_rial":spec["amount_rial"],
    })

    worker.trace(job,"action_plan_llm",f"Selecting grounded accounting action goal with {model}",{
        "model":model,
        "started_epoch":time.time(),
        "allowed_goals":[ACTION_GOAL],
    })
    response=worker.ollama_chat(
        job,0,
        [{"role":"system","content":goal_prompt()},{"role":"user","content":prompt}],
        [],
        fast=True,
        model=model,
        num_ctx=768,
        num_predict=48,
        temperature=0.0,
        timeout_seconds=90,
        response_format=goal_schema(),
        think_override=False,
    )
    metrics=dict(response.get("_metrics") or {})
    goal=parse_goal((response.get("message") or {}).get("content"))
    worker.trace(job,"action_plan_validated","Validated grounded accounting action goal",{"goal":goal})

    worker.trace(job,"action_read","Resolving customer through server search",{"query":spec["party_query"]})
    parties=worker.tool(job,"search_parties",{"query":spec["party_query"]},"action-party-"+hashlib.sha256(norm(spec["party_query"]).encode()).hexdigest()[:16])
    party,reason,candidates=_resolve_unique(_rows(parties),spec["party_query"],("name","code","national_id","mobile"))
    if not party:
        if reason=="ambiguous":
            raise ActionBlocked("مشتری مبهم است؛ نام/کد دقیق‌تر بده. گزینه‌ها: "+_choices(candidates))
        raise ActionBlocked("مشتری خواسته‌شده در شرکت فعلی پیدا نشد.")
    party_id=int(party["id"])
    party_name=str(party.get("name") or spec["party_query"])

    worker.trace(job,"action_read","Reading real customer ledger",{"party_name":party_name})
    ledger=worker.tool(job,"party_ledger",{"party_id":party_id},"action-ledger-"+str(party_id))
    if not isinstance(ledger,dict):
        raise ActionBlocked("گردش حساب مشتری پاسخ معتبر برنگرداند.")
    balance=float(ledger.get("balance") or 0)
    worker.trace(job,"action_condition","Evaluated debtor condition from real ledger",{
        "party_name":party_name,
        "balance_rial":balance,
        "condition":"balance > 0",
        "matched":balance>0,
    })
    if balance<=0:
        text=(
            f"مانده واقعی {party_name}: {_fmt_rial(balance)}. "
            "شرط «اگر بدهکار است» برقرار نیست؛ بنابراین هیچ Proposal مالی ساخته نشد."
        )
        return text,{
            "provider":"deterministic_condition",
            "model":model,
            "mode":"accounting_action_noop",
            "action_goal":goal,
            "tools_used":["search_parties","party_ledger"],
            "condition":{"type":"party_debtor","balance_rial":balance,"matched":False},
            "metrics":metrics,
        }

    amount=int(spec["amount_rial"])
    if amount>balance+0.01:
        raise ActionBlocked(
            f"مبلغ پیشنهادی {_fmt_rial(amount)} از مانده بدهکار واقعی {_fmt_rial(balance)} بیشتر است؛ "
            "در v8.9.0 این اقدام برای جلوگیری از بیش‌وصول Block می‌شود."
        )

    worker.trace(job,"action_read","Resolving voucher accounts from real trial balance",{
        "debit_query":spec["debit_account_query"],
        "credit_query":spec["credit_account_query"],
    })
    trial=worker.tool(job,"trial_balance",{},"action-trial-balance")
    rows=_rows(trial)

    debit,d_reason,d_candidates=_resolve_unique(rows,spec["debit_account_query"],("code","name"))
    if not debit:
        if d_reason=="ambiguous":
            raise ActionBlocked(
                "حساب بدهکار مبهم است. یکی از کد/نام‌های دقیق زیر را در درخواست بنویس: "+_choices(d_candidates)
            )
        raise ActionBlocked("حساب بدهکار خواسته‌شده در تراز شرکت پیدا نشد.")

    credit,c_reason,c_candidates=_resolve_unique(rows,spec["credit_account_query"],("code","name"))
    if not credit:
        if c_reason=="ambiguous":
            raise ActionBlocked(
                "حساب بستانکار مبهم است. یکی از کد/نام‌های دقیق زیر را در درخواست بنویس: "+_choices(c_candidates)
            )
        raise ActionBlocked("حساب بستانکار خواسته‌شده در تراز شرکت پیدا نشد.")

    debit_id=int(debit["id"]); credit_id=int(credit["id"])
    if debit_id==credit_id:
        raise ActionBlocked("حساب بدهکار و بستانکار به یک حساب واقعی Resolve شدند؛ Proposal ساخته نشد.")

    debit_label=(" ".join(x for x in (str(debit.get("code") or ""),str(debit.get("name") or "")) if x)).strip()
    credit_label=(" ".join(x for x in (str(credit.get("code") or ""),str(credit.get("name") or "")) if x)).strip()

    args={
        "description":f"پیشنهاد ثبت دریافت از {party_name} — ایجادشده توسط AI و نیازمند تایید انسانی",
        "lines":[
            {
                "account_id":debit_id,
                "description":f"دریافت از {party_name}",
                "debit":amount,
                "credit":0,
            },
            {
                "account_id":credit_id,
                "party_id":party_id,
                "description":f"تسویه بخشی از مانده {party_name}",
                "debit":0,
                "credit":amount,
            },
        ],
    }
    call_id=_stable_call_id(int(job["id"]),spec,party_id,debit_id,credit_id)
    worker.trace(job,"action_proposal","Creating approval-only balanced voucher proposal",{
        "party_name":party_name,
        "amount_rial":amount,
        "debit_account":debit_label,
        "credit_account":credit_label,
        "idempotency_key_prefix":call_id[:18],
    })
    result=worker.tool(job,"create_voucher_draft",args,call_id)
    if not isinstance(result,dict) or int(result.get("proposal_id") or 0)<=0:
        raise ActionBlocked("Control Plane شناسه Proposal معتبر برنگرداند؛ هیچ اجرا تایید نشده است.")
    if str(result.get("status") or "")!="awaiting_human_approval":
        raise ActionBlocked("وضعیت Proposal غیرمنتظره است؛ اجرای خودکار مجاز نیست.")

    proposal_id=int(result["proposal_id"])
    worker.trace(job,"action_complete","Accounting action proposal is waiting for human approval",{
        "proposal_id":proposal_id,
        "status":"awaiting_human_approval",
    })
    text=(
        f"مانده واقعی {party_name}: {_fmt_rial(balance)} و شرط بدهکار بودن برقرار است.\n"
        f"Proposal #{proposal_id} برای ثبت دریافت {_fmt_rial(amount)} آماده شد:\n"
        f"• بدهکار: {debit_label}\n"
        f"• بستانکار: {credit_label} — طرف حساب: {party_name}\n"
        "هیچ سندی هنوز ایجاد نشده است. این Proposal فقط پس از تایید انسانی در پنل، "
        "به یک سند حسابداری draft تبدیل می‌شود."
    )
    return text,{
        "provider":"ollama+deterministic",
        "model":model,
        "mode":"accounting_action_proposal",
        "action_goal":goal,
        "proposal_id":proposal_id,
        "proposal_status":"awaiting_human_approval",
        "tools_used":["search_parties","party_ledger","trial_balance","create_voucher_draft"],
        "condition":{"type":"party_debtor","balance_rial":balance,"matched":True},
        "grounded":{
            "party_name":party_name,
            "amount_rial":amount,
            "debit_account":debit_label,
            "credit_account":credit_label,
        },
        "metrics":metrics,
    }


def install_action_orchestrator(Worker: Any) -> None:
    if getattr(Worker,"_action_orchestrator_v1_installed",False):
        return
    old=Worker.process_agent

    def process_agent(self: Any, job: dict[str,Any], tools_desc: list[dict[str,Any]]):
        prompt=str(job.get("prompt") or "")
        if not is_action_candidate(prompt):
            return old(self,job,tools_desc)
        try:
            return execute_receipt_action(self,job,prompt,tools_desc)
        except ActionBlocked as e:
            self.trace(job,"action_blocked",str(e))
            return str(e),{
                "provider":"deterministic_block",
                "model":"none",
                "mode":"accounting_action_blocked",
                "tools_used":[],
                "blocked_reason":str(e),
            }
        except ActionPlanError as e:
            self.trace(job,"action_rejected","Action request failed constrained grounding",{"reason":str(e)})
            return (
                "برای این اقدام باید مشتری، مبلغ ریالی، حساب بدهکار و حساب بستانکار را صریح و داخل گیومه مشخص کنی. "
                "هیچ Proposal ساخته نشد."
            ),{
                "provider":"deterministic_rejection",
                "model":"none",
                "mode":"accounting_action_rejected",
                "tools_used":[],
                "rejection_reason":str(e),
            }

    Worker.process_agent=process_agent
    Worker._action_orchestrator_v1_installed=True
