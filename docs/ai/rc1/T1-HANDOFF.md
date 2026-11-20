# T1 HANDOFF — Manufacturing MF-1 (RC1)

**Branch:** feature/rc1-t1-manufacturing
**Depends on:** T2 WarehouseStockApi / InventoryDomain stock API
**GL:** WIP/FG BLOCKED (T5 policy)

## Delivered
- ManufacturingExecutionDomain: draft→approved→released→issue→scrap→QC→FG→closed
- BOM snapshot on order create; approveBom on ProductionDomain
- Stock via ManufacturingInventoryAdapter → InventoryDomain
- MRP-lite recommendations (no auto PO)

## Tests
- tests/test_manufacturing_mf1_php_e2e.py + harness mf_e2e_*
- DATABASE_TESTED: pending integrator re-run on release SHA
