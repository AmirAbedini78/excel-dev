# 00-START-HERE — ERPSMART AI Development Entrypoint

> این فایل نقطه شروع اجباری هر Session توسعه است.

## ترتیب مطالعه

قبل از طراحی یا تغییر کد:

1. `00-GOVERNANCE.md`
2. `01-NORTH-STAR.md`
3. `02-CURRENT-STATE.md`
4. `03-ARCHITECTURE.md`
5. `04-ROADMAP.md`
6. Domain مرتبط از `02-domains/INDEX.yml`
7. در صورت عملیات/تحلیل AI: `05-WORKFLOW-CONTRACTS.md`
8. قبل از تحویل: `06-TEST-RELEASE-PROTOCOL.md`
9. برای Commercial MVP: `09-COMMERCIAL-MVP-RELEASE.md`
10. برای v10 Platform: `10-MODULAR-PILOT-PLATFORM.md`
11. برای Providerها: `11-MODEL-PROVIDER-GATEWAY.md`
12. برای عمق Finance/Action: `12-FINANCE-CAPABILITY-MATRIX.md`
13. برای Golden Flow بازرگانی: `13-TRADE-FLOW-MVP.md`

برای انتقال سریع Context به AI جدید، بعد از موارد بالا این فایل را بخوان:

```text
AI_DEVELOPMENT_HANDOFF_FA.md
```

## دستور استاندارد برای AI/Developer

قبل از کدنویسی باید صریحاً مشخص شود:

```text
Current baseline:
Current phase:
Requirement:
In scope:
Out of scope:
Affected contracts:
Risk:
Success criteria:
Files expected to change:
Tests required:
```

اگر Requirement کاربر با `01-NORTH-STAR.md` یا Scope فعلی تعارض داشت، بدون ساختن هدف جدید یا حدس زدن Roadmap، تعارض را اعلام کن و فقط پس از تصمیم صریح کاربر سند North Star/Roadmap را تغییر بده.

## قانون Source of Truth

ترتیب اولویت:

```text
Repository code + tests
        ↓
Live/runtime evidence
        ↓
docs/ai canonical docs
        ↓
legacy docs
        ↓
conversation history
```

History برای فهم علت تصمیم‌هاست، نه جایگزین وضعیت فعلی سورس.

## Current snapshot

- Repository: `AmirAbedini78/excel-dev`
- v9.3 final baseline: `5a4474dfb7a429c526fb68e9b55b0d8b6c982411` — `LIVE-VALIDATED / FEATURE FROZEN`.
- Proven financial write evidence: Job #54 → Proposal #3 → Human Approval → balanced draft `AI-VCH-20260826-202025-9F19` → exact product UI article verification PASS.
- Current milestone: **v10.0 — Modular Pilot Platform**.
- Current scope: **Modular AI-Native Business Operations Platform**; first commercial vertical = Finance/Trade for B2B trading/import/distribution companies.
- Current sprint: Module Kernel **LIVE-VALIDATED** → Provider Gateway **LIVE-VALIDATED on local path (Job #56)** → Finance Agent depth → Inventory/Procurement/CRM-lite/Trade slices → Design Partner demo readiness.
- v9.3 safety invariants remain frozen: current facts from deterministic Tools, no model-generated ERP IDs, Proposal/Approval for risky mutation, RAG is not current ledger.
- Canonical v10 contracts: `10-MODULAR-PILOT-PLATFORM.md` + `11-MODEL-PROVIDER-GATEWAY.md` + `12-FINANCE-CAPABILITY-MATRIX.md` + `13-TRADE-FLOW-MVP.md`.

## v10.1 Cycle 4 — Inventory + Procurement vertical slice

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`. Shared `InventoryDomain` now connects existing purchase documents to expected inbound, warehouse receipt/inspection, Stock Ledger, on-hand/reserved/available and replenishment reads. Risky receipt posting remains Proposal → Human Approval. See `14-INVENTORY-PROCUREMENT-MVP.md`. Context Picker / Entity Chips stays in committed UX backlog and will attach server-resolved page entities after the Golden Flow pages stabilize.

## v10.2 Cycle 5 — Trade Logistics + Landed Cost

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`. Cycle 4 is now `LIVE E2E PASS` through Job #70 and receipt `RCV-20260829-024216-D32F`. Cycle 5 adds Trade Case → Shipment → ETA/Customs → Estimated/Actual Trade Costs → deterministic Landed Cost allocation → inventory valuation bridge. AI mutations remain Proposal → Human Approval. See `15-TRADE-LOGISTICS-LANDED-COST.md`.
