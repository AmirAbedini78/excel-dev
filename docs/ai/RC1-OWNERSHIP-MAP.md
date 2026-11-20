# RC1 Ownership Map (authoritative)

**Baseline SHA:** `db6d6e5eadd5fd7517c02d7df48b748ad2c47c9f`  
**Integration branch:** `release/erpsmart-rc1`  
**Frozen:** 2026-11-20 by T0  
**Rule:** Cross-owner edits require T0 integration request. No parallel edits of T0-owned central wiring.

## T0 — Integration & architecture
- `app/bootstrap.php`
- `app/Core/ModuleRegistry.php`
- `app/Core/RuntimeCache.php`
- `index.php`
- Shared schema/bootstrap wiring (`app/Core/AccountingSchema.php`, `app/Core/AccountingExtendedSchema.php` additive apply only via T0)
- `docs/ai/RC1-*.md`, `docs/ai/rc1/RC1-STATUS-BOARD.md`
- Merge authority for all lane PRs into `release/erpsmart-rc1` and final PR to `main`

## T1 — Manufacturing
- `app/Core/ProductionDomain.php`
- `app/Modules/ProductionModule.php`
- New: `app/Core/Manufacturing*.php` (if needed)
- Tests: `tests/test_manufacturing_*`, `tests/harness/mf_*`
- Docs: `docs/ai/rc1/T1-HANDOFF.md`

## T2 — Warehouse & stock
- `app/Core/InventoryDomain.php`
- New: `app/Core/Warehouse*.php` / stock API helpers owned by T2
- `app/Modules/InventoryProcurementModule.php` (inventory UI only; coordinate purchase UI with T3)
- Tests: `tests/test_*inventory*`, `tests/test_*warehouse*`, `tests/harness/wms_*`
- Docs: `docs/ai/rc1/T2-HANDOFF.md`
- **Does NOT own** manufacturing posting or GL

## T3 — Trading
- `app/Core/TradeDomain.php`
- `app/Modules/TradeModule.php`
- New: `app/Core/Trading*.php` (cockpit/trace helpers)
- May **read** `PurchaseMatchingDomain` / AP domains; may not rewrite AP/GL kernels
- Tests: `tests/test_*trade*`, `tests/test_ap_trading_*` extensions for cockpit
- Docs: `docs/ai/rc1/T3-HANDOFF.md`

## T4 — Iran-first localization
- `app/Core/BusinessProfileDomain.php`
- New: `app/Core/IranLocalization*.php`, localization templates under `app/` owned paths
- Tests: `tests/test_iran_*`, localization unit tests
- Docs: `docs/ai/rc1/T4-HANDOFF.md`, regulatory evidence matrix
- **Does NOT** activate tax/Sayad/VAT rates

## T5 — Financial integrity (exclusive lock required)
- New: `app/Core/CostingAccounting*.php` / valuation resolver
- May touch **only after T0 exclusive lock**:
  - `app/Core/ApPostingDomain.php`
  - `app/Core/ApSettlementDomain.php`
  - `app/Core/AccountingPostingDomain.php`
  - `app/Core/ArSettlementDomain.php`
  - `app/Core/OrderToCashDomain.php` (money helpers only if needed)
- Tests: finance/costing reconciliation suites
- Docs: `docs/ai/rc1/T5-HANDOFF.md`, valuation decision record

## T6 — Platform / UX / safe AI
- New: `app/Core/Ui*.php`, `app/Core/Dashboard*.php`, `app/Core/AiProposal*.php` (or bounded extensions)
- Shared CSS/JS components that do not rewrite T1/T2/T3 module bodies
- May propose patches to module UI via integration request
- Tests: UI/role/AI approval gates
- Docs: `docs/ai/rc1/T6-HANDOFF.md`

## T7 — QA / release engineering
- `tests/harness/**` shared harness extensions
- CI workflows (`.github/workflows/**`)
- `scripts/release_gate.py` extensions for RC1-required checks
- Release docs: `docs/ai/rc1/RC1-ACCEPTANCE-REPORT.md`, `RC1-MIGRATION-PRE-FLIGHT.md`, `RC1-RELEASE-MANIFEST.md`
- **Never** alters business domains to green tests

## Shared / coordinate (read OK; write via request)
- `app/Core/SalesDomain.php` — O2C fulfillment (TR-2); T5/T2 coordinate; T0 owns merge conflicts
- `app/Core/PurchaseMatchingDomain.php` — P2P matching; T3 consumes; T5 for AP link
- `app/Modules/AccountingIndustrialModule.php` — UI surfaces; lane-specific sections via request

## Worktrees
| Lane | Path | Branch |
|------|------|--------|
| T0 | `C:\Projects\erp-worktrees\rc1-t0` | `release/erpsmart-rc1` |
| T1 | `C:\Projects\erp-worktrees\rc1-t1` | `feature/rc1-t1-manufacturing` |
| T2 | `C:\Projects\erp-worktrees\rc1-t2` | `feature/rc1-t2-warehouse` |
| T3 | `C:\Projects\erp-worktrees\rc1-t3` | `feature/rc1-t3-trading` |
| T4 | `C:\Projects\erp-worktrees\rc1-t4` | `feature/rc1-t4-iran-first` |
| T5 | `C:\Projects\erp-worktrees\rc1-t5` | `feature/rc1-t5-financial` |
| T6 | `C:\Projects\erp-worktrees\rc1-t6` | `feature/rc1-t6-platform-ux` |
| T7 | `C:\Projects\erp-worktrees\rc1-t7` | `feature/rc1-t7-qa-release` |
