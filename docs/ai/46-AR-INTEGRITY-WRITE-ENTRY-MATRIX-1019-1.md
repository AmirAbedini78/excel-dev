# v10.19.1 — Accounting write-entry matrix

| Entry point | Operation | Domain method | Safeguards |
|---|---|---|---|
| UI `acc_save_sale` | Create draft sale | Industrial module INSERT | company-scoped party/items; draft only |
| UI `acc_delete_sale` | Delete/cancel draft | `OrderToCashDomain::deleteOrCancelSalesDraft` | billing→void+cancel links; blocks AR-posted; no orphan links |
| UI `acc_o2c_cancel_billing_invoice` | Cancel O2C draft invoice | `cancelDraftBillingInvoice` | draft-only; keep rows; restore invoiceable qty |
| UI `acc_o2c_confirm_order` | Approve order | `confirmOrder` | maker/checker; not billing doc |
| UI `acc_o2c_reserve` / deliver / invoice | Fulfillment | `SalesDomain` / `createInvoiceFromDelivery` | stock locks; billing gates; idempotency |
| UI `acc_ar_post_invoice` | Post AR | `postCustomerInvoice` | map types; fiscal+period; fingerprint; maker/checker; unique postings |
| UI `acc_save_voucher` | Manual voucher | Industrial module | separate path; not AR kernel |
| AI tools | No direct AR post/cash | proposal-only for inventory receipt etc. | no auto AR mutation |
| Settings map | AR accounts | `setCompanyMap` | asset/income/liability + allow_posting + distinct |

Settlement / cash allocation: **NOT_IMPLEMENTED** in 10.19.1.
