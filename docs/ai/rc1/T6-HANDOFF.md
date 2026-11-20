# T6 Handoff — Platform / UX / Safe AI (RC1)

**Branch:** `feature/rc1-t6-platform-ux`  
**G0 baseline:** `921b6dac631763ce8fc772fe54cbc7d1e92a87a0`  
**Author:** AmirHosein \<abedinnezhad1999@gmail.com\>  
**PR target:** `release/erpsmart-rc1`

## Delivered

### 1. Trading cockpit (TR-1)
- **Route:** `index.php?page=trading_cockpit`
- **Core:** `DashboardTradingCockpit::pipeline` / `::trace`
- **UI:** `UiCockpitModule::renderTrading`
- Data-backed PO → GR → P2P bill → AP invoice → payment/allocation stages
- Next actions role-filtered; never claims Paid without allocation; never Bank Reconciled

### 2. Manufacturing cockpit (MF-1)
- **Route:** `index.php?page=manufacturing_cockpit`
- **Core:** `DashboardManufacturingCockpit::snapshot`
- **UI:** `UiCockpitModule::renderManufacturing`
- BOM/plan lists from `ProductionDomain`; stock evidence for production movements; QC + valuation BLOCKED/empty states when T1/T2 evidence missing

### 3. Role matrix
- Code: `UiRoleActionMatrix.php`
- Doc: `docs/ai/rc1/T6-ROLE-ACTION-MATRIX.md`

### 4. Safe AI
- Code: `AiProposalGuardrails.php`
- Doc: `docs/ai/rc1/T6-SAFE-AI-GUARDRAILS.md`
- Hooks exercised: proposal-mode evaluation + free-text mutation block + approval contract checks

### 5. Unauthorized POST
- `UiCockpitModule` refuses stock/GL/AI-direct mutate actions with 403
- Domain writers unchanged (still Tenant-gated)

## Routes / components

| Surface | Route | Components |
|---------|-------|------------|
| Trading | `trading_cockpit` | `DashboardTradingCockpit`, `UiCockpitModule`, `UiRtlSupport` |
| Manufacturing | `manufacturing_cockpit` | `DashboardManufacturingCockpit`, `UiCockpitModule` |
| CSS | — | `assets/ui-cockpit.css` |

Permissions: `procurement.view` (trading), `production.view` (manufacturing).

## Tests
- `tests/test_rc1_t6_platform_ux.py`
- `tests/php_rc1_t6_guardrails_test.php` (Docker `erpsmart-o2c-php:8.2`)

## T0 integration
See `docs/ai/rc1/T6-INTEGRATION-REQUEST.md` for bootstrap/ModuleRegistry/index.php re-apply notes.

## Open blockers
1. **MF issue/FG/QC writers** not in G0 — cockpit shows blocked states until T1/T2 land APIs.
2. **Live browser screenshots** deferred (no authenticated local app session in this lane); checklist in `T6-BROWSER-NOTES.md`.
3. **Cross-tenant E2E with MySQL fixture** not re-run here; cockpit SQL is company-scoped (`workspace_id` + `company_id`).
4. Merge order: prefer after T2/T5/T1/T3 per T0 checklist; T6 is read-mostly and can merge earlier if conflicts resolved via integration request.
