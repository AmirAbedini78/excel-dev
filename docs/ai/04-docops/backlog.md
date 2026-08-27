# SmartDocs Backlog

## P0 — v10 two-day Pilot Platform sprint

### Thursday 2026-08-27
- [x] Module Kernel v1 + workspace enable/disable + Module Center — LIVE-VALIDATED
- [x] canonical SmartDocs pivot from Accounting-only MVP to Modular AI-Native Business Operations Platform
- [~] Model Provider Gateway v1: implementation + 6/6 contract tests PASS; local product smoke pending; cloud live smoke waits for real provider credential
- [ ] Finance capability/action matrix against all existing Finance/Sales/Treasury forms — NEXT
- close highest-value missing Finance Agent actions without broad ERP expansion

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
