#!/usr/bin/env python3
"""Runtime preflight for Cycle 12.2 structured analysis-model output.

The check mirrors the actual Worker transport instead of using a one-off raw HTTP
request. It first tries the configured analysis model with the same 150s runtime
budget; if that model is too slow/unavailable it verifies the configured fallback
model. The first model that produces valid structured output is persisted only as
runtime routing metadata under /app/data so subsequent Business Supervisor calls do
not repeatedly wait on a model that already failed the live preflight.
"""
from __future__ import annotations

import argparse
import json
import time
from pathlib import Path

from worker import Worker


def _job() -> dict:
    return {"id": 0, "company_id": 0, "prompt": "cycle12 analysis preflight", "context": {}}


def _schema() -> dict:
    return {
        "type": "object",
        "properties": {"status": {"type": "string", "enum": ["ok"]}},
        "required": ["status"],
        "additionalProperties": False,
    }


def _attempt(worker: Worker, model: str, timeout_seconds: int) -> tuple[bool, str, float]:
    started=time.monotonic()
    try:
        response=worker.ollama_chat(
            _job(),
            0,
            [
                {"role":"system","content":"Return only JSON matching the supplied schema. status must be ok."},
                {"role":"user","content":"health check"},
            ],
            [],
            fast=False,
            model=model,
            num_ctx=512,
            num_predict=32,
            temperature=0.0,
            timeout_seconds=timeout_seconds,
            response_format=_schema(),
            think_override=False,
        )
        content=str((response.get("message") or {}).get("content") or "").strip()
        data=json.loads(content)
        if data != {"status":"ok"}:
            return False, "invalid_structured_output", time.monotonic()-started
        return True, "ok", time.monotonic()-started
    except Exception as exc:
        return False, type(exc).__name__, time.monotonic()-started


def main() -> None:
    ap=argparse.ArgumentParser()
    ap.add_argument("--config",default="/app/config.json")
    ap.add_argument("--route-file",default="/app/data/cycle12_analysis_route.json")
    args=ap.parse_args()

    cfg=json.loads(Path(args.config).read_text(encoding="utf-8"))
    worker=Worker(cfg)

    candidates=[]
    for role,timeout in (("analysis",150),("fallback",120)):
        try:
            model=str(worker.model_for(role) or "").strip()
        except Exception:
            model=""
        if model and model not in [m for _,m,_ in candidates]:
            candidates.append((role,model,timeout))

    if not candidates:
        raise SystemExit("ANALYSIS_MODEL_PREFLIGHT FAIL no analysis/fallback model available")

    failures=[]
    for role,model,timeout in candidates:
        ok,reason,elapsed=_attempt(worker,model,timeout)
        print(f"ANALYSIS_MODEL_PREFLIGHT attempt role={role} model={model} elapsed={elapsed:.1f}s result={'PASS' if ok else 'FAIL:'+reason}")
        if not ok:
            failures.append({"role":role,"model":model,"reason":reason,"elapsed_seconds":round(elapsed,2)})
            continue

        route=Path(args.route_file)
        route.parent.mkdir(parents=True,exist_ok=True)
        route.write_text(json.dumps({
            "version":"cycle12-analysis-route-v1",
            "selected_role":role,
            "selected_model":model,
            "elapsed_seconds":round(elapsed,2),
            "failed_candidates":failures,
        },ensure_ascii=False,indent=2),encoding="utf-8")
        print(f"ANALYSIS_MODEL_PREFLIGHT PASS model={model} role={role} route={route}")
        return

    raise SystemExit("ANALYSIS_MODEL_PREFLIGHT FAIL no configured model passed structured-output runtime check: "+json.dumps(failures,ensure_ascii=False))


if __name__=='__main__':
    main()
