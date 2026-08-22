---
id: analytics_forecast
status: planned_next
touches_code:
  - app/Core/AiToolRegistry.php
  - engine/deep_safe.py
  - engine/read_guard.py
---

# Analytics, Risk & Forecast

## Current

Grounded aggregation, period comparisons, ranking and Safe Deep exist.

## Missing

Production Financial Intelligence layer:

- KPI registry
- time-series extraction
- consistent status semantics
- trend engine
- anomaly signals
- forecast datasets
- backtesting
- confidence/error reporting

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
