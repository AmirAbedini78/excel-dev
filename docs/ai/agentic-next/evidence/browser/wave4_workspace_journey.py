#!/usr/bin/env python3
"""Wave4 Universal AI Workspace — synthetic browser contract journey (no production).

Validates static/UI contracts and optional local HTTP smoke when BASE_URL is set.
Does not invent agent progress or mark proposals executed.
"""
from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

# .../docs/ai/agentic-next/evidence/browser → repo root is parents[5]
ROOT = Path(__file__).resolve().parents[5]
EVIDENCE = Path(__file__).resolve().parent


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def check_static() -> list[str]:
    fails: list[str] = []
    checks = [
        ("assets/universal-ai-workspace.js", "ERPSMART_AI_WORKSPACE"),
        ("assets/universal-ai-workspace.js", "AWAITING_APPROVAL"),
        ("assets/universal-ai-workspace.js", "پیشنهاد — اجرا نشده"),
        ("assets/universal-ai-workspace.js", "do not invent task-machine steps"),
        ("assets/business-copilot.js", "applyJobUi"),
        ("app/Core/BusinessCopilot.php", "data-universal-ai-workspace"),
        ("app/Core/BusinessCopilotApi.php", "enrichWorkspaceJob"),
        ("docs/ai/agentic-next/09-UNIVERSAL-AI-UX.md", "No fictitious progress"),
    ]
    for path, needle in checks:
        text = read(path)
        if needle not in text:
            fails.append(f"missing {needle!r} in {path}")
    return fails


def optional_http_smoke(base: str) -> dict:
    """Hit login page only; never mutates production data."""
    url = base.rstrip("/") + "/index.php?page=login"
    req = urllib.request.Request(url, headers={"User-Agent": "wave4-workspace-journey/1.0"})
    try:
        with urllib.request.urlopen(req, timeout=8) as resp:
            body = resp.read(4000).decode("utf-8", errors="replace")
            return {
                "ok": resp.status == 200,
                "status": resp.status,
                "has_login_form": ("password" in body.lower() or "ورود" in body),
                "url": url,
            }
    except urllib.error.URLError as exc:
        return {"ok": False, "error": str(exc), "url": url}


def main() -> int:
    fails = check_static()
    report = {
        "suite": "wave4_universal_ai_workspace_browser",
        "synthetic": True,
        "production": False,
        "static_ok": not fails,
        "static_failures": fails,
        "proposal_rule": "proposed must never render as executed",
        "progress_rule": "no fictitious agent_task steps",
    }
    base = os.environ.get("WAVE4_BASE_URL", "").strip()
    if base:
        report["http_smoke"] = optional_http_smoke(base)
    else:
        report["http_smoke"] = {"skipped": True, "reason": "WAVE4_BASE_URL unset"}

    out = EVIDENCE / "wave4_workspace_journey.json"
    out.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0 if not fails else 1


if __name__ == "__main__":
    sys.exit(main())
