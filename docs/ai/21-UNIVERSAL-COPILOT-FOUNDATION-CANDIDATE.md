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
