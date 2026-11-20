# T1 Handoff — Manufacturing Execution (MF-1)

**Lane:** T1 · **Branch:** `feature/rc1-t1-manufacturing`  
**Base (G0):** `921b6dac631763ce8fc772fe54cbc7d1e92a87a0`  
**Date:** 2026-11-20  
**DATABASE_TESTED:** YES — Docker PHP `erpsmart-o2c-php:8.2` + MySQL container `erpsmart-p2p-mysql-test` (`02eb215a87f6…`) / DB `erpsmart_mf1_t1_test` (isolated on verified container). Suite: `python -m unittest tests.test_manufacturing_mf1_php_e2e -v` → 5 OK.

## Delivered

### Lifecycle (statuses with real semantics)
`draft → approved → released → in_progress → qc → finished → closed`

| Status | Semantics implemented |
|--------|------------------------|
| draft | Order created; BOM snapshotted (approved BOM, ≥2 components) |
| approved | Maker/checker (approver ≠ creator) |
| released | Reservations via stock API; source BOM marked immutable |
| in_progress | First material issue posted |
| qc | QC accept/reject recorded (reject never becomes FG-accepted) |
| finished | FG receipts cover accepted QC / planned qty |
| closed | Terminal; no further mutations |

### Canonical hierarchy
- **MF authority:** `erp_production_boms` (approved) → snapshot `erp_mf_order_bom_snapshot` on `erp_mf_production_orders`
- **Legacy CRUD (untouched):** `acc_boms` / `acc_production_orders` (industrial UI only)
- **Stock ledger (single):** `acc_stock_movements` + `acc_inventory_reservations` via `ManufacturingInventoryAdapter`
- **No second stock/GL ledger.** WIP/FG GL = **BLOCKED**

### Owned files
- `app/Core/ProductionDomain.php` — BOM approve/immutable + migrate wiring
- `app/Core/ManufacturingExecutionDomain.php` — order lifecycle
- `app/Core/ManufacturingInventoryAdapter.php` — T2 bridge / temporary canonical adapter
- `app/Core/ManufacturingCostEvidence.php` — cost rollup (POSTED_ACTUAL/PROVISIONAL/UNVALUED/BLOCKED)
- `app/Modules/ProductionModule.php` — factory operator UI
- `tests/harness/mf_e2e_*.php`, `tests/test_manufacturing_mf1_php_e2e.py`
- `docs/ai/rc1/T1-HANDOFF.md`

### MRP-lite
`ManufacturingExecutionDomain::mrpRecommendations` — shortage recommendations only; **`auto_po=false`**.

### Cost evidence
`ManufacturingCostEvidence::rollup` cites movement IDs; never labels provisional as `actual`. Uses T5 `CostingAccountingResolver` if present; else UNVALUED/PROVISIONAL + `gl_posting=BLOCKED`.

## Integration requests

### T0
1. Merge after **T2 stock API** lands (G2 order). Temporary adapter posts to canonical ledger until then.
2. Optional bootstrap clarity: Manufacturing* already auto-loaded via `ProductionDomain.php` require_once — no bootstrap edit required for domain classes.
3. Schema: MF tables created by `ProductionDomain::migrate()` (already called from bootstrap).
4. MF E2E uses disposable `erpsmart_mf1_t1_test` on verified MySQL container with advisory lock `erpsmart_mf1_schema` during reset.

### T2
1. Implement frozen APIs on `InventoryDomain`:
   - `reserveForProduction(wid,cid,uid,args)`
   - `issueMaterial(wid,cid,uid,args)`
   - `receiveFinishedGoods(wid,cid,uid,args)`
   - `reverseMovement(wid,cid,uid,args)`
2. When present, `ManufacturingInventoryAdapter::mode()` switches to `inventory_domain` automatically.
3. Movement types used: `production_issue` (out), `production_fg_receipt` (in), `production_return` (in).
4. Source type: `production_order` (+ line/document ids). Idempotency: `(wid,cid,source_type,source_id,key,payload_hash)`.

### T5
1. Optional `CostingAccountingResolver::resolveProductionOrder(wid,cid,orderId)`.
2. Confirm WIP/FG GL remains BLOCKED unless balanced invoice-safe mapping proven.
3. Material unit_cost on issues is PROVISIONAL until AP-invoice proof.

## ER (source ↔ movement)

```
erp_mf_production_orders
  ├── erp_mf_order_bom_snapshot (frozen BOM lines)
  ├── erp_mf_material_issues → erp_mf_material_issue_lines → acc_stock_movements (production_issue/out)
  ├── erp_mf_qc_events (accept|reject; reject ∉ FG)
  ├── erp_mf_fg_receipts → acc_stock_movements (production_fg_receipt/in)
  ├── erp_mf_scrap_events (separate; not FG accepted qty)
  └── erp_mf_work_ops (time/output evidence)

acc_inventory_reservations source_type=production_order (release)
```

## Tests
```bash
# Docker PHP + verified MySQL
python -m unittest tests.test_manufacturing_mf1_php_e2e -v
```
Covers: BOM 2 materials, partial issue 2/3, idempotent replay, concurrent issue, insufficient stock, scrap separate, QC reject≠FG, partial/repeat FG, injected rollback, malformed qty, duplicate component, company isolation, maker/checker, closed period, cost evidence, no parallel GL.

## Explicit non-claims
- No automatic WIP/FG GL
- No auto PO from MRP
- Temporary stock adapter until T2 merges (same ledger, not a fork)
