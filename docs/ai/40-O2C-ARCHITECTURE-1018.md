# ERPSMART 10.18.0 — Order-to-Cash Bounded Vertical Slice

**Status:** SOURCE_TESTED + DATABASE_TESTED (sandbox) — **NOT_DEPLOYED**  
**Branch:** `feature/erp-o2c-v10.18.0`  
**AR_POSTING:** `BLOCKED`  
**SETTLEMENT:** `NOT_IMPLEMENTED`  
**Hotfix:** see `docs/ai/42-O2C-HOTFIX-1018-1.md` (v10.18.1 partial invoice / billing isolation / locks / idempotency)

## Semantics (evidence-based)

| Concept | Canonical record | Actual meaning in this codebase |
|---|---|---|
| Quotation / commercial order | `acc_sales_docs` `doc_type=preinvoice` (also `invoice`) | Commercial sales document; **not** automatically a confirmed SO |
| Confirmed sales order | same row with `workflow_status ∈ {approved,final}` | Maker≠checker via `OrderToCashDomain::confirmOrder` |
| Reservation | `acc_inventory_reservations` (`source_type=sales_doc`) | Soft hold; **no** stock movement |
| Delivery | `acc_sales_deliveries` + `acc_sales_delivery_lines` + `acc_stock_movements` | Posts outbound stock atomically |
| Customer invoice (O2C) | new `acc_sales_docs` `doc_type=invoice` **draft** + `erp_o2c_invoice_links` | Linked to delivery lines; **no** AR/GL |
| AR / Settlement | — | Explicitly blocked / not implemented in v10.18 |

## State machine

```
draft ──(confirmOrder, maker≠checker)──► approved|final
approved|final ──(reserveStock)──► reservations active (no stock move)
approved|final + reserved ──(deliverStock)──► delivery posted + stock out + reservation consumed
posted delivery ──(createInvoiceFromDelivery)──► draft invoice + erp_o2c_invoice_links
draft invoice ──✗──► AR voucher / cash settlement (BLOCKED)
```

Invalid: reserve/deliver on `draft` or `void`; over-reserve; over-deliver; over-invoice vs delivered; cross-company links.

## Entry-point matrix

| Source | File / method | Creates/mutates | Financial / stock effect | Guard |
|---|---|---|---|---|
| UI create sale | `acc_save_sale` | `acc_sales_docs/lines` draft only | none | `accounting.sales.manage` + CSRF + company scope |
| UI delete draft | `acc_delete_sale` | delete draft only | none | draft-only |
| UI confirm | `acc_o2c_confirm_order` → `OrderToCashDomain::confirmOrder` | workflow → approved | none | Maker/Checker + permission |
| UI reserve | `acc_o2c_reserve` → `SalesDomain::reserveStock` | reservations | no stock move | approved/final + qty/avail gates |
| UI deliver | `acc_o2c_deliver` → `SalesDomain::deliverStock` | delivery + stock out | stock out | reserved qty + idempotency key |
| UI invoice from delivery | `acc_o2c_invoice_delivery` → `createInvoiceFromDelivery` | draft invoice + links | **no AR** | delivered − invoiced qty |
| AI sales draft | `create_sales_invoice_draft` | draft sales doc | none | proposal + human approve |
| AI confirm | `confirm_sales_order` | approved | none | proposal + Maker/Checker |
| AI reserve/deliver | `reserve_sales_stock` / `deliver_sales_stock` | same as UI | same | proposal + domain normalize |
| AI O2C invoice | `create_o2c_invoice_from_delivery` | draft invoice | **no AR** | proposal + domain |
| Manual bypass invoice | same `acc_save_sale` / AI draft | standalone draft | none (no auto AR) | still draft-forced on create |

## Inventory reconciliation

- Reservation never inserts `acc_stock_movements`.
- Delivery inserts `movement_type=sales_delivery`, `direction=out`, `status=posted` in the same transaction as delivery lines and reservation consumption.
- Idempotent retries use `erp_o2c_delivery_idempotency` (workspace+company+sales_doc+key → delivery_id).

## Financial posting status

| Step | Status |
|---|---|
| Draft customer invoice from delivery | IMPLEMENTED |
| Exact qty / money scaling (4dp qty, 2dp money) | IMPLEMENTED |
| AR voucher (Dr AR / Cr Revenue / tax) | **BLOCKED** |
| Cash receipt allocation / settlement | **NOT_IMPLEMENTED** |
| Outstanding AR balance as ledger truth | **NOT claimed** |

## Migrations

- `SalesDomain::migrate` — deliveries (existing)
- `OrderToCashDomain::migrate` — `erp_o2c_invoice_links`, `erp_o2c_delivery_idempotency`
- Schema gate: `RuntimeCache::SCHEMA_VERSION = 10.18.0`

## Tests

- Source: `tests/test_o2c_contracts_1018.py`
- MySQL sandbox: `tests/test_o2c_mysql_integration_1018.py` (disposable Docker MySQL on `:3307`)
- Regression: P2P v10.16/v10.17 suites retained

## Residual risks (next phases)

1. **High:** AR/GL posting kernel not proven — do not enable settlement.
2. **Medium:** `SalesDomain::fulfillment` still aggregates with float internally for display; write paths use scaled integers.
3. **Medium:** Delivery reversal/cancellation not implemented (document-only Gap).
4. **Process:** Staging/production NOT verified; file deploy ≠ DB migrate.
