# Handoff — v10.19.0 AR Posting Kernel

## Baseline

- Pre-feature main (post v10.18.1 merge): see Git after merge of this PR
- Feature branch: `feature/erp-ar-posting-v10.19.0`
- Deploy: **NOT_DEPLOYED** (no cPanel / no production migrations)

## Delivered

- `app/Core/AccountingPostingDomain.php` — map, readiness, post, outstanding
- Bootstrap migrate + schema `10.19.0`
- Industrial sales UI: AR post button when readiness + billing + vouchers.manage
- Source contracts: `tests/test_ar_posting_contracts_1019.py`
- PHP+MySQL E2E: `tests/test_ar_php_e2e_1019.py`
- Capability master updated

## Explicitly NOT delivered

- Settlement / payment allocation
- AR reversal UI
- Multi-currency
- Enabling AR without fiscal+map (gated)

## Next safely eligible

After merge + staging fiscal/map setup: optional settlement phase only once posted AR E2E remains green.
