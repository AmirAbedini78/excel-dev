# 14-INVENTORY-PROCUREMENT-MVP — Cycle 4

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`

Cycle 4 turns the existing Purchase primitive into a real operational inventory flow. It deliberately reuses `acc_purchase_docs`/`acc_purchase_lines` and does not create a parallel PO model.

## Golden slice

`Purchase Order / Purchase Invoice → Expected Inbound → Warehouse Receipt / Inspection → Stock Movement → On Hand → Reserved → Available → Replenishment Risk`

## Source of truth

- `on_hand`: posted `acc_stock_movements` only.
- `reserved`: active `acc_inventory_reservations` only.
- `available = on_hand - reserved`.
- `expected_inbound`: purchase quantity minus accepted receipt quantity.
- rejected receipt quantity does not increase stock and remains replaceable inbound.
- `projected_available = available + expected_inbound`.
- shortage is evaluated against `acc_items.min_stock`; suggested replenishment targets `max_stock` when defined, otherwise `min_stock`.

No LLM or RAG is a source of stock truth.

## Shared domain

`InventoryDomain` is the single PHP domain service used by both web UI and AI Tools. This prevents inventory calculations from diverging between manual and Agent flows. Receipt posting is transaction-aware so it can run both manually and inside Proposal Approval without nested transactions.

## New ERP primitives

- `acc_inventory_receipts`
- `acc_inventory_receipt_lines`
- `acc_stock_movements`
- `acc_inventory_reservations`

## AI Tools

Reads: `search_warehouses`, `search_purchase_documents`, `purchase_pipeline`, `inventory_position`, `replenishment_risk`.

Guarded action: `create_warehouse_receipt` → Proposal → Human Approval → posted receipt + accepted-quantity stock movement.

## Live gate

Use the already validated purchase `AI-PUR-20260828-214858-1275` as the first inbound scenario. Before receipt it should show 2 units expected. After approved warehouse receipt it should show +2 on-hand/available and 0 expected inbound for the PLC line.

## Deferred

Shipment, customs and landed cost remain Cycle 5. Page-aware AI Context Picker / Entity Chips remains a committed UX feature to be layered on these real module pages after the Golden Flow entities stabilize.
