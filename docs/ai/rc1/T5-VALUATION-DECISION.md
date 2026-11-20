# T5 Valuation Decision Record — WIP/FG GL (RC1)

**Status:** DECIDED · **Decision:** `WIP/FG GL = BLOCKED`  
**Author lane:** T5 (Financial integrity & valuation)  
**Baseline SHA:** `921b6dac631763ce8fc772fe54cbc7d1e92a87a0`  
**Date:** 2026-11-20  
**Currency:** IRR (Toman display-only)

## Locked AP context (cannot reopen in RC1)

| Question | Locked answer |
|---|---|
| Goods receipt posts GL? | **No** |
| GRNI accrual? | **Not supported** |
| When is AP recognized? | Posted supplier invoice (`ApPostingDomain::postSupplierInvoice`) |
| First inventory/purchase GL debit? | At AP post: Dr purchase/inventory · Cr AP |

Evidence: `docs/ai/55-AP-POSTING-ARCHITECTURE-1022.md`, `InventoryDomain::createReceipt` (stock only), shared contract §4–5.

## Decision

**RC1 keeps WIP/FG general-ledger valuation BLOCKED.**

Operational manufacturing may:

- Issue materials and receive finished goods as **stock movements**.
- Produce a **verified cost rollup** citing movement / invoice / voucher IDs.
- Classify costs as `POSTED_ACTUAL` | `PROVISIONAL` | `UNVALUED` | `BLOCKED`.

It must **not**:

- Auto-post WIP or FG journals in RC1.
- Label provisional / estimated amounts as `actual`.
- Invent GRNI or receipt-based inventory asset recognition.

## Why BLOCKED (not enabled)

1. **Double inventory asset risk.** Invoice-based AP already debits the company-mapped purchase/inventory account when the supplier invoice posts. A second WIP/FG capitalization journal without a proven clearing/offset map would double-recognize the same economic substance as an asset.
2. **No proven clearing bridge.** Under NO-GRNI, there is no receipt accrual to relieve into WIP. Enabling WIP/FG GL would require a new, period-safe, maker/checker-gated map (e.g. inventory → WIP → FG / COGS) that reconciles exactly to AP invoice recognition — **not proven** in current kernels.
3. **Incomplete cost evidence is common.** Material issues may exist before posted AP invoices. RC1 must surface `UNVALUED` / `PROVISIONAL` rather than fabricate actuals that feed GL.
4. **Shared contract default.** `RC1-SHARED-CONTRACTS.md` §5 sets RC1 default WIP/FG GL = BLOCKED unless T5 proves balanced, period-safe, non-duplicative posting. That proof is **not** available; therefore policy remains BLOCKED.

## What RC1 accepts instead (MF-1)

- Stock truth in `acc_stock_movements`.
- Cost evidence via `CostingAccountingDomain::resolveMovementValuation` / `rollupProductionMaterialCost`.
- Reconciliation via `CostingAccountingDomain::reconcileSubledgerVsGl` (exact signed IRR diffs).
- Explicit `gl_valuation=BLOCKED` on rollups and FG movement resolution.

## Revisit criteria (POST_RC1)

Enable WIP/FG GL only when all hold:

1. Company map for inventory / WIP / FG / COGS with typed accounts and open fiscal period.
2. Deterministic relief of invoice-capitalized inventory into WIP without double count (unique source keys + reversal).
3. E2E proof: receipt still posts **zero** GL; AP posts once; WIP/FG journals balance; subledger vs GL diffs = `0.00` IRR.
4. Incomplete costs remain non-posting (`UNVALUED`/`PROVISIONAL`) and never auto-journal.

## Code anchors

- Policy constant: `CostingAccountingDomain::WIP_FG_GL_POLICY = 'BLOCKED'`
- Gate: `CostingAccountingDomain::attemptWipFgGlPosting` → always `posted=false`
- Company row: `erp_costing_company_policy.wip_fg_gl_policy` (RC1 inserts `BLOCKED` only)
