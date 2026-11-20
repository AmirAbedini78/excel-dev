# T2 Handoff — Warehouse & stock integrity (RC1)

**Lane:** T2  
**Branch:** `feature/rc1-t2-warehouse`  
**Worktree:** `C:\Projects\erp-worktrees\rc1-t2`  
**Baseline SHA (G0 freeze):** `921b6dac631763ce8fc772fe54cbc7d1e92a87a0`  
**Date:** 2026-11-20

## Actual implementation

Frozen stock API on `InventoryDomain` (thin wrappers) → `WarehouseStockApi`:

| Method | Behavior |
|--------|----------|
| `reserveForProduction(wid,cid,uid,args)` | `source_type=production_order`; replaces active reservations for (order, warehouse); lock via `OrderToCashDomain::lockStockTargets`; no oversell |
| `issueMaterial(wid,cid,uid,args)` | `movement_type=production_issue` direction `out`; consumes reservation; partial OK; idempotent |
| `receiveFinishedGoods(wid,cid,uid,args)` | `movement_type=production_fg_receipt` direction `in`; partial OK; idempotent |
| `reverseMovement(wid,cid,uid,args)` | Opposite posted movements + nonnegative guard; restores issue reservations; audit |
| `createReceipt` (existing) | Canonical PO GR; accepted increments once; rejected never stocks |

Qty: `OrderToCashDomain::scaledQty` / `fixedQty` (scale 4).  
Idempotency table: `erp_wms_idempotency` keyed by `(wid,cid,source_type,source_id,idempotency_key)` + `payload_hash`.  
Events: `erp_wms_stock_events`; movements use `source_type=wms_stock_event` (unique key safe for partials).  
Named locks released in `finally` after each API call.

### Migration IDs
- `InventoryDomain::migrate()` / `WarehouseStockApi::migrate()` → additive `erp_wms_stock_events`, `erp_wms_idempotency`, ensures `erp_o2c_stock_locks` via `OrderToCashDomain::migrate`.
- **T0 integration request:** wire `InventoryDomain::migrate($db)` into central schema apply / ModuleRegistry if not already auto-invoked (T2 does not edit bootstrap/ModuleRegistry).

## Files touched
- `app/Core/WarehouseStockApi.php` (new)
- `app/Core/InventoryDomain.php` (API wrappers + migrate + require helper)
- `tests/harness/wms_e2e_bootstrap.php` (new)
- `tests/harness/wms_e2e_runner.php` (new)
- `tests/harness/wms_e2e_suite.php` (new)
- `tests/harness/wms_concurrent_reserve.sh` (new)
- `tests/test_warehouse_stock_php_e2e_rc1.py` (new)
- `docs/ai/rc1/T2-HANDOFF.md` (this file)

## Non-claims
- No GL / WIP / FG valuation posting (T5).
- No ProductionDomain edits; `production_order_id` is an opaque source id for T1.
- No lot/bin/serial WMS automation beyond traceability via event + movement rows.
- No production deploy / staging claim.

## Business I/O examples
- Seed on_hand 10 RM → two POs reserve 10 → exactly one wins (reserved_sum=10).
- Reserve 5 → issue 3 (idempotent replay) → on_hand 7, reserved 2; mismatched idempotency key rejected.
- Partial issue 3+2 of 8 reserved; FG receipt 1+2 → FG on_hand 3.
- Rejected GR 4 → on_hand unchanged; accepted GR 6 → +6 once.
- Reverse issue 4 → on_hand restored + reservation restored.

## Tests (real PHP + disposable MySQL)

```text
DATABASE_TESTED: yes
Container: erpsmart-p2p-mysql-test
Container ID: 02eb215a87f6a726cf4efb1546f4b09865c9eb6eec9de1e9dc0e6b3163672db6
Port: 3307  DB: erpsmart_p2p_test  User: p2p/p2p_test
PHP: docker image erpsmart-o2c-php:8.2
```

Commands:

```bash
docker run --rm --memory=512m --add-host=host.docker.internal:host-gateway \
  -e O2C_DB_HOST=host.docker.internal -e O2C_DB_PORT=3307 \
  -e O2C_DB_USER=p2p -e O2C_DB_PASS=p2p_test -e O2C_DB_NAME=erpsmart_p2p_test \
  -v <worktree>:/app -w /app erpsmart-o2c-php:8.2 \
  php tests/harness/wms_e2e_suite.php

python -m unittest tests.test_warehouse_stock_php_e2e_rc1 -v
```

**Result (2026-11-20):** suite `ok:true`, failed=[], all 7 cases green (rejected GR, concurrent fight, idempotency, partial issue/FG, cross-company, rollback, reverse).

## Concurrency / isolation / audit
- Concurrent two-process reserve for last units: 1 win / 1 fail proven.
- Cross-company: company 2 cannot reserve company 1 warehouse/item (`inventory_entity_not_owned`).
- Fault-injection rollback leaves zero production reservations.
- `Audit::logForWorkspace` on reserve/issue/FG/reverse (harness captures logs).

## API signatures (for T1/T3/T5)

```php
InventoryDomain::reserveForProduction(int $wid, int $cid, int $uid, array $args): array
// args: production_order_id, warehouse_id, lines[{item_id,quantity,source_line_id?}], notes?, idempotency_key?

InventoryDomain::issueMaterial(int $wid, int $cid, int $uid, array $args): array
// args: production_order_id, warehouse_id, lines[{item_id,quantity,unit_cost?,source_line_id?}], movement_date?, notes?, idempotency_key?

InventoryDomain::receiveFinishedGoods(int $wid, int $cid, int $uid, array $args): array
// args: production_order_id, warehouse_id, lines[{item_id,quantity,unit_cost?}], movement_date?, notes?, idempotency_key?

InventoryDomain::reverseMovement(int $wid, int $cid, int $uid, array $args): array
// args: event_id? | movement_id?, notes?, idempotency_key?

InventoryDomain::createReceipt(...) // unchanged PO GR
InventoryDomain::migrate(PDO $db): void
```

## Open issues
| ID | Sev | Issue | Owner |
|----|-----|-------|-------|
| T2-I1 | P2 | Central migrate wiring for `InventoryDomain::migrate` not applied by T2 (bootstrap/ModuleRegistry owned by T0) | T0 |
| T2-I2 | P2 | Shared disposable MySQL contended by parallel lanes; suite may need `--memory=512m` / retry under host pressure | T7/ops |
| — | P0/P1 | None open for T2 stock API correctness after suite green | — |

## Next integrator action
1. Review/merge this PR into `release/erpsmart-rc1` before T1 manufacturing consume.
2. T0: call `InventoryDomain::migrate` in central schema path.
3. T1: call these APIs with real production_order ids; do not invent a second stock ledger.
