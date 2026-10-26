---
id: analytics_forecast
status: LOCAL-VALIDATED
touches_code:
  - app/Core/AiToolRegistry.php
  - engine/financial_intelligence.py
  - engine/forecast_risk.py
  - engine/proactive_agent.py
  - engine/deep_safe.py
  - engine/read_guard.py
---

# Analytics, Risk & Forecast

## Current proven baseline

- v9.0.1 Financial Intelligence: deterministic metrics/findings + bounded finding-ID priority + severity gate؛
- v9.1 Forecast/Risk/Anomaly: complete-month deterministic linear baseline، MAE planning range، confidence، MAD anomaly و concentration/draft exposure؛
- v9.2 Proactive Accounting: grounded next-best actions + deterministic severity/impact gate؛
- v9.3 regression/latency/release contract around all three routes.

Live Job #49 on deployed v9.3.0 repeated the 9-read forecast/risk route successfully and produced the expected deterministic ranges/findings with no Proposal/write. Because the prompt explicitly requested risks, selection of `forecast_risk_anomaly` was expected. The Job exposed only a web observability transport defect (missing hardening metadata in no-refresh UI), addressed by v9.3.0.1; predictive formulas/routing are unchanged.

همه اعداد از Tool/engine deterministic می‌آیند؛ LLM فقط IDهای موجود را اولویت‌بندی می‌کند.

## Missing

Beyond-current-baseline work:

- KPI registry
- longer real time-series datasets
- rolling backtest and calibration
- collections outcome labels
- learned ranking only after sufficient approved feedback
- formal production SLO after multi-customer evidence

## Forecast rule

LLM must not output unsupported numeric forecast.

Required flow:

```text
series → model → error/confidence → explanation
```

## Dataset policy

Synthetic:
- pipeline validation
- load
- edge cases
- known anomaly injection

Real:
- accuracy claims
- calibration
- business validation

User feedback/outcomes should be captured from the start for future learning.

## 2026-09-10 — v10.10.2 / Kernel v1.5 low-memory candidate

Current implementation contract: **27-LOW-MEMORY-INTELLIGENCE.md**. Status: **LOCAL-VALIDATED**; Windows/Docker/Ollama and product Live Gate remain pending. This entry supersedes earlier r6/v1.4 working-state and transport descriptions, without changing the North Star or frozen financial/approval contracts.

The candidate fixes explicit-model routing, uses grounded_selection_v3 (finding/action IDs only), removes unnecessary known-route planner calls, bounds local inference and endpoint failover, preserves per-supplier comparisons during degradation, and isolates bounded lexical RAG by workspace/company. Model qualification, installation integrity and ERP semantic acceptance are separate gates. See the new contract for settings, limitations, measured synthetic input reduction and file map.

Supplier comparison now reports every returned supplier and separates operational trade-case flags from supplier quality. Existing forecast formulas are unchanged; predictive accuracy still requires real-data rolling backtests.
