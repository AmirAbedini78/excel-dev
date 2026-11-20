# Defect RC1-P0-001 — MF-1 stock API missing

| Field | Value |
|-------|-------|
| ID | RC1-P0-001 |
| Severity | P0 |
| Contract | MF-1 |
| Owner | **T1** (consumer) / **T2** (InventoryDomain API) |
| Status | RESOLVED |
| Baseline | G0 `921b6dac631763ce8fc772fe54cbc7d1e92a87a0` |
| Reporter | T7 (Independent QA) |
| Date | 2026-11-20 |

## Summary
Shared contracts require `InventoryDomain::reserveForProduction`, `issueMaterial`, `receiveFinishedGoods`, and `reverseMovement`. None exist in source at G0. `ProductionDomain` is BOM/plan/preview pilot only — no production order, material issue, scrap, QC, or FG receipt.

## Reproduction
```bash
docker run --rm -v "%CD%:/app" -w /app erpsmart-o2c-php:8.2 php tests/harness/rc1_capability_probe.php mf1
```
Expect `status=BLOCKED` with missing method list.

## Expected
MF-1 E2E: approved BOM → MRP-lite → production order → partial issue → scrap/QC → FG receipt → source-backed cost evidence; stock ledger `acc_stock_movements` only.

## Actual
Probe lists missing APIs; acceptance suite marks **BLOCKED** (not PASS).

## Minimal fix path
1. T2 implements frozen stock API per `docs/ai/RC1-SHARED-CONTRACTS.md` §2.
2. T1 consumes API for production order lifecycle.
3. T7 re-runs `scripts/rc1_acceptance_gate.py` MF-1 module.

## Non-claims
T7 will not stub fake PASS or alter InventoryDomain/ProductionDomain to green the gate.
