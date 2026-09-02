# 21 — Universal Business Copilot Foundation Candidate

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`
Baseline: `c443d7d362c1c053978c0aaed803a09c5eb9a10b`

## What this candidate proves

- one global Sidecar wired from the shared application shell;
- persistent open state + active conversation across page navigation;
- deterministic `@` Entity search with typed refs;
- multi-entity chips;
- server-side workspace/company/RBAC re-resolution;
- Context Envelope v2 separating `current_page` from `attached_entities`;
- LLM-free Entity preview;
- Customer 360 old page-jump CTA replaced with Sidecar attach;
- existing `ai_conversations`, `ai_jobs`, Worker transport and live polling reused;
- CRM worker accepts v2 context while retaining v1 compatibility.

## Initial providers

`party.customer`, `party.supplier`, `item`, `sales.document`, `purchase.document`, `trade.case`, `shipment`, `warehouse`, `finance.voucher`.

## Live acceptance

1. Open CRM Customer 360 and click `از Copilot درباره این مشتری بپرس`.
2. Sidecar opens without page navigation and shows the customer chip.
3. Type `@` and attach a second supported Entity.
4. Click a chip and verify Quick Preview.
5. Ask `وضعیت معاملاتمون با @کارخانه بهین بسته‌بندی چطوره؟` with exactly one Customer attached and verify the deterministic grounded Customer Business Review fast path.
6. Navigate to Trade/Inventory while the same conversation remains available.
7. From a different Customer page, explicitly attach another Customer and verify the explicit `@` selection outranks implicit current-page context.
8. Verify unrelated page context is not automatically converted into authority or an explicit attachment.

No DB migration is required for this candidate.

## Live Gate finding — Mention Search hotfix r2

Browser validation on baseline `e958dd9631671835cfadf778dbced57e50911c40` proved the global Sidecar and page-context UX, but typing `@` / `@کارخانه` produced no visible result. Cycle 10 therefore remains `LIVE-VALIDATION-PENDING`.

Hotfix contract:

- mention search shows immediate loading/error/no-result feedback instead of failing silently;
- one failing Entity provider cannot abort results from the other providers;
- UTF-8 query truncation has a safe fallback when PHP `mbstring` is unavailable on hosting;
- the browser asset URL is cache-busted to `business-copilot.js?v=10.7.1`;
- no DB migration or Worker mutation is required.

## Live Gate finding — PHP endpoint compatibility hardening r3-r5

The live endpoint still returned an unavailable state. The endpoint was made compatible with a wider PHP runtime range and the regression runner was corrected to use the canonical `engine/compose.yaml`. This hardening passed 147/147 full regression and 13/13 focused contracts, but live `@` search still did not return JSON to the Sidecar. PHP syntax compatibility was therefore not the root cause.

## Live Gate finding — Main-entrypoint API bridge + company context r6

Live validation after commit `8d3a2498edbb5d29fb3f2fd978d9d8f1aa20a2b5` showed:

- the `@` UI trigger is firing and displaying the loading/error state;
- the browser is still not receiving valid JSON from the standalone Copilot endpoint;
- `InventoryProcurementModule` has no company selector even though its data is company-scoped.

r6 contract:

- move Copilot API execution into `BusinessCopilotApi`, loaded from bootstrap;
- route the Sidecar through the already-proven main application entrypoint `index.php?copilot_api=1`;
- keep `copilot_api.php` as a compatibility wrapper only;
- JSON responses substitute invalid UTF-8, fail closed on encoding errors, emit bounded safe error codes and a request ID;
- browser errors show HTTP status / request ID without exposing server internals;
- restore a shared active-company selector to both Procurement and Inventory using the existing `acc-company-bar` UX and session-backed `AccountingRepository::companyId()`;
- company switching clears record-specific page state by redirecting to the selected module root;
- no DB migration, Worker mutation, schema change or new permission is introduced.

After r6 deploy, first verify `@` / `@کارخانه`, then change company independently on Procurement and Inventory, then proceed to multi-entity and Quick Preview acceptance.
