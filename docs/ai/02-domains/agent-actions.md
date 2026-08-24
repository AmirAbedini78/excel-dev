---
id: agent_actions
status: LOCAL-VALIDATED
touches_code:
  - app/Core/AiToolRegistry.php
  - app/Core/AiRepository.php
  - engine/agent_guard.py
  - engine/action_orchestrator.py
  - engine/commercial_hardening.py
  - engine/worker.py
---

# Accounting Agent Actions

## Current proven actions

- Sales invoice Draft Proposal؛
- conditional grounded receipt → balanced voucher Draft Proposal؛
- Human Approval → deterministic Draft creation؛
- post-action confirmed/final verification.

## Invariants

- customer/item IDs only from tools
- quantities/prices must be grounded/validated
- no server-confirmation = no success claim
- proposal must exist before UI says proposal created
- approval is server-side
- final/post is not direct LLM action
- concurrent retry returns the same Proposal ID
- terminal response retry cannot execute completion side effects twice

## v9.3 commercial hardening

- sales-invoice Proposal = medium risk؛
- voucher Proposal = high risk؛
- both require `ai.actions.approve` and explicit Human Approval؛
- atomic idempotency key prevents duplicate Proposal؛
- same terminal response can be replayed for delivery recovery؛
- UI shows Proposal ID, localized risk/status and explicit confirmation.

General financial orchestration beyond the proven receipt slice remains deferred until after Commercial MVP freeze decision.

## Target examples

```text
برای مشتری X از کالای Y فاکتور Draft بساز.
```

Later:

```text
مانده X را بررسی کن؛ اگر شرط مشخص برقرار بود، سند Draft مناسب آماده کن.
```

## Deferred

- unrestricted form automation
- blind UI clicking
- direct DB mutation
- auto-finalization of high-risk financial documents
