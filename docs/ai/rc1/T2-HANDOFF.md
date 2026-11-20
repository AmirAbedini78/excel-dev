# T2 HANDOFF — Warehouse / shared stock API (RC1)

**Branch:** `feature/rc1-t2-warehouse`  
**Baseline:** G0 `921b6da` / release sync  
**Owner:** T2 (completed/stabilized by T0 integrator)

## Implemented
- `WarehouseStockApi.php` — reserveForProduction, issueMaterial, receiveFinishedGoods, reverseMovement
- `InventoryDomain` delegates frozen RC1 API to WarehouseStockApi; `migrate()` additive
- Ledger: `acc_stock_movements` + `acc_inventory_reservations`; events `erp_wms_stock_events`; idempotency `erp_wms_idempotency`
- Movement types: `production_issue` (out), `production_fg_receipt` (in); source_type for moves: `wms_stock_event`
- No GL posting

## Tests
- Harness: `tests/harness/wms_e2e_*.php`
- Python: `tests/test_wms_stock_php_e2e_rc1.py` (if present) or docker runner scenarios
- Sandbox: `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / port 3307

## Nonclaims
- No advanced WMS/FEFO; no lot/bin required; no production deploy
