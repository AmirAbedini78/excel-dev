#!/usr/bin/env python3
"""ERPSMART v8.2C.4 safe deep-analysis patch.

Design rule:
- The accounting numbers remain in the deterministic ERP report.
- A complete qualitative deep core is deterministic and always available.
- Gemma is an optional, bounded enhancement only.
- Bad/truncated/slow model output can never make the core answer incomplete.
"""
from __future__ import annotations

import json
import sys
import threading
import time
from typing import Any

PATCH_VERSION = "v8.2C.4.2"


def _block(data: Any, key: str) -> dict[str, Any]:
    if not isinstance(data, dict):
        return {}
    value = data.get(key)
    return value if isinstance(value, dict) else {}


def build_qualitative_facts(bundle: Any, structured: dict[str, Any]) -> dict[str, str]:
    data = bundle if isinstance(bundle, dict) else {}
    sales = _block(data, "sales")
    trial = _block(data, "trial_balance")

    gap = structured.get("sales_minus_purchases")
    if isinstance(gap, (int, float)):
        if gap < 0:
            relation = "خرید ثبت‌شده بیشتر از فروش ثبت‌شده است"
        elif gap > 0:
            relation = "فروش ثبت‌شده بیشتر از خرید ثبت‌شده است"
        else:
            relation = "خرید و فروش ثبت‌شده برابرند"
    else:
        relation = "رابطه خرید و فروش از داده موجود مشخص نیست"

    trial_state = "متوازن" if bool(structured.get("trial_balanced")) else "دارای مغایرت"

    rows = sales.get("rows") if isinstance(sales.get("rows"), list) else []
    has_draft = any(
        isinstance(row, list)
        and len(row) > 4
        and str(row[4] or "").strip().lower() == "draft"
        for row in rows
    )

    top = trial.get("top_accounts") if isinstance(trial.get("top_accounts"), list) else []
    has_large_balances = any(isinstance(row, list) for row in top[:3])

    return {
        "رابطه_خرید_و_فروش": relation,
        "وضعیت_تراز": trial_state,
        "فروش_پیش_نویس": "وجود دارد" if has_draft else "مشاهده نشد",
        "مانده_های_بزرگ": "وجود دارد" if has_large_balances else "مشاهده نشد",
        "بهای_تمام_شده": "داده کافی موجود نیست",
        "جریان_نقد": "داده کافی موجود نیست",
        "روند_زمانی": "داده کافی موجود نیست",
    }


def build_safe_core(facts: dict[str, str]) -> str:
    relation = facts.get("رابطه_خرید_و_فروش") or "رابطه خرید و فروش نیازمند بررسی است"
    trial_state = facts.get("وضعیت_تراز") or "نامشخص"
    has_draft = facts.get("فروش_پیش_نویس") == "وجود دارد"
    has_large = facts.get("مانده_های_بزرگ") == "وجود دارد"

    risk_parts: list[str] = []
    if has_large:
        risk_parts.append("مانده‌های بزرگ حساب‌ها")
    if has_draft:
        risk_parts.append("وجود فروش پیش‌نویس")
    if risk_parts:
        risk_text = " و ".join(risk_parts) + " باید از نظر علت، قدمت و وضعیت تسویه یا نهایی‌شدن پیگیری شوند."
    else:
        risk_text = "از داده کیفی فعلی ریسک مشخص و قطعی قابل اثبات نیست و موارد غیرعادی باید در دوره‌های بعد پایش شوند."

    return "\n".join([
        f"برداشت: {relation} و وضعیت تراز آزمایشی {trial_state} است؛ این دو مشاهده به‌تنهایی نتیجه‌ای درباره سودآوری یا نقدینگی نمی‌دهند.",
        f"ریسک: {risk_text}",
        "محدودیت: داده کافی درباره بهای تمام‌شده، هزینه‌ها، جریان نقد و روند دوره‌ای موجود نیست؛ بنابراین نتیجه قطعی درباره عملکرد مالی ارائه نمی‌شود.",
        "اقدام: علت فاصله خرید و فروش، اسناد پیش‌نویس و مانده‌های بزرگ بررسی شوند و برای تحلیل بعدی داده‌های هزینه، جریان نقد و مقایسه دوره‌ای تکمیل شوند.",
    ])


def normalize_enhancement(text: Any) -> str:
    value = " ".join(str(text or "").replace("**", "").replace("__", "").split()).strip(" -*•\t")
    return value


def validate_enhancement(text: Any) -> tuple[bool, str, str]:
    value = normalize_enhancement(text)
    if not value:
        return False, "empty", ""
    if len(value) > 420:
        return False, "too_long", ""
    if any(ch.isdigit() for ch in value):
        return False, "numeric_output_forbidden", ""

    lowered = value.lower()
    forbidden = (
        "ریال",
        "تومان",
        "درصد",
        "سود",
        "زیان",
        "نقدینگی",
        "ورشکست",
        "کسری نقد",
        "purchases_higher",
        "sales_higher",
        "cogs",
        "cashflow",
        "trend_available",
        "sales_purchase_relation",
        "trial_balanced",
    )
    if any(term in lowered for term in forbidden):
        return False, "unsafe_financial_claim_or_internal_term", ""

    if not value.endswith((".", "!", "؟", "؛")):
        return False, "possibly_truncated", ""

    return True, "", value


def _trace_skip(worker: Any, job: dict[str, Any], model: str, reason: str, elapsed: float) -> None:
    try:
        worker.trace(job, "deep_fallback", "Optional model enhancement skipped; safe deep core retained", {
            "model": model,
            "reason": reason[:240],
            "elapsed_seconds": round(elapsed, 2),
            "safe_core_retained": True,
        })
    except Exception:
        pass


def process_deep_safe(worker: Any, job: dict[str, Any]) -> tuple[str, dict[str, Any]]:
    started = time.monotonic()
    prompt = str(job.get("prompt", ""))

    worker.trace(job, "route", "Read-only financial analysis -> deep hybrid path")
    worker.trace(job, "analysis_bundle_request", "Collecting compact financial data from cPanel")

    call_id = f"job{job['id']}-financial-analysis-bundle-safe-v4"
    bundle = worker.tool(job, "financial_analysis_bundle", {}, call_id)
    report_text, structured = worker.build_financial_report(bundle)

    data_chars = len(json.dumps(bundle, ensure_ascii=False, separators=(",", ":"), default=str))
    worker.trace(job, "analysis_bundle_ready", "Compact financial data is ready", {
        "data_chars": data_chars,
        "depth": "deep",
    })

    facts = build_qualitative_facts(bundle, structured)
    core_text = build_safe_core(facts)
    fact_json = json.dumps(facts, ensure_ascii=False, separators=(",", ":"))

    configured_model = str(worker.cfg.get("analysis_model") or "gemma3:4b")
    model = configured_model
    enhancement_used = False
    enhancement_reason = ""
    enhancement_text = ""
    metrics: dict[str, Any] = {}
    llm_started = time.monotonic()

    try:
        model = worker.model_for("analysis")
        worker.trace(job, "deep_analysis", f"Safe deep core prepared; optional local enhancement: {model}", {
            "qualitative_fact_chars": len(fact_json),
            "safe_core_chars": len(core_text),
            "source_data_chars": data_chars,
        })

        system = (
            "تو یک مشاور مدیریتی ERP هستی. فقط یک جمله کوتاه، کامل و فارسی برای اقدام مدیریتی تکمیلی بنویس. "
            "فقط از داده کیفی ورودی استفاده کن. هیچ عدد، مبلغ، درصد یا نتیجه‌ای درباره سود، زیان، نقدینگی یا سلامت مالی ننویس. "
            "نبود داده را ریسک واقعی تلقی نکن. فقط یک پیشنهاد درباره کنترل داخلی، پیگیری عملیات یا تکمیل داده بده. "
            "جمله باید کامل و با نقطه، علامت سؤال یا علامت تعجب تمام شود."
        )
        user = "داده_کیفی=" + fact_json
        messages = [{"role": "system", "content": system}, {"role": "user", "content": user}]

        worker.trace(job, "llm_request", f"Sending optional safe enhancement to {model}", {
            "round": 1,
            "messages": 2,
            "model": model,
            "optional": True,
            "started_epoch": time.time(),
        })

        response = worker.ollama_chat(
            job,
            0,
            messages,
            [],
            fast=False,
            model=model,
            num_ctx=int(worker.cfg.get("deep_safe_num_ctx", 1024)),
            num_predict=int(worker.cfg.get("deep_safe_num_predict", 48)),
            temperature=float(worker.cfg.get("deep_safe_temperature", 0.1)),
            timeout_seconds=int(worker.cfg.get("deep_safe_timeout_seconds", 100)),
        )
        metrics = dict(response.get("_metrics") or {})
        msg = response.get("message") or {}
        valid, reason, cleaned = validate_enhancement(msg.get("content"))
        if valid:
            enhancement_used = True
            enhancement_text = cleaned
        else:
            enhancement_reason = reason
            _trace_skip(worker, job, model, "guard:" + reason, time.monotonic() - llm_started)
    except Exception as exc:
        enhancement_reason = type(exc).__name__ + ": " + str(exc)
        _trace_skip(worker, job, model, enhancement_reason, time.monotonic() - llm_started)

    final_parts = [report_text, "", "تحلیل عمیق مدیریتی", core_text]
    if enhancement_used:
        final_parts += ["", "نکته تکمیلی مدل: " + enhancement_text]
    final = "\n".join(final_parts)

    with worker.progress_lock:
        trace_copy = list(worker.current_trace[-50:])

    total_elapsed = round(time.monotonic() - started, 2)
    provider = "hybrid_safe_core" if enhancement_used else "deterministic_safe_core"
    return final, {
        "provider": provider,
        "model": model if enhancement_used else "none",
        "attempted_model": model,
        "mode": "deep_financial_analysis",
        "tools_used": ["financial_analysis_bundle"],
        "rounds": 1 if enhancement_used else 0,
        "structured_report": structured,
        "qualitative_facts": facts,
        "llm_enhancement_used": enhancement_used,
        "llm_enhancement_reason": enhancement_reason[:500],
        "metrics": metrics or {"elapsed_seconds": total_elapsed},
        "trace": trace_copy,
        "patch_version": PATCH_VERSION,
    }


def install_worker_patch(worker_cls: type) -> None:
    if bool(getattr(worker_cls, "_deep_safe_v4_installed", False)):
        return

    original_process_fast_analysis = worker_cls.process_fast_analysis

    def patched_process_fast_analysis(self: Any, job: dict[str, Any]) -> tuple[str, dict[str, Any]]:
        prompt = str(job.get("prompt", ""))
        if self.analysis_depth(prompt) != "deep":
            return original_process_fast_analysis(self, job)
        return process_deep_safe(self, job)

    def patched_run_job(self: Any, job: dict[str, Any], tools: list[dict[str, Any]]) -> None:
        self.stop_event.clear()
        with self.progress_lock:
            self.current_progress = {}
            self.current_trace = []

        self.trace(job, "start", "Job leased from control plane", {
            "job_type": job.get("job_type"),
            "company_id": job.get("company_id"),
        })

        heartbeat = threading.Thread(target=self.heartbeat_loop, args=(job,), daemon=True)
        heartbeat.start()
        heartbeat_stopped = False

        def stop_heartbeat() -> bool:
            nonlocal heartbeat_stopped
            if heartbeat_stopped:
                return True
            self.stop_event.set()
            # A heartbeat API call can retry, so do not close the lease while
            # that thread may still be using it. The configured API path is
            # bounded by urllib timeouts/retries; 240s is a final safety cap.
            heartbeat.join(timeout=240)
            heartbeat_stopped = not heartbeat.is_alive()
            return heartbeat_stopped

        try:
            if job.get("job_type") != "agent_chat":
                raise RuntimeError(f"unsupported_job_type:{job.get('job_type')}")
            text, meta = self.process_agent(job, tools)

            if not stop_heartbeat():
                raise RuntimeError("heartbeat_thread_did_not_stop_before_complete")
            self.api.post("complete", {
                "node_uid": self.uid,
                "job_id": job["id"],
                "lease_token": job["lease_token"],
                "result_text": text,
                "result": meta,
            })
            self.trace(job, "completed", "Job completed successfully", {
                "tools_used": meta.get("tools_used", []),
            })
        except Exception as exc:
            stop_heartbeat()
            self.trace(job, "failed", f"Job failed: {exc}")
            print(f"[job {job['id']}] failed: {exc}", file=sys.stderr, flush=True)
            try:
                self.api.post("fail", {
                    "node_uid": self.uid,
                    "job_id": job["id"],
                    "lease_token": job["lease_token"],
                    "error": repr(exc),
                }, 30)
            except Exception as report_exc:
                print(
                    f"[job {job['id']}] could not report failure: {report_exc}",
                    flush=True,
                )
        finally:
            stop_heartbeat()

    worker_cls.process_fast_analysis = patched_process_fast_analysis
    worker_cls.run_job = patched_run_job
    worker_cls._deep_safe_v4_installed = True
    worker_cls._deep_safe_original_process_fast_analysis = original_process_fast_analysis
