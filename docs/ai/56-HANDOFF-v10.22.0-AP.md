# ERPSMART AUTONOMOUS HANDOFF — v10.22 AP

**Status:** SOURCE_TESTED + DATABASE_TESTED (disposable MySQL) · **NOT_DEPLOYED** · Payment `NOT_IMPLEMENTED`

## Identity

| Field | Value |
|---|---|
| Initial main SHA | `837a8918547876d8eca77eaab05fb99158d4346c` |
| Branch | `feature/erp-ap-posting-v10.22.0` |
| Commit | `d751b2dd6b4d64b5b78090f6e6f24620fc294851` |
| PR | https://github.com/AmirAbedini78/excel-dev/pull/16 |
| Merge SHA | `280df57a8fdded20096565018718281e6e5ddb1f` |
| Post-merge CI | https://github.com/AmirAbedini78/excel-dev/actions/runs/44747809655 (success) |
| Schema / ModuleRegistry | `10.22.0` (at merge; later advanced by 10.23) |
| Domain | `ApPostingDomain` (at merge `10.22.0-ap`) |
| Architecture | [55-AP-POSTING-ARCHITECTURE-1022.md](55-AP-POSTING-ARCHITECTURE-1022.md) |

## Accounting model decision

**Invoice-based AP liability (NO GRNI).** Goods receipt posts stock only; AP voucher on approved matched invoice:

`Dr purchase/inventory · Dr input VAT (iff tax>0+mapped) · Cr AP control`

Proven: no double inventory asset, no double AP from drafts, stock ≠ GL.

## Files

- `app/Core/ApPostingDomain.php` (new)
- `app/bootstrap.php`, `RuntimeCache.php`, `ModuleRegistry.php`, `SalesDomain.php`, `AccountingPostingDomain.php` (version/wiring)
- `app/Modules/AccountingIndustrialModule.php` (AP UI + map)
- `tests/harness/ap_e2e_*.php`, `tests/test_ap_posting_contracts_1022.py`, `tests/test_ap_php_e2e_1022.py`
- `docs/ai/55-AP-POSTING-ARCHITECTURE-1022.md`, this handoff

## Migrations (additive)

`erp_ap_company_maps`, `erp_ap_workflow`, `erp_ap_postings` — no parallel ledger.

## E2E results (DATABASE_TESTED)

`python -m unittest tests.test_ap_php_e2e_1022` → **6/6 OK** on disposable MySQL `:3307` via `erpsmart-o2c-php:8.2`.

Scenarios: PO10/GR6/inv4+2; over-qty reject; maker/checker; closed period; wrong company rollback; idempotency; immutability; reverse; draft≠debt; additive migrate.

## Security / isolation

Company+workspace scoped queries; AP maps distinct from AR; account type/postability/active checks; historical account snapshots on postings.

## Iran-first

IRR functional currency; no invented VAT rates; no Sayad/taxpayer claims; payment deferred to 10.23.

## Staging / prod

**NOT_DEPLOYED.** No cPanel/production MySQL migrations.

## Next

v10.23 supplier payment + domestic trading E2E (only after this PR merges green).
