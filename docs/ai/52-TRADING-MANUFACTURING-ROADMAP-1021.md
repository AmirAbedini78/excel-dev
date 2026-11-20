# Trading & Manufacturing Dependency Roadmap (v10.21)

## Trading — domestic
| Step | Status | Evidence / gap |
|---|---|---|
| Purchase request / RFQ | PARTIAL / CRUD | Enterprise/workflow pilots |
| PO → receipt → match | DATABASE_TESTED | P2P v10.16/10.17 |
| Supplier invoice / AP GL | NOT_STARTED / BLOCKED | No AP posting kernel yet |
| Payable → treasury pay | NOT_STARTED | After AP kernel |

## Trading — foreign
| Step | Status | Gap |
|---|---|---|
| Trade case / shipment / costs | INTEGRATED_DRAFT / DATABASE_TESTED (sandbox) | TradeDomain landed cost |
| Customs / FX settlement | PARTIAL | No verified FX posting kernel |
| Landed cost → inventory valuation | PARTIAL | SalesDomain uses TradeDomain costs |

## Manufacturing
| Step | Status | Gap |
|---|---|---|
| BOM | CRUD_PILOT | Production module |
| MRP-lite | DATA_MODEL / PILOT | Incomplete demand netting |
| Production order / issue / FG | CRUD_PILOT | Costing not DATABASE_TESTED as full cycle |
| QC / waste / maintenance | NOT_STARTED | — |

## Recommended next engineering slices
1. AP posting kernel mirroring AR integrity (after staging AR/settlement smoke).
2. One complete domestic trading path: PO → receipt → AP voucher → payment allocation.
3. One manufacturing pilot: BOM → issue → FG receipt → WIP/FG valuation with tests.
