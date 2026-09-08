# 25 — Cycle 12.2 Agent Core Rescue / MVP-B Intelligence Closeout Candidate

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`

Baseline:

```text
8478459063cd49f1eec77d67273ad74ab6ac6f76
```

## Why this increment exists

Cycle 12 and 12.1 proved universal `@` context, `/` Skill discovery, bounded capability retrieval and grounded deterministic reads, but live acceptance exposed two MVP blockers:

1. several business questions still behaved like fixed mini-reports rather than a controlled reasoning workflow over typed ERP evidence;
2. the Sidecar composer became a clipping context for `@` and `/` menus, and long conversations could make the composer difficult to reach.

This increment is not a new feature detour. It rescues the D2–D4 MVP-B contract: **bounded Supervisor → deterministic domain reads → structured Evidence Pack → analysis model → grounded answer**, while preserving every existing Proposal/Approval safety path.

## Architecture lock

```text
Natural Persian / explicit Skill
        ↓
server-validated company + @ entities
        ↓
Capability / intent gate
        ↓
Bounded Business Supervisor
        ↓
server-owned typed read Tool arguments
        ↓
Cross-module deterministic evidence Tool
        ↓
Structured Evidence Pack with Evidence IDs
        ↓
Analysis model (qualitative reasoning only)
        ↓
claim ↔ Evidence-ID validation
        ↓
Grounded response / deterministic fallback
```

The model does not create database IDs, Tool names, SQL, dates, quantities, amounts or write arguments. A model claim is accepted only when it cites existing Evidence IDs and any numeric tokens in that claim are present in the cited facts.

## New read Tools

### `supplier_performance_summary`

Cross-module deterministic Supplier evidence from:

- confirmed goods purchase documents;
- ordered / accepted / rejected / open quantities;
- receipt fill rate and acceptance rate where evidence exists;
- Trade Case count and risk;
- recorded delay samples / on-time rate when ETA + ATA exist.

It deliberately does **not** manufacture a generic quality score. Missing SLA, response-time, price normalization or quality evidence is surfaced as a limitation.

Without explicit Supplier `@` refs, the bounded portfolio candidate set is the highest confirmed-purchase suppliers in the period; this selection scope is disclosed in the evidence limitations.

### `shipment_commitment_impact`

Deterministic cross-module relation:

```text
Trade Case / selected Shipment
→ Purchase lines
→ Item IDs
→ current Inventory position
→ open approved/final Sales lines
→ reservation / fulfillment coverage
→ affected Sales documents / customers
```

The Tool returns **exposure**, not invented causality. A sales commitment is treated as dependent on future inbound only when the uncovered unreserved quantity exceeds current available stock. If a specific Shipment is selected, its identity and delay are preserved instead of silently substituting another Shipment from the same Trade Case.

## Supervisor scope

Cycle 12.2 intentionally handles only the two current MVP-B killer workflows:

1. Supplier performance / Supplier comparison;
2. Shipment or Trade Case delay impact on known Sales commitments.

All other existing Cycle 12 paths continue through the prior capability/Skill stack. Accounting multi-step reads continue through the proven constrained `workflow_planner.py`; existing guarded writes remain authoritative and bypass this rescue layer.

This keeps the increment small enough for live validation while establishing the Evidence-Pack pattern that later Skills can adopt.

## Model policy

The Worker tries the configured analysis role first, then the configured fallback role if distinct. Structured JSON is required. The response schema binds every summary/finding/action to one or more Evidence IDs.

If model output fails JSON/schema/provenance validation, the deterministic Evidence Pack is still returned and a trace records the reason. No business fact is lost because model synthesis failed.

A packaged runtime preflight (`cycle12_mvp_b_smoke.py`) verifies that the actual installed local analysis/chat model can produce the required structured output before live acceptance.

## Sidecar UX fix

`@` and `/` menus are moved into a dedicated non-scrolling Sidecar overlay layer. They are no longer descendants of the scrollable composer, so CSS overflow cannot clip them regardless of z-index. The thread owns the flexible grid row and remains independently scrollable; the composer remains reachable.

## Safety / unchanged boundaries

- no DB/schema migration;
- no direct SQL from Worker or LLM;
- new Tools are read-only;
- server owns company scope and Tool arguments;
- attached entities remain server-canonical Context Envelope refs;
- write requests bypass Business Supervisor and keep Proposal → Human Approval;
- cross-workspace/company safety contracts remain unchanged;
- no RAG, graph DB, multi-agent, fine-tuning or broad autonomous execution enters the MVP path.

## Evaluation gate

This increment adds a first semantic MVP-B fixture with natural Persian questions covering:

- Supplier review and comparison;
- Shipment → Sales commitment impact;
- Trade Risk;
- Customer review/compare;
- Inventory risk;
- explain-previous;
- write bypass.

The semantic fixture is a routing/contract seed, not the final production evaluation platform. Live failures must be promoted into this benchmark before later optimization.

## Local candidate verification

Required before packaging:

```text
Cycle 12.2 focused tests
Cycle 12 compatibility tests
full test_*.py regression
PHP lint
Node syntax
Commercial MVP release_gate.py --require-php --require-node
Docker image source/import audit
```

Real Docker Compose, the actual installed Ollama model preflight and Worker runtime smoke execute on the target Windows/Docker environment through the installer because Docker/PowerShell are not available in the assistant build environment.

## Live acceptance gate

Do not close Cycle 12.2 until these pass in the deployed product:

1. `کدوم تامین‌کننده عملکرد بهتری داشته؟` — no `@`; returns bounded Supplier evidence + actual model synthesis or explicit model fallback reason.
2. Two Supplier `@` refs — comparison uses only those canonical IDs.
3. `اگر @Shipment-X دیر برسد، چه تعهدهای فروش شناخته‌شده‌ای تحت تأثیر قرار می‌گیرند؟` — shows Purchase→Item→Inventory→Sales exposure and customers.
4. Explain a follow-up without fabricating evidence.
5. `@` and `/` menus remain visible above the composer in old/long conversations.
6. Long thread scroll keeps the composer/send button reachable.
7. An explicit write request still goes through the existing Proposal/Approval flow.

After this live gate passes, Cycle 12/MVP-B is closed and the roadmap advances to **MVP C — Role Brief / Intelligent Home**, not another UI-polish cycle.
## Runtime model routing hardening (r2 recovery)

The first live preflight proved that a cold `analysis_model` can exceed a fixed 90-second one-off HTTP check even while the rest of the 208-test candidate is valid. The recovery preflight now uses the exact Worker `ollama_chat` transport, the same 150-second analysis budget used by the Supervisor, then a 120-second configured fallback. The first model that proves structured-output compatibility is persisted only in the existing Worker data volume (`/app/data/cycle12_analysis_route.json`) and is preferred by the bounded Supervisor. Repository configuration and business truth are not mutated.

## Live Gate correction — v10.9.3

Live validation after `ded6a88` exposed three product-blocking gaps and they are treated as correctness, not cosmetic polish:

1. Supplier Performance used a narrower hard-coded `doc_type` set than canonical `document_analytics`, causing false `no_confirmed_purchase_history`. The source now uses the canonical confirmed semantics (`approved + final`) across all purchase documents; receipt/acceptance metrics remain limited to physical-item lines.
2. Trade overview can resolve “this shipment/case” only when exactly one active Trade Case exists in the current company; multiple active cases still require explicit `@` selection.
3. Company Trade Risk now uses the same Structured Evidence + bounded reasoning path instead of the legacy text-synthesis path.
4. Sidecar uses a fixed-height flex shell: header/scope/composer are pinned, the thread alone expands/scrolls, and `@`/`/` remain in the overlay layer.

No write boundary, schema, migration, RAG, or autonomy scope changed.

## Runtime route hardening (v10.9.3 r6)

The model proven by the local structured-output preflight is only a preference while it remains one of the Worker's currently configured `analysis` / `fallback` models. Stale runtime route metadata is ignored so it can never displace the configured fallback after a model/config change.
