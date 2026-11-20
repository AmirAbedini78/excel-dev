# ERPSMART AUTONOMOUS HANDOFF — v10.22 AP

**Status:** SOURCE_TESTED + DATABASE_TESTED (disposable MySQL) · **NOT_DEPLOYED** · Payment `NOT_IMPLEMENTED`

## Identity

| Field | Value |
|---|---|
| Initial main SHA | `837a8918547876d8eca77eaab05fb99158d4346c` |
| Branch | `feature/erp-ap-posting-v10.22.0` |
| Schema / ModuleRegistry | `10.22.0` |
| Domain | `ApPostingDomain` `10.22.0-ap` |
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
