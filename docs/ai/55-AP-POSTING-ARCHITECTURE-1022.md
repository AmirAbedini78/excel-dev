# v10.22 — AP Posting Architecture & Valuation Decision

**Status:** KERNEL_AVAILABLE · Payment/settlement `NOT_IMPLEMENTED` in 10.22 · **NOT_DEPLOYED**  
**Evidence class:** SOURCE_TESTED (+ DATABASE_TESTED via PHP+MySQL E2E when sandbox green)

## Mandatory accounting decision

| Question | Decision | Evidence |
|---|---|---|
| Does goods receipt post GL? | **No** | `InventoryDomain` inserts `acc_inventory_receipts` + `acc_stock_movements` only; no `acc_vouchers` |
| GRNI accrual at receipt? | **Not supported / not implemented** | No GRNI clearing tables or receipt vouchers exist |
| When does AP liability arise? | **On posted supplier invoice (invoice-based)** | New `ApPostingDomain::postSupplierInvoice` → `acc_vouchers` (`source_type=p2p_ap`) |
| Inventory asset double-count risk? | **Avoided** | Stock qty is operational; first inventory/purchase GL debit is at AP post only |
| Stock movement = automatic GL? | **False** | Proven by inventory receipt path |
| Tax / input VAT | **Fail-closed** | Tax line only when `tax_total>0` AND company maps a validated input-VAT account; no invented rates |
| PPV / purchase-price variance | **Not used in 10.22** | No GRNI baseline to clear; map column reserved, unused |
| Parallel ledger? | **Forbidden** | Identity/maps/snapshots only: `erp_ap_company_maps`, `erp_ap_postings`, `erp_ap_workflow` |

### Supported model (canonical)

**Invoice-based AP liability with purchase/inventory debit (no GRNI):**

```
Dr  purchase_account_id   (net − tax)     [expense or inventory asset — company map]
Dr  input_vat_account_id  (tax)           [only if tax>0 and mapped]
Cr  payable_account_id    (net)           [liability AP control]
```

Functional currency: **IRR**. Toman is display-only via existing helpers (never ledger currency).

### Rejected alternatives (this release)

1. **GRNI at receipt** — would invent accrual GL not present in inventory path; deferred until perpetual inventory valuation is designed.
2. **Reuse AR maps** — explicitly forbidden; AP has its own `erp_ap_company_maps`.
3. **Treat draft P2P invoice as debt** — draft ≠ posted; outstanding uses posted voucher lines only.

## Eligibility gates

1. Company AP map + open fiscal year (`assessReadiness`).
2. Goods invoices under enforced P2P: must be `erp_p2p_vendor_bills.status=matched` with `canonical_doc_id`.
3. Service invoices: require `allow_service_manual` company P2P policy (explicit separate approval policy).
4. Workflow: `draft → validated → approved → posted` (maker/checker at each step).
5. Immutable request fingerprint; idempotent posting; atomic voucher + `erp_ap_postings`.

## Outstanding

Ledger-based supplier payable from historically snapshotted AP control accounts on `acc_voucher_lines` (mirrors AR). Draft invoices and payment drafts do not reduce/create debt.

## Payment (v10.23)

Supplier payment + allocation explicitly `NOT_IMPLEMENTED` in 10.22 (`ApPostingDomain::PAYMENT`).
