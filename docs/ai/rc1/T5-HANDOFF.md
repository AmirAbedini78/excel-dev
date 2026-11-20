# T5 Handoff — Financial integrity & valuation (RC1)

## Identity

| Field | Value |
|---|---|
| Lane | T5 Financial integrity & valuation |
| Worktree | `C:\Projects\erp-worktrees\rc1-t5` |
| Branch | `feature/rc1-t5-financial` |
| Baseline / G0 freeze | `921b6dac631763ce8fc772fe54cbc7d1e92a87a0` |
| Env author | AmirHosein `<abedinnezhad1999@gmail.com>` |
| Integration target | `release/erpsmart-rc1` only |
| Exclusive lock | New `CostingAccounting*` only — **no** edits to ApPostingDomain / AccountingPostingDomain / ArSettlementDomain |

## Files touched

| Path | Role |
|---|---|
| `app/Core/CostingAccountingDomain.php` | Valuation resolver, MF cost rollup, reconciliation, WIP/FG gate |
| `docs/ai/rc1/T5-VALUATION-DECISION.md` | Decision record: WIP/FG GL = BLOCKED |
| `docs/ai/rc1/T5-HANDOFF.md` | This handoff |
| `tests/harness/t5_costing_e2e_runner.php` | PHP PDO runner on disposable MySQL |
| `tests/test_t5_costing_valuation_e2e.py` | Real PHP+MySQL E2E suite |

Prompt-pack under `docs/ai/rc1/prompt-pack/` may appear untracked from G0 scaffolding; commit only if T0 expects it in-lane (prefer not blocking T5 deliverable).

## Migration IDs

- Additive table: `erp_costing_company_policy` via `CostingAccountingDomain::migrate()`
- **T0 action:** wire migrate into central schema apply / ModuleRegistry if desired; lane does not edit T0-owned bootstrap.

## Implementation (claims)

1. **Valuation resolver** — `resolveMovementValuation` / `resolveItemQtyCost` classify `POSTED_ACTUAL | PROVISIONAL | UNVALUED | BLOCKED` with movement / invoice / voucher / AP posting IDs. Estimates never set `is_actual=true`.
2. **MF cost rollup** — `rollupProductionMaterialCost` sums verified `production_issue` costs; provisional totals are separate keys; `gl_valuation=BLOCKED`.
3. **Reconciliation** — `reconcileSubledgerVsGl` reports voucher Dr/Cr imbalance and AP/AR posted-net vs voucher control lines with **exact signed IRR** diffs; `max_abs_diff_irr` / `balanced`.
4. **Duplicate key detector** — `detectDuplicatePostingKeys` + receipt-GL voucher count (must stay 0).
5. **WIP/FG GL** — policy constant + `attemptWipFgGlPosting` always returns `posted=false`, `classification=BLOCKED`.

## Non-claims

- No GRNI / no receipt GL.
- No WIP/FG voucher posting enabled.
- No patches to AP/AR posting kernels (no P0 defect proven requiring them).
- No production / cPanel / staging deploy.
- No Toman silent multiply in GL (IRR only).
- Labor/overhead journals not invented; rollup is material-issue evidence only.

## Business examples (IRR)

- PO 10 @ 1,000 → GR 6 (stock only, 0 GL) → invoice 4 → AP voucher Dr inventory 4,000 / Cr AP 4,000 once.
- Issue qty 2 of invoiced item → `POSTED_ACTUAL` unit 1,000 · total 2,000 · cites invoice+voucher IDs.
- FG movement → classification `BLOCKED` (no GL).
- Unpriced issue (no invoice, unit_cost 0, no PO) → `UNVALUED`; rollup `posted_actual_total_irr=null`.

## Tests (DATABASE_TESTED)

| Field | Value |
|---|---|
| PHP image | `erpsmart-o2c-php:8.2` |
| MySQL container | `erpsmart-p2p-mysql-test` |
| Container ID prefix | `02eb215a87f6…` |
| Port / DB / user | `3307` / `erpsmart_p2p_test` / `p2p` / `p2p_test` |
| Command | `python -m unittest tests.test_t5_costing_valuation_e2e -v` |
| Coverage | receipt no GL; invoice AP once; UNVALUED missing cost; FG/WIP BLOCKED; reconcile diffs 0.00; no duplicate posting keys |

## API contracts for integrators

```
CostingAccountingDomain::wipFgGlPolicy(wid, cid): array
CostingAccountingDomain::attemptWipFgGlPosting(wid, cid, uid, args): array  # always blocked
CostingAccountingDomain::resolveMovementValuation(wid, cid, movementId): array
CostingAccountingDomain::resolveItemQtyCost(wid, cid, itemId, qtyRaw, context=[]): array
CostingAccountingDomain::rollupProductionMaterialCost(wid, cid, productionOrderId): array
CostingAccountingDomain::reconcileSubledgerVsGl(wid, cid, opts=[]): array
CostingAccountingDomain::detectDuplicatePostingKeys(wid, cid): array
CostingAccountingDomain::migrate(PDO $db): void
```

Movement conventions consumed (T2/T1): `production_issue` / `production_fg_receipt` with `source_type=production_order`.

## Integration requests for T0

1. Merge T5 after/with T2 foundations (merge order G2 §10).
2. Optionally call `CostingAccountingDomain::migrate` from central schema wiring.
3. Do **not** enable WIP/FG GL without revisiting `T5-VALUATION-DECISION.md` criteria.

## Open blockers

| ID | Severity | Owner | Note |
|---|---|---|---|
| T5-B1 | P2 / POST_RC1 | T5+T0 | WIP/FG GL bridge design after proven clearing map |
| T5-B2 | P2 | T1/T2 | Production issue/FG movements must use agreed `source_type`/`movement_type` for rollup to see them |
| T5-B3 | P2 | T5 | Labor/overhead validated journals not in RC1 rollup |

No open P0/P1 in T5 lane scope from this delivery.

## Next integrator action

1. Review PR → `release/erpsmart-rc1`.
2. Confirm T7 includes `tests/test_t5_costing_valuation_e2e.py` in RC1 mandatory suite.
3. T1 consumes rollup + respects `gl_valuation=BLOCKED`.
