#!/usr/bin/env python3
"""Production-equivalent small-model selection eval; no ERP calls or mutations.

Qualification requires three different evidence patterns, including a five-supplier
input. A valid JSON shape alone never qualifies a model.
"""
from __future__ import annotations
import argparse
import json
import os
import tempfile
import time
from pathlib import Path
from worker import Worker
import agent_intelligence_kernel as K
import decision_brief as DB
from local_inference import token_upper_bound, failure_details


def qualification_cases():
    rows = []
    for i in range(5):
        rows.append({"party_id": i + 1, "name": "supplier-" + str(i), "purchase_document_count": 2,
                     "purchase_net_total": 1000 * (5 - i), "receipt_fill_rate": 0,
                     "high_risk_cases": 1 if i == 3 else 0, "medium_risk_cases": 0,
                     "trade_case_count": 1, "arrival_sample": 0})
    return [
        ({"capability": "supplier.performance", "supplier_rows": rows},
         "تأمین‌کننده‌ها را مقایسه کن؛ کدام پرونده به پیگیری فوری نیاز دارد؟", "F3"),
        ({"capability": "trade.risk", "trade_rows": [
            {"trade_case_id": 1, "case_no": "T-A", "risk_level": "low", "reasons": []},
            {"trade_case_id": 2, "case_no": "T-B", "risk_level": "high", "reasons": ["customs_hold"]}]},
         "مهم‌ترین ریسک فعلی بازرگانی را اولویت‌بندی کن.", "F1"),
        ({"capability": "shipment.impact", "shipment_result": {"summary": {"affected_sales_count": 2, "items_with_trade_case_dependency_signal": 1},
          "item_exposure": [{"item_name": "item-A", "potential_dependency_on_this_trade_case": True,
                             "uncovered_after_current_available": 4},
                            {"item_name": "item-B", "potential_dependency_on_this_trade_case": False,
                             "uncovered_after_current_available": 0}]}},
         "کدام کالا در تأخیر محموله نیازمند بررسی فوری پوشش تعهدهاست؟", "F0"),
    ]


def reason_code(exc: Exception) -> str:
    validation_codes = {"selection_shape", "selection_unknown_or_duplicate", "selection_action",
                        "qualification_semantic_priority", "qualification_actual_model_mismatch",
                        "qualification_budget_exhausted", "no_recoverable_json_object"}
    return str(exc) if str(exc) in validation_codes else str(getattr(exc, "code", type(exc).__name__))


def qualify(worker, model: str, deadline: float, evidence: list | None = None) -> list[dict]:
    results = []
    for case_no, (state, prompt, expected) in enumerate(qualification_cases()):
        remaining = deadline - time.monotonic()
        if remaining < 1:
            raise RuntimeError("qualification_budget_exhausted")
        brief = DB.build(state)
        messages = DB.messages(prompt, brief)
        # The first request may load weights; subsequent cases also require a
        # faster response. This is not proof that the first request was cold.
        limit = min(K.DECISION_CALL_SECONDS if case_no == 0 else 30, remaining)
        record = {"capability": state["capability"], "model": model, "case_number": case_no + 1,
                  "request_kind": "first_request" if case_no == 0 else "subsequent_request",
                  "request_limit_seconds": limit, "prompt_token_upper_bound": token_upper_bound(messages)}
        print(f"AGENT_KERNEL_PREFLIGHT request model={model} case={case_no + 1} limit={limit:.1f}s", flush=True)
        started = time.monotonic()
        metrics = {}
        try:
            response = worker.ollama_chat({"id": 0, "company_id": 0}, 0, messages, [], model=model,
                                         num_ctx=2048, num_predict=128, temperature=0.0,
                                         timeout_seconds=limit, response_format=DB.schema(brief),
                                         think_override=False)
            metrics = response.get("_metrics") or {}
            selection = DB.validate(K._safe_json_object(str((response.get("message") or {}).get("content") or "")), brief)
            if selection["finding_ids"][0] != expected:
                raise ValueError("qualification_semantic_priority")
            actual = str(metrics.get("model") or model)
            if actual != model:
                raise ValueError("qualification_actual_model_mismatch")
            record["status"] = "PASS"
        except Exception as exc:
            code = reason_code(exc)
            record.update(status="SKIPPED" if code == "endpoint_cooling" else "FAIL",
                          reason=code, diagnostics=failure_details(exc))
            raise
        finally:
            record["elapsed_seconds"] = round(time.monotonic() - started, 3)
            for key in ("load_duration", "prompt_eval_count", "prompt_eval_duration", "eval_count",
                        "eval_duration", "endpoint_id", "first_chunk_seconds", "first_content_seconds",
                        "thinking_chars", "content_chars", "done_reason"):
                if key in metrics:
                    record[key] = metrics[key]
            record["duration_unit"] = "nanoseconds_for_ollama_duration_fields"
            results.append(record)
            if evidence is not None:
                evidence.append(record)
    return results


def persist_route(path: Path, worker, configured: list[str], selected: str, results: list[dict]):
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "contract": "erpsmart.agent-kernel.model-route.v2", "kernel_version": K.PATCH_VERSION,
        "analyst_contract": K.ANALYST_CONTRACT, "planner_mode": "auto",
        "analyst_model": selected, "mode": "model" if selected else "deterministic_decision_fallback",
        "configured_decision_models": configured, "runtime_fingerprint": worker.local_inference.fingerprint(),
        "verified_epoch": time.time(), "expires_epoch": time.time() + (86400 if selected else 300),
        "evaluation": results, "business_live_validated": False,
    }
    handle, temp = tempfile.mkstemp(prefix=".route-", dir=path.parent)
    try:
        with os.fdopen(handle, "w", encoding="utf-8") as f:
            json.dump(payload, f, ensure_ascii=False, indent=2)
        os.replace(temp, path)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)
    return payload


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--config", default="/app/config.json")
    ap.add_argument("--route-file", default="/app/data/agent_kernel_model_route.json")
    ap.add_argument("--budget-seconds", type=float, default=240)
    ap.add_argument("--require-model", action="store_true")
    args = ap.parse_args()
    cfg = json.loads(Path(args.config).read_text(encoding="utf-8-sig"))
    worker = Worker(cfg)
    configured = K._configured_decision_models(worker)
    deadline = time.monotonic() + max(5, min(300, args.budget_seconds))
    selected, results = "", []
    for model in configured[:3]:
        if time.monotonic() >= deadline:
            break
        # Administrative qualification can wait before the NEXT candidate.
        # Interactive jobs never sleep through this cooldown. Wait only if the
        # same total budget still leaves time for a meaningful request.
        wait = worker.local_inference.ready_in_seconds()
        if wait > 0 and deadline - time.monotonic() > wait + 1:
            print(f"AGENT_KERNEL_PREFLIGHT capacity cooldown before model={model} wait={wait:.2f}s", flush=True)
            while worker.local_inference.ready_in_seconds() > 0 and time.monotonic() < deadline - 1:
                time.sleep(min(1, worker.local_inference.ready_in_seconds(), max(0, deadline - time.monotonic() - 1)))
        before = len(results)
        try:
            cases = qualify(worker, model, deadline, evidence=results)
            selected = model
            print(f"AGENT_KERNEL_PREFLIGHT analyst PASS model={model} cases={len(cases)}")
            break
        except Exception as exc:
            code = reason_code(exc)
            if len(results) == before or results[-1].get("status") == "PASS":
                results.append({"model": model, "status": "FAIL", "reason": code,
                                "diagnostics": failure_details(exc)})
            print(f"AGENT_KERNEL_PREFLIGHT candidate {results[-1]['status']} model={model} reason={code}")
            print(json.dumps(results[-1], ensure_ascii=False), flush=True)
    route = persist_route(Path(args.route_file), worker, configured, selected, results)
    print(json.dumps({"kernel_version": K.PATCH_VERSION, "mode": route["mode"],
                      "analyst_model": selected, "planner_mode": "auto",
                      "business_live_validated": False}, ensure_ascii=False))
    if not selected:
        print("AGENT_KERNEL_PREFLIGHT DEGRADED: no model qualified; grounded ERP reads remain available.")
        if args.require_model:
            raise SystemExit(2)
    else:
        print("AGENT_KERNEL_PREFLIGHT PASS: bounded selection capability; ERP live acceptance still required.")


if __name__ == "__main__":
    main()
