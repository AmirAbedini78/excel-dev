# ERPSMART HANDOFF — v10.16.0 P2P Three-Way Matching

Machine-readable companion to the chat HANDOFF REPORT. Generated on system-2.

## 1. Environment

| Field | Value |
|---|---|
| System | system-2 (Windows user `h`) |
| Repo | `C:\Projects\excel-dev` |
| Branch | `feature/erp-p2p-three-way-v10.16.0` |
| Feature commit (18 patch files) | `5da53bf2405e11aa5937db59262a5d97adc50a93` |
| Branch HEAD (includes docs) | `12f280809990f0a449f38c590cbe98bd09df01a6` |
| origin/main (at cut) | `aed25a29afe6862e291b4b7c87f82cfdeee5ba13` |
| Working tree | Clean after docs commit |

## 2. Version / release

| Field | Value |
|---|---|
| Baseline | v10.15 merged via PR #4 |
| Release | **10.16.0** P2P Three-Way Matching pilot |
| Installer backup | `C:\Projects\excel-dev-patch-backups\v10.16.0-p2p-before-20261119-225105-0a507f` |
| Patch integrity | PASS — 18 files match installer manifest SHA256 |
| PR | https://github.com/AmirAbedini78/excel-dev/pull/5 |
| CI (feature commit) | success — https://github.com/AmirAbedini78/excel-dev/actions/runs/44601189666 |
| CI (branch HEAD incl. docs) | success — https://github.com/AmirAbedini78/excel-dev/actions/runs/44601762317 |
| Deploy | **NOT_DEPLOYED** (no cPanel, no production DB migration) |
| Merge | **NOT MERGED** (awaits user approval) |

## 3. Scope actually implemented

### Domain (`PurchaseMatchingDomain`)
- Additive migrate: `erp_p2p_vendor_bills`, `erp_p2p_vendor_bill_lines` with unique keys for supplier invoice, canonical doc, bill line.
- `approveOrder` — maker cannot approve own PO.
- `submit` — pending vendor bill; idempotent same payload; reject duplicate number with different hash; qty capped by `min(ordered, accepted) - already_matched`; unit price must match PO (no tolerance); discount/tax lines blocked for simple match.
- `approve` — second actor; locks PO then bill `FOR UPDATE`; re-validates match; inserts **draft** `purchase_invoice_goods` + lines; links `canonical_doc_id`; **no GL, no payment, no stock move**.
- `reject` — second actor + reason length bounds.

### Module / UI
- Opt-in module `p2p` in `ModuleRegistry` (depends finance + procurement).
- Page `index.php?page=p2p`; actions `p2p_*` routed in `index.php`.
- Inventory search/inbound excludes matched P2P canonical invoices.
- Accounting delete path blocks docs with P2P dependencies.

### What was NOT implemented
- Global fail-closed three-way on all invoice entry paths.
- Rejected-bill correction with same supplier invoice number.
- AP/GL posting, payment, bank reconciliation.
- MySQL live concurrency suite on system-2.

## 4. Capability matrix delta

| Capability | Before (main) | After (this branch, evidence-based) |
|---|---|---|
| P2P three-way matching | `NOT_STARTED` | `TRANSACTIONAL_TESTED` (source + local release_gate + GitHub Actions release-gate success on branch HEAD) |
| Manual invoice bypass | Present | Still present — documented Gap for v10.17 |
| Enterprise suite | As PR #4 | Unchanged by this PR |

Master matrix: `docs/ai/ERP-CAPABILITY-DELIVERY-MASTER.md`.

## 5. Tests

| Command | Exit | Result |
|---|---|---|
| `py -3.13 scripts\release_gate.py` | 0 | Ran **337** tests; **OK (skipped=6)**; JS syntax PASS; PHP lint **SKIPPED** (no PHP CLI) |
| `php tests\php_p2p_contract_test.php` | N/A locally | Deferred to CI `--require-php` |
| MySQL integration / concurrency | NOT_TESTED | No isolated Docker MySQL used; production DB untouched |
| Browser E2E | NOT_TESTED | — |

Skipped locally must not be reported as proven.

## 6. Safety proof / unproven

**Proven in source review + contract/unit style tests:** workspace/company scoping in P2P SQL; maker-checker on order approve and bill approve/reject; idempotent supplier invoice + approve re-entry; PO lock ordering notes; draft-only invoice output; inbound exclusion for matched invoices.

**NOT proven on system-2:** live MySQL race of dual approve; staging migrate smoke; production isolation with companies A/B; CI PHP green until Actions completes.

## 7. Migration

- New tables: `erp_p2p_vendor_bills`, `erp_p2p_vendor_bill_lines`.
- First web request after deploy runs migrate via bootstrap (additive `CREATE TABLE IF NOT EXISTS`).
- Preflight read-only: `scripts/p2p_preflight.php` (not deployed by cPanel docroot design).
- File rollback ≠ drop tables. Backup MySQL before any staging/production source deploy.

## 8. Deployment

**NOT_DEPLOYED.** Do not publish to cPanel until user authorizes + staging backup + preflight + smoke.

## 9. Risks / next slice

1. **High:** Manual accounting invoice bypass of three-way — fix in v10.17 policy Fail Closed.
2. **Medium:** Concurrent PO line edits outside P2P path — MySQL concurrency tests + global guards.
3. **Medium:** Rejected bill cannot reuse invoice number — revision/amend path.
4. **Process:** Wait for CI green; user merges PR #5; only then start v10.17 vertical slice on updated main.

## 10. Exact next actions for user

1. Review PR: https://github.com/AmirAbedini78/excel-dev/pull/5
2. Confirm CI green on HEAD: https://github.com/AmirAbedini78/excel-dev/actions/runs/44601762317
3. If accepted: merge PR yourself (Cursor will not merge); then authorize staging DB backup before any deploy. **Do not deploy production yet.**
