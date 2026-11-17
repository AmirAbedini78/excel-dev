# ERPSMART — ERP & Agent-first Transformation Blueprint

**Date:** 2026-11-17
**Repository baseline:** `621507ca2a2f93cc9e7fdefe71c118b10ab89cc7`
**Current delivery:** v10.12.0 **Manufacturing Planning Pilot + P0 safety/CI correction**. Not a complete manufacturing ERP or a production-ready autonomous agent.

## 1. North Star and implementation rules

ERPSMART = **Business System of Record + Business Copilot** for Iranian B2B importers/distributors/manufacturers. It is not merely a chat interface. Existing accounting/stock/tool services remain authoritative. The Python model can choose among approved tool descriptors but cannot execute SQL, access another company, invent IDs/amounts, post a voucher, or bypass approval. No separate replicated accounting database. Keep PHP/cPanel Control Plane and Docker Worker separately deployable. Never store credentials in source/seed fixtures or model-visible data.

**Delivery order:** establish correct domain models, posting and audit boundaries → ergonomic human workflows → complete typed read tools → proposal/approval execution → operational Agent → document RAG and MCP adapters → learning from measured evaluations. Multi-agent complexity is deferred until traces/evals show the need.

## 2. External benchmark — references, not imported code

| Source | Relevant observed capabilities | Applied principle |
|---|---|---|
| [Sepidar official](https://www.websepidar.com/Sepidar/) | Accounting, inventory, sales/customers, manufacturing, payroll, assets, foreign orders, taxpayer integration | Iran market requires business-package coverage, not only a general ledger |
| [Holoo features reference](https://www.holoocenter.com/نرم-افزار-هلو-شرکت-هاى-پیمانکاری/) | Formulas for manufacturing, material consumption, shortage, manufacturing margin | Lean and understandable production UX |
| [ERPNext manufacturing docs](https://docs.frappe.io/erpnext/manufacturing) | BOM, work orders, planning, job cards | BOM revision → production order → material movement is a governed state machine |
| [Odoo supply chain 19](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp.html) | Procurement/inventory/manufacturing logistics | End-to-end stock truth, no duplicate balances |
| [Microsoft Business Central](https://learn.microsoft.com/en-us/dynamics365/business-central/production-manage-manufacturing) | Manufacturing integrated with costing, warehouse, subcontracting and quality | Future costing/QC/routing are coupled, not isolated feature buttons |
| [LangGraph](https://github.com/langchain-ai/langgraph) | Checkpoints, human interrupts, durable execution | Adopt resumable state-machine patterns without rewriting ERP prematurely |
| [MCP servers](https://github.com/modelcontextprotocol/servers) | Example typed tool interfaces; explicitly reference examples, not turnkey production authorization | Future MCP facade over *existing* audited/authorized PHP tools |
| [OpenAI Agents SDK](https://github.com/openai/openai-agents-python) | Tracing, validation and handoffs | Structured traces and tool provenance, not opaque reasoning |
| [OWASP agent security](https://cheatsheetseries.owasp.org/cheatsheets/AI_Agent_Security_Cheat_Sheet.html) | Tool boundaries, prompt injection, permissions | Executor must independently enforce all policies |

Research is *targeted*, not a claim to inspect every public repository or reproduce proprietary designs. Respect original project licenses and Iranian regulatory/technical requirements independently.

## 3. Real ERP gap analysis (against current source)

**Implemented/partially implemented:** Accounting/financial vouchers and tax configuration; Purchase documents; Warehouse stock ledger, receipt and reservations; Sales/fulfillment; Trade/shipment/landed cost; CRM; Business Copilot and Tool Registry; permissions, audit and workspace isolation. *Implemented* does not mean certified or fully production-accepted.

**Gaps requiring product work** (do not claim these are delivered):

1. **Financial close/controllership:** fiscal years, locked periods, immutable post/reversal, balanced debit/credit, opening balances, multi-currency revaluation, cash/bank reconciliation, AR/AP ageing and audit-ready reports; jurisdiction-specific taxpayer/e-invoice compliance to validate with local accountants.
2. **Procure-to-pay:** purchase requisitions → RFQ/vendor quotes → approval → PO → partial/quality receipt → bill matching → payment and credit notes; supplier scorecard.
3. **Manufacturing:** revision-controlled BOM, routing/work center, capacity, MRP with scheduled demand and pegging, work order lifecycle, component reservations/issue, yield/scrap and returns, WIP, overhead, labor and variance, serial/lot QC/traceability, finished-goods receipt, GL posting. Pilot in this patch has *draft BOM, draft plan, read-only shortage preview only*.
4. **Order-to-cash:** lead/quote/price lists/customer credit → order → allocation → shipment → invoice → receipts → return/warranty and profitability.
5. **Trade:** foreign-currency contracts/Incoterms/FX, forwarder and customs timeline, cost allocation/revaluation, shipment ETA exposure, imports with compliance audit.
6. **HR/Assets/Projects/Service:** personnel, payroll/attendance, depreciation/assets, project budgets/job costing, service tickets/SLA; each behind a separate bounded module.
7. **UX/ops:** role-based workspace, operational search/filters, bulk operations and keyboard access, Farsi RTL/Jalali, accessible form errors, mobile tables, per-step statuses, export and printable audit artifacts; observability and backup/restore.

## 4. Current candidate v10.12.0 — shipped slice

- P0 fix: release test CSS cache expectation matches current v10.11.0; `local_only` is a hard policy gate before cursor/xAI model preference and before context-selected cloud provider overrides.
- Add **opt-in production module**, disabled by default and dependent on inventory.
- Add three tenant-scoped MySQL tables: `erp_production_boms`, `erp_production_bom_lines`, `erp_production_plans` via existing schema bootstrap gate. All are draft-only; creation audited with existing `Audit::log` and CSRF/permission checked at existing `index.php` action gate.
- Add BOM creation (product + 1–20 components), company-scoped display and draft production plan with warehouse/quantity/due date.
- Preview requirements by scaled BOM quantity + per-item scrap. Compare against **posted movement minus active reservations**, from canonical `InventoryDomain` batch query. Display shortage and rough price-based estimate, *not* actual manufacturing cost or authoritative availability to promise.
- Optional idempotent demo seed, for a **dedicated explicitly DEMO-named company only**; 24 materials, 6 finished products, 1 warehouse, 6 BOMs and 30 draft plans. No auto seed in boot/deploy; no fake accounting vouchers or stock movements.
- Existing Ollama/Gemini/Grok/Cursor Worker is not redeployed as part of file installation. One provider security fix in `engine/provider_gateway.py` requires a Worker rebuild after production validation.

**Known limitations:** first 200 items shown in editor; first 100 BOM/plans; only single-level BOM, no schedule-based MRP/netting, no purchase lead times, multi-warehouse transfers or open-WO reservations; do not assume independent draft plans have allocated stock. On large datasets the original `InventoryDomain::purchasePipeline` cap remains for inbound estimates, so manufacturing *shortage* uses only actual available stock. No automatic DB rollback of schema additions; tables are additive and opt-in, data backup remains mandatory before deployment.

## 5. Agent tool backlog — implement only after domain contracts

Each future action requires a **versioned typed descriptor** `{id, description, input_schema, permission, company_scope, risk_level, output_evidence_schema, max_rows, timeout}`. Tool IDs are stable; selected entity IDs come from server-verified mentions. Schema validation and actor permission execute on the server. Each response has evidence IDs, freshness, aggregation grain, limitations and truncation markers.

### Read / analysis candidates (priority order)

- Finance: `finance.period_status`, `finance.trial_balance`, `finance.general_ledger`, `finance.aged_receivables`, `finance.aged_payables`, `finance.cash_position`, `finance.bank_reconciliation_exceptions`, `finance.profitability_by_company`, `finance.fx_exposure`, `finance.tax_invoice_status`, `finance.payment_forecast`.
- Purchase: `procurement.open_orders`, `procurement.vendor_scorecard`, `procurement.price_comparison`, `procurement.late_receipts`, `procurement.quality_rejections`, `procurement.three_way_match_exceptions`.
- Stock: `inventory.availability`, `inventory.reservation_exposure`, `inventory.slow_moving`, `inventory.expiry_and_lot`, `inventory.stock_valuation`, `inventory.reorder_proposal`.
- Manufacturing: `production.bom_explain`, `production.material_readiness`, `production.shortage_impact`, `production.work_order_status`, `production.wip_cost`, `production.capacity_exposure`, `production.scrap_variance`.
- Sales/CRM: `sales.quote_pipeline`, `sales.order_commitments`, `sales.invoice_detail`, `sales.gross_margin`, `crm.customer_360`, `crm.followups_due`, `crm.churn_risk`.
- Trade: `trade.open_cases`, `trade.shipment_eta`, `trade.landed_cost`, `trade.customs_exceptions`, `trade.fx_and_payment_risk`, `trade.shipment_commitment_impact`.
- Executive: `management.role_brief`, `management.business_health`, `management.anomaly_explain`, `management.priority_queue`, `management.compare_periods`.

These are *backlog IDs*, not a claim that all endpoints are present. Reuse existing `AiToolRegistry`/`AiCapabilityRegistry` where equivalent; do not duplicate APIs.

### Guarded actions (never direct agent writes)

`draft_purchase_order`, `draft_sales_invoice`, `draft_stock_transfer`, `draft_production_order`, `draft_payment`, `draft_followup`, `draft_bank_reconciliation`, `draft_reorder_plan`: **generate proposal only**, present full human-readable diff, risk class, provenance, expiry and source IDs; approval must re-check tenancy, permissions, stock, period lock, balance, limits and optimistic version before atomic idempotent execution. On errors, no silent success. No blanket auto-approve.

### Conversational UX standard

Every Business Copilot turn should disclose **intent → what was checked → findings → recommendation → constraints → next safe action** in natural Persian, with expandable evidence and proactive clarification only for genuinely ambiguous entities. Long operations need a visible task status and resumable context; model output must not masquerade as a posted transaction. A CLI/web test matrix must include Farsi variants, implicit/explicit mentions, negative/ambiguous cases, permission denial, stale data, timeouts and model fallback.

## 6. RAG/MCP/multi-agent decisions

- **RAG** for authorized manuals, contracts, notes, standards and procedures; retrieval filters must be workspace/company/document ACL, with citation/freshness. It must NOT replace numeric ERP SQL/tool evidence. Start with bounded chunking and hybrid retrieval only after document corpus, data governance and evals.
- **MCP** can later expose strictly allowlisted, versioned tool descriptors from the existing PHP Tool Registry. Per-client OAuth/scopes and approval at executor required. No free-form remote shell, broad SQL, unrestricted filesystem or undocumented third-party MCP servers in production.
- **Multi-agent** is deferred. First measure a single planning agent with deterministic skills and human confirmation. Add a subordinate specialist only if evaluation shows improved accuracy or completion at acceptable cost/latency.
- **Observability:** trace IDs across job/lease/tool/evidence/proposal/approval, token and per-task cost, P50/P95 latency, model/provider success, fallback %, exact rejection reason. Redact secrets and personal data before model/log providers.

## 7. Release and acceptance gates

1. **Safety baseline:** CI green, PHP/JS/Python gate, provider local-only enforcement and tenant/BOM authorization tests.
2. **Production pilot:** backup database, activate module in *demo workspace only*, create BOM and plan, check shortage against real posted stock, verify no inventory/GL mutation; perform cross-company and no-permission negative tests.
3. **Seed:** manually run CLI on existing named demo company only; verify dry-run first, idempotent retry and no real ledger touch.
4. **Deployment:** exact SHA/HEAD patch, rollback of files, schema backup separately, CI, cPanel app deployment, Docker Worker rebuild separately due provider guard; do not replace secrets.
5. **Agent gate later:** task completion on 50+ anonymized real workflows per role, 100% zero unauthorized writes, evidence match, financial reconciliations, P95 time/cost measured on actual host; release only with signed approval.

## 8. Forward two-week increments (each independently shippable)

- **S0 / this patch:** unblock CI, close provider policy bypass; opt-in BOM + plan + shortages; safe demo seed.
- **S1:** production lifecycle domain (work centers, revisions, release/reservation/issue/receipt) with invariants + costing adapters, auditable reversal; first real end-to-end production fixture.
- **S2:** procurement/finance correctness audits: 3-way match, journal constraints, period close, currency/revaluation and supplier reconciliation.
- **S3:** customer order-to-cash/returns, credit limits, trade/shipments and role-aware ERP dashboard; UX consistency and accessibility.
- **S4:** comprehensive tenant-isolated synthetic demo company: customers, suppliers, inventory, PO/GRN/bills, SO/deliveries/invoices, trade ETA, BOM/WOs and **balanced** GL; non-production namespace and verified seed receipts.
- **S5:** 20–40 highest-value read tools backed by domain truth; Agent Evals, trace and policy taxonomy; Persian conversational UX.
- **S6:** approve-ready write proposals with reversible/idempotent actions and explicit audit, not silent direct writes.
- **S7:** document RAG (ACL, citations, evals), MCP gateway with auth, optional durable agent orchestration, proactive briefs and alerts.

Do not collapse these phases into a single untested DB migration. Later slices remain design/backlog until built and validated.
