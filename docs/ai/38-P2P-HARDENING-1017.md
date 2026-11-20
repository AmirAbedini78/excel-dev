# ERPSMART 10.17.0 — P2P Hardening

**Baseline:** merged main `c1db532` (PR #5 / v10.16.0).

## Purchase Invoice Entry-Point and Bypass Matrix

| Entry path | File / method | Creates goods invoice? | Guard in 10.17 | Residual risk |
|---|---|---|---|---|
| P2P match approve | `PurchaseMatchingDomain::approve` | Yes (draft only) | Canonical path; maker≠checker; qty revalidation; FOR UPDATE | No GL post (intentional) |
| Accounting UI save | `AccountingIndustrialModule::savePurchase` | Yes if `purchase_invoice_goods` | `assertManualGoodsInvoiceAllowed`; exception reason + audit | Exception abuse if owner grants freely |
| AI tool proposal exec | `AiToolRegistry::createPurchaseDraft` | Yes | Same domain assert + exception audit | Same |
| Enterprise RFQ bridge | `EnterpriseDomain::createPurchaseDraft` | No (creates **PO** draft) | Out of goods-invoice scope | — |
| Import / external API | none found beyond AI tool | N/A | N/A | Future imports must call domain assert |
| Direct SQL | DBA | Yes | Outside app | Operational control |

## Implemented

- Company policy `erp_p2p_company_policy` (enforce goods match; allow service manual).
- Fail-Closed when module `p2p` enabled + enforce=1.
- Rejected bill amend with `erp_p2p_bill_revisions` history; same supplier invoice number.
- Policy exception audit table.
- Permission `accounting.purchase.exception` (seeded to owner).
- Order-line mutability guard API for pending/matched bills.
- Isolated MySQL concurrency/partial-bill tests (docker sandbox).

## Explicitly NOT done

- AP/GL posting remains disabled (draft only) — canonical posting kernel not proven for this slice.
- Payment / bank reconciliation.
- Production/staging cPanel deploy.
- Global PO line editor does not exist; guard is ready for future writers.

## Tests

- Source: `tests/test_p2p_hardening_1017.py`, updated `tests/test_p2p_three_way.py`
- MySQL: `tests/test_p2p_mysql_integration_1017.py` against disposable `erpsmart-p2p-mysql-test:3307`
