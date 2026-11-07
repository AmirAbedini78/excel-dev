#!/usr/bin/env python3
"""Conversational delivery tier for Business Copilot (MVP).

When the user selects a capable cloud engine (Grok / Gemini / Cursor), synthesis
uses warmer Persian and paragraph-style narration while numeric grounding rules stay
unchanged. When only weak/local models are available—or cloud synthesis fails—the
worker keeps deterministic ERP evidence and wraps it in user-friendly templates.
"""
from __future__ import annotations

import re
from typing import Any

PATCH_VERSION = "v10.11-conversational-mvp-r1"

PREMIUM_PROVIDERS = frozenset({"grok", "gemini", "cursor"})
WEAK_MODEL_MARKERS = ("0.8b", "1.7b", ":0.8", ":1.7", "tiny", "lite", "qwen3.5", "qwen3:1")


def engine_selection(job: dict[str, Any]) -> tuple[str, str]:
    ctx = job.get("context") if isinstance(job.get("context"), dict) else {}
    sel = ctx.get("engine_selection") if isinstance(ctx.get("engine_selection"), dict) else {}
    return str(sel.get("provider") or "").strip().lower(), str(sel.get("model") or "").strip()


def delivery_tier(job: dict[str, Any], worker: Any) -> str:
    """Return premium | standard | deterministic_only."""
    provider, model = engine_selection(job)
    if provider in PREMIUM_PROVIDERS:
        return "premium"
    if provider != "ollama":
        return "standard"
    model_l = model.lower()
    if model and any(m in model_l for m in WEAK_MODEL_MARKERS):
        return "deterministic_only"
    return "standard"


class _NullWorker:
    _last_model_provider = ""


def synthesis_model(worker: Any, job: dict[str, Any]) -> str:
    provider, selected = engine_selection(job)
    if provider in PREMIUM_PROVIDERS and selected:
        return selected
    return worker.model_for("analysis")


def grounded_system_prompt(tier: str, skill_id: str) -> str:
    guard = (
        "You are ERPSMART's grounded business copilot. Answer only from the ERP evidence supplied. "
        "Do not calculate, invent, transform, or infer numeric values, dates, percentages, currency amounts, "
        "document numbers, business IDs, or codes. You may quote a value only when it is already present in the evidence. "
        "Your job is qualitative interpretation: what matters, why it matters, what to inspect next, and what cannot "
        "be concluded from the available evidence."
    )
    if tier == "premium":
        style = (
            " Answer in fluent, natural Persian suited for a business manager. "
            "Match the tone and intent of USER_REQUEST. Use complete sentences; the summary may be one short paragraph. "
            "Findings and actions should sound human and practical, not robotic. "
            "Never claim evidence that is absent."
        )
    else:
        style = " Answer in concise Persian. Never claim evidence that is absent."
    return guard + style + f" Skill context: {skill_id}."


def synthesis_temperature(tier: str) -> float:
    return 0.38 if tier == "premium" else 0.12


def synthesis_num_predict(tier: str) -> int:
    return 480 if tier == "premium" else 360


def render_grounded_hybrid(
    evidence_text: str,
    summary: str,
    findings: list[str],
    actions: list[str],
    limitations: list[str],
    tier: str,
) -> str:
    evidence = str(evidence_text or "").strip()
    if tier == "premium":
        parts: list[str] = [
            "بر اساس دادهٔ تازه ERP برای این شرکت:",
            "",
            evidence,
            "",
        ]
        if summary:
            parts.append(summary.strip())
        if findings:
            parts.append("")
            parts.append("نکات مهم:")
            parts.extend(f"• {x}" for x in findings)
        if actions:
            parts.append("")
            parts.append("پیشنهادهای عملی:")
            parts.extend(f"• {x}" for x in actions)
        if limitations:
            parts.append("")
            parts.append("محدودیت داده:")
            parts.extend(f"• {x}" for x in limitations)
        parts.append("")
        parts.append(
            "اعداد و شناسه‌های دقیق فقط از بخش دادهٔ ERP بالا معتبرند؛ "
            "برداشت‌های متنی به همان شواهد متکی‌اند."
        )
        return "\n".join(parts)

    lines = [evidence, "", "تحلیل هوشمند روی شواهد ERP:", f"• {summary}"]
    if findings:
        lines.append("• برداشت‌های کلیدی:")
        lines.extend(f"  - {x}" for x in findings)
    if actions:
        lines.append("• اقدام‌های پیشنهادی:")
        lines.extend(f"  - {x}" for x in actions)
    if limitations:
        lines.append("• محدودیت داده:")
        lines.extend(f"  - {x}" for x in limitations)
    lines.append(
        "• اعداد و شناسه‌های دقیق فقط از بخش Grounded بالا معتبرند؛ "
        "مدل فقط مجاز به ارجاع به مقادیر موجود در همان شواهد است."
    )
    return "\n".join(lines)


def wrap_deterministic_fallback(evidence_text: str, prompt: str, tier: str, note: str = "") -> str:
    evidence = str(evidence_text or "").strip()
    if tier == "premium":
        lead = "در حال حاضر تحلیل زبانی تکمیلی در دسترس نبود، اما خلاصهٔ مستقیم ERP را می‌توانید استفاده کنید:"
    elif tier == "deterministic_only":
        lead = "پاسخ از مسیر ابزارهای ERP (بدون مدل ابری):"
    else:
        lead = "خلاصهٔ مبتنی بر دادهٔ ERP:"
    parts = [lead, ""]
    q = re.sub(r"\s+", " ", str(prompt or "")).strip()
    if q and tier != "deterministic_only":
        parts += [f"درخواست شما: {q[:280]}", ""]
    parts.append(evidence)
    if note:
        parts += ["", note]
    elif tier == "deterministic_only":
        parts += [
            "",
            "برای پاسخ محاوره‌ای عمیق‌تر، موتور Grok یا Gemini را در دستیار انتخاب کنید.",
        ]
    return "\n".join(parts)


def polish_final_delivery(job: dict[str, Any], text: str, meta: dict[str, Any]) -> str:
    """Light post-pass: normalize whitespace; avoid duplicate disclaimer stacks."""
    if not isinstance(text, str):
        return text
    tier = str((meta or {}).get("delivery_tier") or delivery_tier(job, _NullWorker()))
    cleaned = re.sub(r"\n{3,}", "\n\n", text.strip())
    if tier == "deterministic_only" and "تحلیل هوشمند" in cleaned and meta.get("synthesis") == "deterministic_fallback":
        cleaned = cleaned.replace("\n\nتحلیل هوشمند روی شواهد ERP:", "\n\n")
    return cleaned
