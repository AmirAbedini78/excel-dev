# Cycle 12.1 — Live Intelligence Hardening

Status: candidate for live acceptance after Cycle 12 infrastructure passed but live intelligence quality did not.

## Why this exists

Cycle 12 proved that the Universal Copilot shell, workspace-wide `@` entities, `/` Skill picker, company-scoped conversations, grounded customer comparison, trade-risk reads, and bounded conversation history work in production. Live testing also exposed four MVP-blocking gaps:

1. direct Skills often returned deterministic text without any analysis-model synthesis;
2. natural supplier questions such as «کدوم تأمین‌کننده عملکرد بهتری داشته؟» still demanded explicit `@` entities;
3. `explain-previous` rendered literal `\\n` escape sequences;
4. the Sidecar composer could become hard to reach on short/zoomed viewports.

This hardening is intentionally inside MVP B. It is not a roadmap expansion and must be completed before Role Home / MVP C.

## Runtime contract

The read path is now:

`Natural Persian -> Capability Retrieval -> deterministic ERP reads -> Grounded Evidence -> analysis model -> qualitative findings/actions -> Evidence`

The analysis model is never the source of commercial numbers. Exact numbers, dates, IDs, percentages and amounts come only from deterministic ERP tools. The synthesis response is schema-constrained and rejected if it emits any numeric token. On rejection/provider failure, the deterministic evidence remains the user-visible fallback.

Write paths remain unchanged and continue through the existing Proposal -> Human Approval boundaries.

## Supplier Portfolio Intelligence

When no supplier is attached, `supplier-review` / `compare-suppliers` now discovers candidates from confirmed purchase history using `document_analytics(group_by=party)` and enriches a bounded candidate set with Party Ledger, Trade Cases and Trade Risk. Two explicitly attached suppliers are still compared directly. One attached supplier is reviewed directly.

This removes the requirement to know entity names before asking a natural portfolio question while still refusing to invent quality/SLA/returns KPIs that are not present in ERP.

## UX hardening

The Sidecar is constrained to the dynamic viewport height. The thread owns the flexible vertical space and scrolls independently; composer and preview are bounded. Cycle 12 CSS/JS assets are cache-busted to `v10.9.1`, and the JS fallback stylesheet loader now detects the stylesheet already loaded by `index.php` instead of injecting a duplicate copy.

## Acceptance gate

Live acceptance requires all of the following:

- natural supplier portfolio question works with no `@`;
- two attached suppliers compare without a single-supplier block;
- customer/trade/supplier direct Skills include a qualitative `تحلیل هوشمند روی شواهد ERP` section when an analysis model/provider is available;
- deterministic evidence survives model failure or numeric-guard rejection;
- explain-previous renders real line breaks;
- Sidecar thread scrolls and Send remains reachable at normal zoom and short viewport heights;
- no write/proposal descriptor leaks into read analysis.
