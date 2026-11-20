# T3 Foreign-Trade Assessment (RC1)

**Status:** `DRAFT_READ_ONLY_FUTURE`  
**RC1 required:** No  
**Owner:** T3 (Trading) · coordinate FX/VAT policy with T4/T5 before any GL enablement

## What exists today (operational / report)

| Capability | Evidence | GL effect |
|---|---|---|
| Trade case CRUD (`acc_trade_cases`) | `TradeDomain::createCase` | None |
| Shipment / milestones | `TradeDomain` shipment + milestone APIs | None |
| Estimated/actual trade costs | `acc_trade_costs` | None — report bridge only |
| Landed cost allocation report | `TradeDomain::landedCostSummary` | Does **not** rewrite `acc_stock_movements.unit_cost` |
| Customs metadata fields | declaration no / office / clearance status | Metadata only |

## Explicitly blocked for RC1

1. **FX GL posting** — no verified FX settlement kernel; rates on trade cases are reporting inputs.
2. **Customs declaration GL** — duties/fees recorded as trade costs remain non-posting until policy + legal source verified.
3. **Import VAT GL** — not enabled; coordinate with T4 regulatory evidence and T5 posting maps.
4. **GRNI / receipt accrual** — domestic and foreign alike: goods receipt is operational stock only (shared contract §4).

## Recommendation

- Keep foreign-trade UI under Trade module tab **Foreign (DRAFT)**.
- Expose assessment via `TradingCockpitDomain::foreignTradeAssessment`.
- Domestic TR-1 (PO→GR→P2P→AP→pay→allocate) remains the RC1 trading path.
- Any future enablement requires T0 integration request + T5 exclusive lock for posting kernels + documented legal sources.

## Non-claims

- Not a statutory customs system.
- Not a live FX dealing / central-bank interface.
- Not taxpayer / Sayad / bank submission.
