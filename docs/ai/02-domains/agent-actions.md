---
id: agent_actions
status: active
touches_code:
  - app/Core/AiToolRegistry.php
  - app/Core/AiRepository.php
  - engine/agent_guard.py
  - engine/worker.py
---

# Accounting Agent Actions

## Current proven action

Sales invoice Draft proposal.

## Invariants

- customer/item IDs only from tools
- quantities/prices must be grounded/validated
- no server-confirmation = no success claim
- proposal must exist before UI says proposal created
- approval is server-side
- final/post is not direct LLM action

## Next

Generalize orchestration after v8.8 read planner:

- action plan
- prerequisites
- dependency outputs
- proposal checkpoint
- resume
- verify

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
