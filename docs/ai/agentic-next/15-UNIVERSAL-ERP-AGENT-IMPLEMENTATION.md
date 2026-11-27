# Universal ERP Agent — Foundation Patch v1

**Patch baseline:** `171d4b17c376529ac4c779b257e5c1ca90444643`  
**Purpose:** expose the existing typed Suite and Enterprise operational domains to the AI control plane through a bounded, permission-aware tool contract.

## Included in this patch

- Adds `AiUniversalOperations` as an adapter over existing `ErpSuiteDomain`, `EnterpriseDomain`, and `AiEntityRegistry` services.
- Adds catalog, search, read, sampled operational report, navigation, and human-approved mutation proposal tools.
- Supports typed create/update/archive for existing Suite and Enterprise records; Enterprise records additionally support their existing controlled status transitions. Suite records with an approval workflow can be reviewed only through their existing approval state.
- Resolves reference labels to a unique company-owned reference before proposal storage.
- Adds allowlisted SQL search over schema-declared Suite columns, snapshot-based stale-write detection, and a dependency guard for selected Suite archive operations.
- Revalidates company scope, module state, permissions, typed field validation, record revision/snapshot, and transition rules when a proposal is executed.
- Keeps generic mutation behind `ai_action_proposals` and the existing human approval route.
- Makes the existing Enterprise transaction methods composable inside the current proposal-approval transaction.
- Adds worker routing for the generic proposal tool only on explicit write intents; existing financial/stock proposals remain on their dedicated guarded paths.

## Explicit boundaries

This is a foundation patch, not a claim that every ERP business vertical is complete.

- Core accounting, sales, purchase, treasury, inventory posting, stock movement, and trade posting must continue to use their dedicated domain tools. They are not exposed through arbitrary generic CRUD.
- Archive is not physical deletion. Posted financial documents require their domain-specific reversal/cancellation workflow.
- Operational reports are sampled and clearly marked as such. They are not a substitute for ledger-grade financial reports.
- The current repository's Enterprise HR records are not a payroll engine. Iranian payroll, tax, insurance, attendance-to-payroll calculations, and statutory reporting need a separate validated business domain before the agent may claim those capabilities.
- Metadata-only documents and signature requests do not imply file storage, OCR, cryptographic e-signatures, or external delivery.
- Approval of an Enterprise record does not imply GL posting, stock movement, payment, salary payment, or external message delivery.

## Required follow-up before production

1. Cursor must inspect all current domain specs and complete module-by-module coverage, especially canonical master-data update flows, sales/purchase edit/reversal, treasury settlement, production execution/costing, payroll gaps, and workflow integration.
2. Add database-backed integration tests for every create/update/archive/transition/review route, including cross-company IDs, disabled modules, missing permissions, ambiguous references, stale revisions, concurrent edits, duplicate retries, and maker/checker.
3. Add model-provider contract tests for Grok and Gemini, Persian synonym/intent routing, tool selection, ambiguity clarification, and unsupported-operation refusal.
4. Add browser tests proving navigation URLs open the exact page/record and the UI shows the proposal preview, approval actor, executed result, and audit evidence.
5. Run the complete existing test suite and PHP/Python release gates. Do not mark any module complete on static tests alone.
6. Verify deployment on a separate cPanel staging subdomain and a cloned MySQL database before production.

## Patch verification

- `php tests/php_universal_ai_agent_contract_test.php` — static wiring contract only (also invoked from `scripts/release_gate.py` when PHP CLI is present).
- `python -m unittest tests.test_universal_ai_agent_routing -v` — static routing + adversarial non-destructive SQL / guarded financial proposals.
- Audit matrix: `16-UNIVERSAL-ERP-AUDIT-MATRIX.md`.
- `php -l` on changed PHP files.
- `python -m compileall engine` or the repository's Python release gate.

A passing result here does not prove DB-level or model-level behavior. The release must remain `NOT_PRODUCTION_VERIFIED` until staging acceptance is recorded.

## Tools added

| Tool | Mode | Notes |
|------|------|-------|
| `erp_entity_catalog` | read | Suite + Enterprise + Core catalog |
| `erp_entity_search` | read | company-scoped |
| `erp_entity_read` | read | unique resolve; ask if ambiguous |
| `erp_entity_report` | read | sampled allowlisted aggregations |
| `erp_navigation_resolve` | read | deep link |
| `erp_entity_history` | read | Enterprise events only |
| `propose_erp_operation` | proposal | create/update/archive/transition/review |

Authority remains `AiToolRegistry`; `AiUniversalOperations` is adapter-only.
