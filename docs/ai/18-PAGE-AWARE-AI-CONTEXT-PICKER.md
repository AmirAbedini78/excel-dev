# ERPSMART v10.5 — Page-aware AI / Context Picker r1

Status: `PARTIAL`

## Product decision — 2026-08-31

Cycle 8 r1 proved a useful **Context Kernel primitive**, but its main product flow was not accepted.

Retained architecture:

```text
browser typed entity ref
→ server workspace/company/RBAC validation
→ canonical entity pointer
→ ai_jobs.context_json
→ Worker context transport
→ fresh Tool grounding
```

Retained safety:

- browser label/business facts are not authority;
- current business values are re-read from Tools;
- explicit customer/context mismatch fails closed;
- Proposal → Human Approval remains unchanged.

## Retired product UX

This flow is `RETIRED` as the intended UX:

```text
Customer 360
→ click “از AI درباره این مشتری بپرس”
→ leave current page
→ dedicated AI page with one attached chip
```

Reason: it does not solve universal entity selection, multi-entity context, persistent assistance or in-flow work. It would lead to per-page AI integrations and a fragmented UX.

## Retained code baseline

Commit:

```text
338e13419d091e6e1d3a5e7fd836ac7296e88e6b
```

Retain/refactor:

- `AiPageContext` typed-ref and validation concepts
- `AiRepository::queueChat()` context persistence
- `ai_jobs.context_json`
- Worker context consumption
- CRM deterministic context use tests

Do not expand `AiPageContext` by adding one hard-coded branch per module.

## Superseding architecture

The next implementation generalizes this kernel into:

```text
Universal Entity Registry
+ Context Resolver / Context Envelope v2
+ Global Business Copilot Sidecar
+ @ Mention/Search
+ multi-entity context
+ current-page awareness
+ Skill/Capability Registry
```

Canonical references:

- `19-ERPSMART-INTELLIGENCE-PLATFORM-MASTER-SPEC.md`
- `20-UNIVERSAL-BUSINESS-COPILOT-48H-MVP.md`

The original Cycle 8 Live Gate is cancelled as a product acceptance gate. Future tests may retain its safety assertions as regression tests for canonical context validation.
