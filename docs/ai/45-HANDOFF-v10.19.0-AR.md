# Handoff — v10.19.0 AR Posting Kernel

**NOT_DEPLOYED**

## Baseline / merge

- Pre-feature main: `2f7d5b15e37ca6093d76d9d7233ba03332c9fe31` (v10.18.1 / PR #8)
- Feature branch: `feature/erp-ar-posting-v10.19.0`
- Feature HEAD: `1694afb8d45cd0287fae19865d311a2db43845ae`
- PR: https://github.com/AmirAbedini78/excel-dev/pull/9
- Merge SHA: `3d9385ec2f8c2405ec6187c1e6bf49403e97e61f`
- Final CI (PR): https://github.com/AmirAbedini78/excel-dev/actions/runs/44724887453
- Post-merge CI: https://github.com/AmirAbedini78/excel-dev/actions/runs/44725423290 (success)

## Delivered

- `app/Core/AccountingPostingDomain.php` — map, readiness, post, outstanding
- Bootstrap migrate + schema `10.19.0`
- Industrial sales UI: AR post button when readiness + billing + vouchers.manage
- Source contracts: `tests/test_ar_posting_contracts_1019.py`
- PHP+MySQL E2E: `tests/test_ar_php_e2e_1019.py` (balanced voucher, maker/checker, idempotency, concurrent no-double, outstanding from AR account)
- Capability master updated

## Accounting effects / no-double-post

- Dr AR / Cr Revenue (+ Cr Tax when mapped) on canonical `acc_vouchers`/`acc_voucher_lines`
- Unique `erp_ar_postings` per invoice + idempotency key; race recovery returns winner
- Outstanding = SUM(debit−credit) on mapped receivable account for party

## Explicitly NOT delivered

- Settlement / payment allocation (`SETTLEMENT=NOT_IMPLEMENTED`)
- AR reversal UI
- Multi-currency
- Staging/production verification
- Enabling AR without fiscal+map (gated by `assessReadiness`)

## Next safely eligible

Settlement / customer payment allocation only after staging fiscal+map setup and posted-AR smoke remains green. No production deploy from this workstream.
