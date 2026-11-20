# ERPSMART HANDOFF — v10.18.0 Order-to-Cash

**NOT_DEPLOYED** — no cPanel / production DB / production migration.

## Baseline

- Verified main before branch: `f34c03a6bfcea9dd53a9a77abb40de91e9d55d08` (PR #5 + PR #6 merged)
- Feature branch: `feature/erp-o2c-v10.18.0`
- Schema: `10.18.0`

## Implemented vertical slice

1. Draft sales document (forced draft on create)
2. Maker/Checker confirm → `approved`
3. Reservation (no stock movement; approved/final only; scaled qty)
4. Delivery (stock out + reservation consume; idempotency; approved/final only)
5. Draft customer invoice from delivery with `erp_o2c_invoice_links`
6. AR posting **BLOCKED**; settlement **NOT_IMPLEMENTED**

## Docs

- Architecture + entry-point matrix: `docs/ai/40-O2C-ARCHITECTURE-1018.md`
- Capability matrix: `docs/ai/ERP-CAPABILITY-DELIVERY-MASTER.md`

## Tests

- `tests/test_o2c_contracts_1018.py`
- `tests/test_o2c_mysql_integration_1018.py`
- P2P regression suites retained

## Next safe phase (v10.19)

Prove accounting posting kernel (mappings, balanced voucher, fiscal open, idempotent AR) before settlement.
