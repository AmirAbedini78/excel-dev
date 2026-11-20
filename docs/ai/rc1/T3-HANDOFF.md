# T3 Handoff — Trading & Logistics (RC1)

**Lane:** T3 · **Branch:** `feature/rc1-t3-trading`  
**Baseline G0:** `921b6dac631763ce8fc772fe54cbc7d1e92a87a0`  
**Status:** SOURCE + DATABASE_TESTED (sandbox) · NOT_DEPLOYED  
**Integration target:** `release/erpsmart-rc1`

## Delivered

1. **Trading cockpit / query domain** — `app/Core/TradingCockpitDomain.php`
   - `searchDomestic`, `poLifecycle`, `findSupplierInvoiceDuplicates`, `foreignTradeAssessment`, `explainException`
   - Timeline: PO → GR → supplier bill → AP voucher → payment → allocation with source IDs, qtys, IRR labels, statuses
2. **Trade UI harden** — `app/Modules/TradeModule.php`
   - Domestic TR-1 cockpit tab (search + lifecycle)
   - Foreign tab marked DRAFT; exception flashes via `explainException`
3. **TradeDomain** — loads cockpit; `FOREIGN_TRADE_GL = DRAFT_READ_ONLY_FUTURE`
4. **Foreign-trade assessment** — `docs/ai/rc1/T3-FOREIGN-TRADE-ASSESSMENT.md`
5. **Iran handbook** — `docs/ai/rc1/T3-IRAN-TRADING-HANDBOOK.md` (regulatory non-claims)
6. **PHP+MySQL proof** — `tests/test_trading_cockpit_php_e2e_rc1.py` (+ harness scenarios)

## TR-1 evidence (exact figures)

| Step | Expected | Asserted |
|---|---|---|
| PO | 10 × 1000 IRR | ordered_qty `10.0000`, po net path |
| GR | 6 accepted | accepted_qty `6.0000`; stock_movements `1` |
| Invoices | 4 + 2 | billed_qty `6.0000`; two AP docs |
| AP posted | 6000.00 IRR | `ap_posted_irr=6000.00` |
| Pay 2500 | remain 3500 | party outstanding |
| Pay 3500 | remain 0 | outstanding `0.00` |
| GR GL | none | `receipt_gl_vouchers=0` |
| Duplicate invoice | blocked | different payload same no → error |

**DB identity:** container `erpsmart-p2p-mysql-test` id prefix `02eb215a87f6` · image `mysql:8.0` · PHP `erpsmart-o2c-php:8.2`  
**Test schema:** `erpsmart_t3_trading_test` on the verified container (avoids cross-lane DROP races on shared `erpsmart_p2p_test`)  
**DATABASE_TESTED:** **YES** — `python -m unittest tests.test_trading_cockpit_php_e2e_rc1 -v` → OK (sandbox only; production forbidden)

## Ownership respected

- Did **not** rewrite `ApPostingDomain` / `ApSettlementDomain` / GL kernels
- Did **not** rewrite `InventoryDomain` stock posting
- Did **not** enable FX / customs / import VAT GL
- Reused P2P + AP for TR-1 amounts
- `bootstrap.php` / ModuleRegistry left to T0 (cockpit loaded via `TradeDomain` require)

## Files touched

- `app/Core/TradingCockpitDomain.php` (new)
- `app/Core/TradeDomain.php`
- `app/Modules/TradeModule.php`
- `tests/harness/ap_e2e_bootstrap.php`
- `tests/harness/ap_e2e_runner.php`
- `tests/test_trading_cockpit_php_e2e_rc1.py`
- `docs/ai/rc1/T3-HANDOFF.md`
- `docs/ai/rc1/T3-FOREIGN-TRADE-ASSESSMENT.md`
- `docs/ai/rc1/T3-IRAN-TRADING-HANDBOOK.md`

## Open issues / follow-ups

1. T0 may optionally wire `TradingCockpitDomain` in central bootstrap (already reachable via TradeDomain).
2. Foreign-trade GL remains blocked until T4/T5 evidence + exclusive lock.
3. Broader mandatory matrix from prompt (closed period, refund/return, cross-tenant) partially covered by prior AP/P2P suites; cockpit test focuses on TR-1 + search + duplicate + foreign assessment.
4. UI polish / role matrix refinements → T6 if needed.
