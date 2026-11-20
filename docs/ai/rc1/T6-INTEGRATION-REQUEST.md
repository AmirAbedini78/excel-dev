# T6 → T0 Integration Request

**Lane:** T6 Platform/UX/Safe AI  
**Branch:** `feature/rc1-t6-platform-ux`  
**Baseline G0:** `921b6dac631763ce8fc772fe54cbc7d1e92a87a0`  
**Target:** `release/erpsmart-rc1`

## Why T0 must re-apply
T6 needs cockpit routes to be operable. The following T0-owned central files were patched in-lane for TR-1/MF-1 operability. On merge conflicts, **re-apply these deltas** (do not drop routes).

## Files requiring T0 merge attention

### 1. `app/bootstrap.php`
Add requires (after AiSuggestionEngine):
- `UiRtlSupport.php`
- `UiRoleActionMatrix.php`
- `AiProposalGuardrails.php`
- `DashboardTradingCockpit.php`
- `DashboardManufacturingCockpit.php`

### 2. `app/Core/ModuleRegistry.php`
- `procurement.pages` include `trading_cockpit`
- `production.pages` include `manufacturing_cockpit`
- Do **not** bump ModuleRegistry VERSION solely for this page list unless other lanes require it.

### 3. `index.php`
- `require_once` `UiCockpitModule.php`
- POST dispatch: `str_starts_with($action,'ui_cockpit_')` → `UiCockpitModule::handle`
- Nav + `navPerm` + `$pagePermission` entries for `trading_cockpit` / `manufacturing_cockpit`
- Render branches for both pages
- CSS link `assets/ui-cockpit.css` for those pages

## T6-owned (merge as-is)
- `app/Core/Ui*.php`, `Dashboard*.php`, `AiProposalGuardrails.php`
- `app/Modules/UiCockpitModule.php`
- `assets/ui-cockpit.css`
- `tests/test_rc1_t6_platform_ux.py`
- `tests/php_rc1_t6_guardrails_test.php`
- Docs under `docs/ai/rc1/T6-*.md`

## Not claimed by T6
- No T1/T2/T3 domain rewrites
- No RuntimeCache SCHEMA_VERSION bump
- No production deploy
