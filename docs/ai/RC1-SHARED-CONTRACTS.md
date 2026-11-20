# RC1 Shared Contracts (FROZEN)

**Status:** FROZEN 2026-11-20 by T0  
**Baseline:** `db6d6e5eadd5fd7517c02d7df48b748ad2c47c9f`  
**Mutations across boundaries require this contract + ownership map compliance.**

## 1. Canonical identity & scope
- Every mutating call takes `(workspace_id, company_id, actor_user_id)`.
- Source documents carry `source_type` + `source_id` (+ optional `source_line_id`).
- Parties = `acc_parties` (or existing party table used by AP/AR); items = `acc_items`; warehouses = `acc_warehouses`.
- Multi-company isolation is mandatory at domain boundary (assert company belongs to workspace).

## 2. Stock source of truth
- **Ledger:** `acc_stock_movements` (posted rows only count).
- **Reservations:** `acc_inventory_reservations` with `status='active'` (never move stock).
- **Metrics:**
  - `on_hand` = Σ(in) − Σ(out) posted movements
  - `reserved` = Σ active reservations
  - `available` = on_hand − reserved
  - `expected_inbound` = open purchase qty − accepted receipt qty (existing InventoryDomain rule)
- Rejected receipt qty **never** increments stock.
- Purchase GR is **operational stock only** — **NO GRNI / no GL** on receipt (invoice-based AP).

### T2 frozen stock API (must implement; T1/T3 consume)
```
InventoryDomain::reserveForProduction(wid, cid, uid, args): array
InventoryDomain::issueMaterial(wid, cid, uid, args): array
InventoryDomain::receiveFinishedGoods(wid, cid, uid, args): array
InventoryDomain::reverseMovement(wid, cid, uid, args): array
InventoryDomain::postReceipt(...)  // existing createReceipt path remains canonical for PO GR
```
**Args invariants:**
- `idempotency_key` scoped by `(wid,cid,source_type,source_id,key,payload_hash)`
- qty via `OrderToCashDomain::scaledQty` / `fixedQty` (scale 4)
- Lock order: company row → warehouse/item lock targets (`OrderToCashDomain::lockStockTargets`) → reservation rows `FOR UPDATE` → revalidate available → write
- Movement types for manufacturing: `production_issue` (out), `production_fg_receipt` (in), `production_return` (in) if needed
- Source types: `production_order` (+ line ids); sales keep `sales_doc` / `sales_delivery`; purchase keep `inventory_receipt`

## 3. GL source of truth
- **Ledger:** `acc_vouchers` + `acc_voucher_lines`
- One economic event → one posting identity (idempotency + unique source fingerprint)
- Open fiscal period required; closed period rejects
- Reversal = explicit reversing voucher; never silent delete
- Dr = Cr per voucher; signed balances reconcile to AP/AR allocation subledgers

## 4. AP model (locked)
- **Invoice-based AP; NO GRNI.**
- Goods receipt = quantity/operational only.
- AP recognized on posted supplier invoice (`ApPostingDomain::postSupplierInvoice`).
- Payments/allocations: `ApSettlementDomain` (v10.23).
- Do **not** invent receipt-based financial accrual.

## 5. Manufacturing valuation policy (T5)
- Resolver classes: `POSTED_ACTUAL` | `PROVISIONAL` | `UNVALUED` | `BLOCKED`
- Cost evidence must cite movement/invoice/voucher IDs.
- **RC1 default:** WIP/FG **GL valuation = BLOCKED** unless T5 proves balanced, period-safe, non-duplicative mapping against invoice-based inventory recognition.
- Operational stock + verified cost rollup report is acceptable for MF-1 when GL remains BLOCKED.
- Never label estimated numbers as `actual`.

## 6. Money & quantity
- Qty scale: 4 decimal places (`scaledQty`/`fixedQty`).
- Money scale: 2 decimal places for IRR amounts (`PurchaseMatchingDomain::fixed(..., 2)`).
- Ledger currency: **IRR**.
- Toman is **display-only** via T4 helpers when company setting selects Toman (`1 Toman = 10 IRR`); never silent multiply in GL.
- Reject float money conversions; overflow fail-closed.

## 7. Permissions & AI
- Authorization at domain boundary via Tenant permissions; UI presence is not auth.
- AI may propose only; human approval + deterministic domain executor required.
- No AI free-text stock/GL mutation.

## 8. Migrations
- Additive only; versioned; idempotent migrate methods.
- T0 applies central schema wiring / ModuleRegistry version bumps.
- Lane PRs may include domain `migrate()` methods; T0 merges ordering.

## 9. Test environment identity (verified 2026-11-20)
| Field | Value |
|-------|-------|
| Container name | `erpsmart-p2p-mysql-test` |
| Container ID | `02eb215a87f6a726cf4efb1546f4b09865c9eb6eec9de1e9dc0e6b3163672db6` |
| Image | `mysql:8.0` (8.0.46) |
| Host/port | `127.0.0.1:3307` / `host.docker.internal:3307` |
| Database | `erpsmart_p2p_test` |
| User | `p2p` / `p2p_test` |
| PHP runner | Docker image `erpsmart-o2c-php:8.2` |
| Host PHP | NOT on PATH — use Docker PHP image |
| Production / cPanel | **FORBIDDEN** |

Refuse unverified `*test*` names without container identity proof.

## 10. Merge order (G2)
1. T2 stock API + T5 valuation policy/docs (foundations)
2. T1 manufacturing consuming T2 API
3. T3/T4/T6
4. T7 acceptance suite continuously; blocks merge if mandatory skip

## 11. Explicit non-claims (POST_RC1)
- Live taxpayer / Sayad / bank submission
- Statutory payroll
- Full FX/customs GL
- Advanced WMS/FEFO automation
- Auto WIP/FG GL when policy unproven
