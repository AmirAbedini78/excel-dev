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
