# ERPSMART Capability Delivery Master

**Maintained:** 2026-11-20 · **NOT_DEPLOYED**
**Merged v10.20.1:** `b2cc0fb6fdda0608b5d6900f7a697e8976c582e1` (PR #13) · CI [44742035933](https://github.com/AmirAbedini78/excel-dev/actions/runs/44742035933)
**Merged v10.21.0:** `942d7765120637dd446e9d8611f373edc1edb1b3` (PR #14) · CI [44743805399](https://github.com/AmirAbedini78/excel-dev/actions/runs/44743805399)
**Docs SHA baseline:** `837a8918547876d8eca77eaab05fb99158d4346c` (PR #15)
**Next:** v10.22 AP merge → v10.23 supplier payment + domestic trading → v10.24 manufacturing costing spec

| Capability | Status | Notes |
|---|---|---|
| O2C | DATABASE_TESTED | PHP E2E |
| AR posting + integrity | DATABASE_TESTED | 10.19.x |
| Settlement + treasury integrity | DATABASE_TESTED | 10.20 / 10.20.1 |
| Business profiles (Iran-first) | FOUNDATION / SOURCE_TESTED | 10.21 |
| IRR ledger / Toman display | FOUNDATION | Explicit helpers |
| AP posting kernel | DATABASE_TESTED (sandbox) | 10.22 invoice-based; no GRNI; payment NOT_IMPLEMENTED |
| Supplier payment / AP settlement | NOT_STARTED | Target 10.23 |
| Trading vertical complete | PARTIAL | See roadmap 52; AP unlocks invoice→pay |
| Manufacturing vertical complete | CRUD_PILOT / PARTIAL | See roadmap 52 |
| VAT / taxpayer / Sayad | NOT_STARTED or DATA_MODEL | See matrix 53 |
| Bank reconciliation | NOT_STARTED | Never claimed by receipt post |
| PRODUCTION_VERIFIED | none | — |
| STAGING_VERIFIED | none | — |
