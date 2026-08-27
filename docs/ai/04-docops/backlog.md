# SmartDocs Backlog

## P0 — v10 two-day Pilot Platform sprint

### Thursday 2026-08-27
- [x] Module Kernel v1 + workspace enable/disable + Module Center — LIVE-VALIDATED
- [x] canonical SmartDocs pivot from Accounting-only MVP to Modular AI-Native Business Operations Platform
- [x] Model Provider Gateway v1: local path LIVE-VALIDATED by Job #56; cloud live smoke waits for real provider credential
- [x] Finance capability/action matrix against all existing Finance/Sales/Treasury forms
- [~] Finance building blocks: Purchase + Cheque candidate; live validation pending
- [x] Trade Flow MVP strategy locked: Import/Procure → Warehouse → Landed Cost/Finance → Sales/Delivery → Cash/Margin
- [ ] Inventory + Procurement primitive is the next implementation cycle after Finance candidate validation

### Friday 2026-08-28
- Inventory minimal-complete slice
- Procurement minimal-complete slice
- CRM-lite / Customer 360 connected to Sales + receivables
- Trade/Logistics model + first risk/intelligence slice
- cross-module proactive manager brief
- CSV/Excel/API design-partner import readiness
- commercial demo dataset + prompt pack + first Design Partner demo flow

## P1 — First Design Partner
- target Iranian B2B trading/import/distribution company
- company research + synthetic business-shaped demo data before first meeting
- activate only required modules per workspace
- free scoped Founder/Design Partner Pilot; paid integration/customization/capacity/support after value proof
- track measurable outcome: collection visibility, stockout/excess risk, procurement delay/cost, manager decision time

## P2 — Evidence-driven product iteration
- prioritize module depth only from pilot/customer evidence
- longer real datasets + forecast backtest/calibration
- proactive feedback/outcome loop
- provider routing quality/cost benchmark
- domain benchmark/evaluation assets for Finance/Trade/Inventory/CRM

## Deferred until after first market evidence
- production-grade completeness of every ERP module
- full payroll/tax/legal localization breadth
- large frontend rewrite solely for framework modernization
- autonomous high-risk financial execution
- advanced multi-agent framework migration without complexity need
- training proprietary large models before sufficient licensed/evaluable data exists

### Job #55 routing correction
- Provider Gateway install/Docker contract tests: PASS.
- Product smoke semantic correctness: FAILED because named unquoted party balance fell through to `company_snapshot`.
- Apply `v10.0-party-balance-r1`, repeat the exact Job #55 prompt, and require `search_parties + party_ledger` with no Proposal before closing Cycle 2.

## Cycle 3 live gate
- [x] Job #56 semantic retest PASS: `search_parties + party_ledger`, 1.0s.
- [x] Finance capability/action matrix.
- [~] Purchase invoice Proposal — candidate; live panel test pending.
- [~] Cheque analytics — candidate; live panel test pending.
- [~] Cheque Proposal — candidate; live panel test pending.
- [ ] Cash receive/payment primitive + Agent bridge — next after Cycle 3 PASS.
