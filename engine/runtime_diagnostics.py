"""Print bounded capacity facts without worker/provider credentials or ERP data."""
from __future__ import annotations
import argparse
import json
import re
from pathlib import Path
import local_inference as L
import agent_intelligence_kernel as K


def report(config: dict) -> dict:
    runtime = L.LocalInference(config)
    names = runtime.models(refresh=True)
    endpoints = []
    for ep in runtime.endpoints:
        row = {"endpoint_id": ep["id"], "declared_memory_budget_mb": ep.get("memory_budget_mb"),
               "model_count": len(runtime.inventory.get(ep["id"], []))}
        try:
            version = str(L._json_get(ep["url"], "/api/version").get("version") or "")
            row["ollama_version"] = version if re.fullmatch(r"[A-Za-z0-9.+_-]{1,80}", version) else "unreported"
        except Exception as exc:
            row["version_error_type"] = type(exc).__name__
        try:
            loaded = L._json_get(ep["url"], "/api/ps").get("models") or []
            row["loaded_models"] = [{"name": str(x.get("name") or "")[:120],
                "size_bytes": max(0, int(x.get("size") or 0)),
                "vram_bytes": max(0, int(x.get("size_vram") or 0)),
                "context_length": max(0, int(x.get("context_length") or 0))}
                for x in loaded[:8] if isinstance(x, dict)]
        except Exception as exc:
            row["loaded_models_error_type"] = type(exc).__name__
        endpoints.append(row)
    return {
        "kernel_version": K.PATCH_VERSION, "analyst_contract": K.ANALYST_CONTRACT,
        "runtime_version": L.VERSION, "host_available_mb": L._host_available_mb(),
        "memory_estimate_is_not_a_guarantee": True,
        "models": [{"name": name, "smallest_artifact_bytes": runtime.model_size(name),
                    "cpu_estimate_at_2048_mb": round(L.admission_required_mb(runtime.model_size(name), 2048), 1)}
                   for name in names],
        "endpoints": endpoints,
        "runtime_fingerprint": runtime.fingerprint(),
        "business_live_validated": False,
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--config", default="/app/config.json")
    args = ap.parse_args()
    config = json.loads(Path(args.config).read_text(encoding="utf-8-sig"))
    print(json.dumps(report(config), ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
