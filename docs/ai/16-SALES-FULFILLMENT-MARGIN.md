# ERPSMART v10.3 — Sales Fulfillment, Delivery and Margin

## Status
Source candidate for Cycle 6. Live validation is required before closeout.

## Product objective
Close the commercial loop after Procurement/Trade/Inventory without creating a parallel Sales subsystem:

`existing Sales Document → Stock Reservation → Warehouse Delivery → Landed-cost-aware COGS → Gross Margin → Manager Brief`

## Canonical sources
- Sales documents: `acc_sales_docs`, `acc_sales_lines`
- Reservation: `acc_inventory_reservations`
- Quantity ledger: `acc_stock_movements`
- Trade valuation: `TradeDomain::landedCostSummary()`
- New delivery evidence only: `acc_sales_deliveries`, `acc_sales_delivery_lines`

## Invariants
1. Reservation never changes on-hand quantity.
2. Delivery is the Cycle-6 stock mutation and creates immutable posted outbound movement.
3. Delivery requires an active reservation for the same Sales document and warehouse.
4. Delivery does not auto-finalize the sales invoice and does not auto-post a GL voucher.
5. Margin revenue excludes tax.
6. COGS prefers fully actual landed cost; otherwise projected landed cost is explicit.
7. When no Trade valuation exists, fallback cost is explicitly labeled; it must never be presented as actual landed margin.
8. All AI mutations remain Proposal → Human Approval → Execution.

## AI tools
### Read
- `search_sales_documents`
- `sales_fulfillment`
- `sales_margin_summary`
- `trade_manager_brief`

### Proposal
- `reserve_sales_stock` — medium risk
- `deliver_sales_stock` — high risk

## Manager Brief
The first brief combines:
- Trade risk and customs/shipment state
- Inventory shortage/replenishment
- Sales orders whose outstanding quantity is not fully reserved
- Recent delivered gross margins

Near-term cash is intentionally omitted until the cash-transaction operational primitive is complete.

## Live gate
Use one real sales document linked to an inventory item that already has Trade/Landed Cost evidence:
1. reserve stock from a named warehouse;
2. approve and verify `reserved` increases while `on_hand` does not;
3. deliver reserved stock;
4. approve and verify outbound movement and `on_hand` decrease;
5. grounded fulfillment read;
6. grounded margin read with landed-cost basis;
7. grounded Manager Brief.
