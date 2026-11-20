# ERPSMART AUTONOMOUS HANDOFF — v10.23 DOMESTIC TRADING

**Status:** SOURCE_TESTED + DATABASE_TESTED (sandbox) · **NOT_DEPLOYED**

## Identity

| Field | Value |
|---|---|
| Baseline main (post-10.22) | `280df57a8fdded20096565018718281e6e5ddb1f` |
| Branch | `feature/erp-ap-payment-trading-v10.23.0` |
| Commit | `ed901d5ddc10be37fa627c823020babd1808fbd9` |
| PR | https://github.com/AmirAbedini78/excel-dev/pull/17 |
| Merge SHA | `e25fc5903445ec2c96946793e46e789fc20f585d` |
| Post-merge CI | https://github.com/AmirAbedini78/excel-dev/actions/runs/44748809339 (success) |
| Schema / ModuleRegistry | `10.23.0` |
| Domains | `ApSettlementDomain` `10.23.0-ap-settlement`; `ApPostingDomain.PAYMENT=AVAILABLE` |

## Scope delivered

1. Supplier payment: draft → approve → post → allocate (partial/multi-invoice).
2. GL: Dr AP · Cr cash/bank (mapped treasury GL). Methods: `cash`, `bank_transfer` only. Cheque/Sayad rejected (separate lifecycle).
3. Domestic trading E2E amounts IRR: PO 10×1000 → GR 6 → inv 4+2 → AP 6000 → pay 2500 (remain 3500) → pay 3500 (remain 0).
4. Same AP kernel for trading/manufacturing profiles — no parallel ledger.
5. Draft payment ≠ settled; bank reconciliation never claimed.

## Files

- `app/Core/ApSettlementDomain.php` (new)
- Wiring: bootstrap, RuntimeCache, ModuleRegistry, UI treasury AP section
- Tests: `test_ap_settlement_contracts_1023.py`, `test_ap_trading_php_e2e_1023.py`
- Extended `tests/harness/ap_e2e_*`

## Migrations

`erp_ap_payments`, `erp_ap_allocations` (additive).

## Iran-first / regulatory

No invented VAT. No Sayad claim. Cheque UI remains lifecycle-distinct from clearance. Staging/prod: **NOT_DEPLOYED**.

## Next

v10.24 manufacturing costing pilot (BOM→MRP→PO→issue→ops→QC→FG→costing) — spec only after this merges; no fake actual costing.
