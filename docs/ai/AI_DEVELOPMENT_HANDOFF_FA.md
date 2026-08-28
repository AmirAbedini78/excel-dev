# ERPSMART — AI Development Handoff

> سند فشرده برای AI/Developer جدید.
> قبل از استفاده، `00-START-HERE.md` مرجع اصلی ترتیب مطالعه است.

## Product

ERPSMART یک **Modular AI-Native Business Operations Platform** است. v9.3 هسته Finance AI را Live-validated کرد؛ v10 Platform را برای Pilot شرکت‌های بازرگانی B2B ماژولار و عملیاتی می‌کند.

هدف:
- ماژول‌های Business قابل فعال/غیرفعال در سطح Workspace
- سؤال/تحلیل/Forecast Grounded در هر Domain فعال
- Agent Action روی workflowهای پشتیبانی‌شده
- Automation/Proactive intelligence بین Finance/Sales/Inventory/Procurement/Trade
- Local-first AI با Provider قابل تعویض
- کاربر به‌تدریج Supervisor باشد نه data-entry operator

## Scope lock

Vertical اول: **Finance/Trade برای trading/import/distribution B2B**.

v10 توسعه Platform foundation + Module depth لازم برای Design Partner Pilot است. ERP کاملِ همه صنایع، Rewrite کامل Frontend، autonomous high-risk posting و model-training بزرگ بدون داده کافی خارج از Scope این Sprint هستند.

## Current baseline / phase

```text
Baseline: 7d4a804dc0330d297803707bb8c9a2d455dfc0db
Frozen milestone: v9.3 Commercial MVP — LIVE-VALIDATED / FEATURE FROZEN
Working milestone: v10.0 Modular Pilot Platform
Working status: IMPLEMENTED-IN-PROGRESS
Current cycle: Provider Gateway v1 — implementation/contract tests complete; local product smoke pending
Next: Finance Agent action depth → Inventory/Procurement/CRM-lite/Trade → Design Partner demo readiness
Canonical v10 contract: docs/ai/10-MODULAR-PILOT-PLATFORM.md
```

## Architecture

```text
cPanel:
UI/Auth/RBAC/MySQL/Queue/Tools/Approval/Audit

        ↑ outbound HTTPS

Docker Worker:
Python + Model Provider Gateway
Primary: Ollama
Optional: OpenAI-compatible cloud fallback / cloud-only second Worker
```

## Safety invariants

1. LLM direct SQL ندارد.
2. Current financial number از Tool deterministic می‌آید.
3. RAG ledger نیست.
4. LLM IDs نمی‌سازد.
5. Financial mutations Proposal/Approval دارند.
6. Retry نباید duplicate بسازد.
7. Tenant/company scope روی server validate می‌شود.
8. Deep analysis باید deterministic fallback داشته باشد.
9. Forecast number از LLM آزاد تولید نمی‌شود.
10. Cache فقط Plan؛ نه Answer مالی.

## Current proven paths

- deterministic report
- Safe Deep
- invoice proposal
- grounded reads
- parameterized analytics
- entity/status analytics
- multi-intent
- adaptive unknown-read planning
- constrained multi-step accounting workflows
- conditional receipt Proposal + Human Approval + Draft verification
- financial intelligence
- deterministic forecast/risk/anomaly
- proactive next-best-action recommendation
- commercial runtime/recovery/release guard (local candidate)

## Current implementation philosophy

Planner read multi-step است و Write را intercept نمی‌کند؛ write فقط از Guarded Proposal routes عبور می‌کند.

Example:

```text
Prompt
→ validated Plan
→ step dependencies
→ Tools
→ deterministic calculations
→ grounded response
```

v9.3 Feature جدید نیست و اکنون feature-frozen است. v9.3.0.1 route/model/latency/risk parity را اصلاح کرد؛ v9.3.0.2 Tool-name و attempted-metrics parity را بست؛ Job #54 و Voucher detail UI نیز Proposal → Approval → balanced Draft را در تجربه واقعی محصول بستند.

## Development workflow

قبل از Edit:
- read canonical docs
- inspect exact Git files
- identify scope/out-of-scope
- candidate-first tests
- exact changed file set

بعد از Edit:
- build/lint/unit/integration
- live test
- docs update
- exact staging
- commit only when validated

## Never repeat these mistakes

- Tool schema بزرگ به مدل ضعیف بدون routing
- آزاد گذاشتن LLM برای حساب کردن/ساخت عدد
- تفسیر «خرید > فروش» به عنوان زیان
- generated ERP IDs
- جواب cached مالی
- patch روی patch بدون prevalidation
- تغییر roadmap صرفاً بر اساس آخرین ایده

## v10 latest — Finance Action Depth

Cycle 2 is live-validated locally: Job #56 fixed named-party balance at 1.0s with no LLM. Cycle 3 adds Purchase/Cheque Finance building blocks, but the user clarified that receivable/debt examples were illustrative. The selected commercial narrative is now the end-to-end trading/import/distribution flow in `docs/ai/13-TRADE-FLOW-MVP.md`; next work crosses Inventory, Procurement, Trade/Logistics and Sales before broad CRM expansion.

## v10.1 Cycle 4 — Inventory + Procurement vertical slice

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`. Shared `InventoryDomain` now connects existing purchase documents to expected inbound, warehouse receipt/inspection, Stock Ledger, on-hand/reserved/available and replenishment reads. Risky receipt posting remains Proposal → Human Approval. See `14-INVENTORY-PROCUREMENT-MVP.md`. Context Picker / Entity Chips stays in committed UX backlog and will attach server-resolved page entities after the Golden Flow pages stabilize.
