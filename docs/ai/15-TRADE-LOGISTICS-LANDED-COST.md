# ERPSMART v10.2 — Trade Logistics + Landed Cost MVP

## Status
`IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`

## Vertical slice
Purchase Document → Trade Case → Shipment → ETA/Delay → Customs/Clearance → Estimated/Actual Trade Costs → Deterministic Landed Cost → Inventory Valuation Bridge.

## Source-of-truth rules
- Purchase documents remain the procurement/accounting source.
- Stock movements remain immutable quantity history.
- Trade costs are stored separately and converted to IRR with an explicit rate.
- Projected cost uses Actual for a cost type when available; otherwise its Estimate.
- Landed-cost allocation is proportional to purchase-line base value.
- Received inventory valuation is derived from accepted receipt quantity × projected landed unit cost.
- AI cannot directly mutate Trade Case, Shipment or Trade Cost; all are Proposal → Human Approval.

## Pilot tables
- `acc_trade_cases`
- `acc_trade_shipments`
- `acc_trade_costs`
- `acc_trade_milestones`

## Agent tools
Reads: `search_trade_cases`, `trade_case_snapshot`, `landed_cost_summary`, `trade_risk_summary`.
Actions: `create_trade_case`, `create_trade_shipment`, `add_trade_cost`.

## Live gate
1. Create Trade Case Proposal from an existing purchase.
2. Human approve and verify case UI.
3. Add/approve shipment and verify ETA.
4. Add estimated + actual trade costs.
5. Verify Landed Cost and inventory valuation bridge through grounded Agent read.
6. Verify risk read for ETA/customs/cost variance.
