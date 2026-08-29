# 13-TRADE-FLOW-MVP — Golden Flow for B2B Trading / Import / Distribution

Status: `STRATEGY LOCKED / IMPLEMENTATION NEXT`

Date: 2026-08-27

## Product decision

ERPSMART's first commercial MVP is **not** a bundle of isolated accounting features and is **not** a broad shallow ERP demo.

The first Design Partner story is one coherent end-to-end trading flow:

```text
Demand / Replenishment signal
→ Supplier / Proforma / Purchase decision
→ Purchase Order
→ Trade Case / Shipment
→ Freight / Insurance / Duty / Other landed charges
→ ETA / delay / supplier / FX risk
→ Warehouse receipt / inspection / put-away
→ Inventory valuation + Landed Cost
→ Vendor bill / Payable / accounting bridge
→ Sales order / invoice / delivery
→ Receivable / cash
→ Real margin + working-capital view
→ Proactive Manager Brief
```

Internal working name: **Trade Resilience Flow**.

## Why this is the first commercial flow

A B2B importer/distributor does not experience Finance, Inventory, Logistics and Sales as independent products. One commercial decision creates effects across all of them. A late shipment can create stockout risk and lost revenue; freight/duty/FX can change true item cost and minimum viable sale price; partial receipt can change the vendor bill quantity and inventory valuation; a sale affects stock, margin and cash.

The MVP must therefore prove that ERPSMART can connect these decisions and facts, not merely answer isolated finance questions.

## Reference operating-model patterns

The flow follows patterns used by mature enterprise systems and current procurement/AI research:

- Odoo Purchase/Inventory/Accounting: PO → receipt → vendor bill; received-quantity billing and 3-way matching.
- Oracle Landed Cost Management: trade operation groups freight, insurance, tax/duty and other charges, allocates them to PO receipts and feeds receipt/cost accounting.
- McKinsey 2026 procurement research: high-value AI is end-to-end across source-to-pay and should start with targeted high-ROI workflows rather than massive ERP replacement.
- McKinsey 2026 distribution research: distributors face margin pressure and geopolitical/logistics volatility; AI value is concentrated in sourcing alternatives, pricing, supplier risk and assortment/inventory decisions.

Iran deployment adds local trade-document and compliance metadata. The ERP should store references for registration/import permissions, proforma/commercial invoice, transport document, warehouse receipt, customs declaration/release document, origin/currency information and related attachments. Legal/compliance rules stay configuration/RAG-driven and are not hard-coded as permanent accounting truth.

## Golden demo scenario

The demo company is an importer/distributor of industrial components.

1. A product has current stock plus committed/open sales demand.
2. ERPSMART detects projected shortage or low coverage.
3. Agent proposes a purchase from a real supplier using grounded item/supplier records.
4. Approved purchase creates/links a Trade Case.
5. Trade Case records route, mode, supplier, currency, Incoterm/reference, planned departure, ETA and shipment status.
6. Freight, insurance, brokerage/customs/duty and other charges are recorded as estimated charges; the system produces estimated landed cost per item.
7. A delay or charge/FX change triggers a risk recalculation: stockout date, revenue-at-risk, margin impact and alternative action.
8. Goods arrive. Warehouse receipt may be partial; inspection/accepted quantity is recorded before stock becomes available.
9. Actual landed cost is allocated to received items and inventory valuation/accounting is updated.
10. Sales can reserve/deliver available stock and issue invoice/draft via the existing guarded Sales flow.
11. Finance sees payable/receivable/cash implications and actual gross margin using landed cost rather than supplier price alone.
12. Manager asks: `امروز در خرید، حمل، انبار، فروش و نقدینگی چه ریسک یا اقدامی مهم‌تر است؟`
13. Cross-module Manager Brief returns prioritized grounded actions and creates only Proposal/Task objects when mutation is requested.

## Minimal entities for the MVP

### Procurement
- supplier
- item
- supplier quote / proforma reference
- purchase request or replenishment trigger
- purchase order/document
- ordered quantity / price / currency
- payment terms

### Trade / Logistics
- trade_case
- shipment reference
- origin / destination
- transport mode: sea / air / land
- carrier / forwarder reference
- planned departure / ETA / actual arrival
- status
- currency / exchange-rate snapshot
- commercial/import document references
- estimated and actual charges

### Warehouse / Inventory
- warehouse
- receipt
- receipt lines
- inspection/accepted/rejected quantity
- stock movement
- on-hand / reserved / available
- reorder/min-stock signal

### Cost / Finance
- supplier invoice / purchase document
- landed-cost charge
- allocation basis
- estimated landed cost
- actual landed cost
- inventory valuation bridge
- payable / payment state
- sales invoice / receivable
- realized gross margin

### Sales
- customer
- quote/order or sales document
- reservation/delivery state
- invoice
- customer balance

## AI capability contract for the flow

### Reads / analysis
- `inventory_position`
- `replenishment_risk`
- `purchase_pipeline`
- `trade_case_snapshot`
- `shipment_risk`
- `landed_cost_estimate`
- `landed_cost_variance`
- `sales_stock_impact`
- `trade_manager_brief`

These names are roadmap contracts until implemented. Current facts always come from server Tools.

### Actions
- purchase proposal / purchase order draft
- trade case draft
- shipment update proposal where approval is required
- warehouse receipt draft
- landed-cost allocation draft
- sales quote/invoice draft
- financial voucher/payment proposal where appropriate

No high-risk business mutation is silently executed by an LLM.

## Agent structure

User sees one ERPSMART Assistant. Internally the flow may use domain skills/agents:

```text
ERPSMART Orchestrator
├── Procurement skill
├── Trade / Logistics skill
├── Inventory skill
├── Finance skill
└── Sales skill
```

Multi-agent complexity is allowed only when the flow requires it; shared Tools and deterministic domain services remain the system of record.

## RAG / knowledge boundary

RAG is useful for supplier contracts, product catalogs/specifications, Incoterm/company SOP guidance, customs/import procedure documents, shipping documents/policies and customer/sales policies.

RAG is **not** the source of current stock, accounting balance, shipment state, item cost or ERP IDs. Those come from transaction Tools.

## Implementation order after Finance Cycle 3

### Cycle 4 — Inventory + Procurement primitive
- inventory movement/receipt domain primitive;
- on-hand/reserved/available calculation;
- purchase order/draft relationship to existing purchase documents;
- deterministic inventory/replenishment reads;
- Agent purchase Proposal tied to the flow.

### Cycle 5 — Trade Case + Shipment + Landed Cost
- `trade_cases`, shipment state and document references;
- estimated/actual charge lines;
- transparent allocation basis (value/quantity initially);
- ETA/delay and landed-cost variance analysis;
- warehouse receipt linkage.

### Cycle 6 — Sales/Delivery + Manager Brief
- reservation/delivery link to available stock;
- landed-cost-aware margin analysis;
- cross-module proactive Manager Brief;
- final Design Partner demo dataset and prompt pack.

CRM-lite follows the golden operational flow rather than delaying it. Customer 360 then adds opportunity/activity/history around the already connected sales/finance data.

## Two-day MVP success gate

The MVP is ready for first Design Partner outreach when one synthetic but business-realistic scenario can demonstrate:

1. shortage/replenishment detection;
2. grounded purchase Proposal;
3. active shipment/import Trade Case;
4. delay/FX/freight impact on stock and margin;
5. warehouse receipt;
6. landed-cost allocation to inventory;
7. sale/delivery/invoice using available stock;
8. finance/margin impact;
9. one cross-module manager question producing prioritized grounded actions.

Breadth outside this golden flow is secondary until customer evidence exists.

## Research references

- McKinsey, `Redefining procurement performance in the era of agentic AI`, 2026-02-05.
- McKinsey, `How AI is redefining category management in distribution`, 2026-08-05.
- Odoo 19 documentation: Purchase, Vendor Bills, Control Policies / 3-way matching, inventory valuation.
- Oracle Fusion Cloud SCM 26B: Landed Cost Management / Trade Operations.
- Iran trade-system legal context: electronic trade/customs/warehouse document exchange requirements and warehouse synchronization obligations.

## v10.1 Cycle 4 — Inventory + Procurement vertical slice

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`. Shared `InventoryDomain` now connects existing purchase documents to expected inbound, warehouse receipt/inspection, Stock Ledger, on-hand/reserved/available and replenishment reads. Risky receipt posting remains Proposal → Human Approval. See `14-INVENTORY-PROCUREMENT-MVP.md`. Context Picker / Entity Chips stays in committed UX backlog and will attach server-resolved page entities after the Golden Flow pages stabilize.

## v10.2 Cycle 5 — Trade Logistics + Landed Cost

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`. Cycle 4 is now `LIVE E2E PASS` through Job #70 and receipt `RCV-20260829-024216-D32F`. Cycle 5 adds Trade Case → Shipment → ETA/Customs → Estimated/Actual Trade Costs → deterministic Landed Cost allocation → inventory valuation bridge. AI mutations remain Proposal → Human Approval. See `15-TRADE-LOGISTICS-LANDED-COST.md`.
