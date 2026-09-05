# 23 — Cycle 12: Skills, Capability Retrieval & Persian NLP MVP

Status: `IMPLEMENTED-CANDIDATE / LIVE-VALIDATION-PENDING`
Baseline: `a15dfa30cae10f0adce8920c7fa7908ba2726a57`

## Why this cycle exists

Cycle 11 made Business Copilot globally usable across companies and Entity types. Live validation also exposed the next bottleneck: ERPSMART already owns many real Tools and deterministic domain engines, but users still need to phrase requests close to old router vocabulary. Follow-up questions also lacked bounded conversation history in the Worker context.

Cycle 12 implements roadmap **MVP B** rather than adding another isolated ERP feature.

## User-visible outcomes

1. Natural language stays primary.
2. Typing `/` opens a permission-filtered Skill picker.
3. `@` and `/` floating menus are clamped to the visible Sidecar area.
4. Explicit Skill selection inserts a Skill ID but never auto-runs it.
5. Customer comparison is deterministic across two attached Customer entities.
6. Supplier review joins Purchase Analytics + Party Ledger + Trade Case + Trade Risk.
7. Supplier comparison works across two attached Supplier entities.
8. Trade Risk can use an attached Trade Case and joins Snapshot + Risk + Landed Cost.
9. Inventory Risk and Executive Brief reuse the proven deterministic domain engines.
10. Follow-up questions such as `بر چه اساسی این نتیجه را محاسبه کردی؟` can inspect a bounded, same-user/same-company conversation history with prior route and Tool metadata.

## Capability retrieval contract

```text
Natural Persian request
→ explicit `/skill` if present
→ deterministic lexical/context retrieval
→ if still ambiguous: small local model chooses capability ID only
→ server-approved Tool descriptor narrowing
→ deterministic Skill fast-path when supported
→ existing constrained/guarded Agent otherwise
```

The local model is not allowed to create Tool arguments, IDs, amounts or business facts during capability selection. Its output is an enum of at most two registered capability IDs.

## Initial Skill Registry v1

- `customer-review`
- `compare-customers`
- `supplier-review`
- `compare-suppliers`
- `trade-risk`
- `inventory-risk`
- `executive-brief`
- `explain-previous`

The runtime Capability Retriever also knows bounded supporting capabilities such as Sales Fulfillment, Sales Margin, CRM Follow-up and CRM Pipeline so generic natural-language requests can receive a smaller relevant Tool set.

## Conversation history safety

Only the last 3 successful jobs of the same:

- workspace,
- user,
- company,
- conversation

are injected. Each prompt/result is length-bounded. Only safe Tool names and route mode are preserved from result metadata. Fresh ERP reads remain the source of current business truth; conversation history is for referent/explanation continuity and never grants authority.

## Read-only Skill evidence

### Customer Compare

`crm_customer_360` independently for both attached Customer IDs.

### Supplier Review / Compare

`document_analytics(purchases, confirmed, rolling 6 months, party_id)`
+ `party_ledger`
+ `search_trade_cases`
+ `trade_risk_summary`.

No overall "best supplier" score is invented when quality, return, payment-term or SLA evidence is unavailable.

### Trade Risk

For an attached Trade Case:

`trade_case_snapshot`
+ `landed_cost_summary`
+ `trade_risk_summary`.

For a broad company request, the proven global Trade Risk route is reused.

## Existing mutation safety

Prompts containing explicit write/action verbs bypass the new read-capability narrowing layer and continue through the existing Proposal/Human Approval routes. Cycle 12 introduces no new mutation Tool and no DB/schema migration.

## Live acceptance

After deploy:

1. Type `/` and verify Skills appear in categories.
2. Verify `/` and `@` popups stay inside the Sidecar at different viewport heights.
3. Attach two Customers and ask naturally: `این دوتا مشتری رو مقایسه کن`.
4. Attach one Supplier and ask: `این تامین‌کننده اخیراً خوب عمل کرده؟`.
5. Attach two Suppliers and ask: `کدوم تأمین‌کننده از نظر خرید و تاخیر وضعیت بهتری داشته؟`.
6. Attach a Trade Case and ask: `اگه این بار دیر برسه کجا ریسک داریم؟`.
7. Ask `بر چه اساسی این نتیجه را گفتی؟` after a grounded answer and verify previous Tool/mode explanation.
8. Use an existing write request and verify the old Proposal/Approval contract still owns execution.
9. Navigate between pages and verify the conversation remains company-scoped.

## Next roadmap gate

Once this cycle is live-proven, move to **MVP C — Role Brief / Intelligent Home**. The next product depth after that remains Trade Resilience: FX scenario, Landed Cost variance, shipment/customs risk, stock coverage, customer commitment, cash/margin exposure and CEO priority actions.


## r4/r5 validation closeout

Focused regression exposed two candidate issues during recovery validation:

- capability retrieval could double-count overlapping lexical phrases (for example a shorter phrase contained inside a longer matched phrase), causing a weak hint to bypass the bounded capability-ID model fallback;
- the Cycle 12 popup CSS asset was not loaded by the shared shell.

The retrieval score now suppresses nested duplicate phrase matches. The existing component-level Cycle 12 JavaScript injection remains unchanged, while `business-copilot-cycle12.css?v=10.9.0` is loaded once from the shared authenticated shell in `index.php`. This keeps the runtime change minimal and fixes viewport clamping without duplicate JavaScript loading.

## r6 safety hardening closeout

The read-path capability filter is fail-closed for mutation descriptors. Proposal/write tools are removed from every read-oriented descriptor set even when capability retrieval returns no match; explicit write requests still bypass the read filter and retain the existing Proposal/Approval flow. Strong lexical weighting is limited to an allowlist of domain phrases (for example `حاشیه سود`) so generic conversational phrases do not bypass the bounded capability-ID classifier.

### r6 pre-delivery validation

Before packaging r6, the Cycle 8 repository snapshot was reconstructed forward through the accepted Cycle 9, Cycle 10 and Cycle 11 payloads. Critical reconstructed Cycle 11 blob hashes were checked against GitHub commit `a15dfa30cae10f0adce8920c7fa7908ba2726a57`. The final Cycle 12 candidate then passed locally: Node syntax for both Copilot/AI live assets, PHP lint for all changed PHP files, 24/24 focused Cycle 12 contracts, and 183/183 full Python regression tests. The installer repeats the Python gates inside the Docker worker before rebuilding the Worker image.
