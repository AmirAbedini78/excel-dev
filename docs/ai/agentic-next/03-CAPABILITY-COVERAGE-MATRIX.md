# 03 — Capability Coverage Matrix (T0/T3 seed)

**Owners:** T0 (authority) / T3 (ERP tool rows)  
**Aligned contract:** `04-AGENT-RUNTIME-CONTRACT.md`  
**Updated:** 2026-11-21 (T3 Wave2 MF reads)

Legend: **READY** = domain + tool + tests · **PARTIAL** = domain exists, tools thin · **GAP** = missing · **BLOCKED** = policy residual (honest)

| Domain | Read tools | Proposal tools | Agent workflows | Residual / notes |
|--------|------------|----------------|-----------------|------------------|
| Finance / GL | READY (`trial_balance`, `party_ledger`, `document_analytics`, bundles) | READY (`create_voucher_draft`, `create_check`) | A–E | Mutations approval-gated |
| P2P / Procurement | READY (`search_purchase_documents`, `purchase_pipeline`, detail) | READY (purchase invoice draft) | A–E | 3-way match UI separate |
| Inventory | READY (`inventory_position`, `replenishment_risk`, warehouses) | READY (`create_warehouse_receipt`) | A,E,G | Stock via InventoryDomain only |
| Sales / O2C | READY (`sales_*`, fulfillment, margin) | READY (confirm/reserve/deliver/invoice) | A–E | |
| Trade / Landed | READY (case, landed, risk, brief) | READY (case/shipment/cost) | B,C,G | |
| CRM | READY (360, pipeline, followup) | READY (activity, opportunity) | A,G | |
| Manufacturing (MF-1) | **READY (Wave2)** `manufacturing_bom_read`, `manufacturing_order_read`, `manufacturing_material_readiness` | GAP (no MF mutate tools in registry — intentional) | MF reads for A/B/C/G | **BLOCKED:** WIP/FG `UNVALUED` / `GL_BLOCKED`; no auto GL |
| Prediction (F) | GAP | — | F unsupported | No backtested forecast tool |
| RAG (H) | PARTIAL (engine RAG) | N/A — non-authoritative | H proposal-gated | Must not replace LIVE_ERP_FACT |
| MCP | GAP (T4) | — | — | Disabled by default |

## Manufacturing detail

| Capability id | Mode | Permission | UNVALUED | GL_BLOCKED |
|---------------|------|------------|----------|------------|
| `manufacturing_bom_read` | read | `production.view` | n/a (structural) | explicit on payload |
| `manufacturing_order_read` | read | `production.view` | `cost_class` from evidence/T5 | always |
| `manufacturing_material_readiness` | read | `production.view` | unpriced/shortage → UNVALUED/estimate | always; `auto_po=false` |

## Adapter

`AiToolRegistry::contractDescriptors()` maps every catalog row to typed v0.1 descriptors (`id`, `mode`, `risk`, `required_permissions`, schemas, budgets, idempotency, evidence/audit policies).
