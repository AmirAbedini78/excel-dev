# RC1 Independent Verification

**Auditor:** Independent RC1 closure (post–PR #30)  
**Verified at (UTC):** 2026-11-20T17:15–17:35Z (gate) / docs 2026-11-20T17:45Z  
**Decision:** `RC1_BLOCKED`  
**Production:** `NOT_DEPLOYED`

## Git / release provenance

| Item | Verified value |
|------|----------------|
| `origin/main` SHA | `6da6a03796e1f89b5279424899adad5b5a23915c` |
| Local `main` | Same SHA; dirty only with this audit’s evidence/docs before commit |
| PR #30 | **MERGED** into `main` at `6da6a03…` ([PR](https://github.com/AmirAbedini78/excel-dev/pull/30)) |
| PR #30 CI | Commercial MVP Gate / `release-gate` **SUCCESS** — run [44770790058](https://github.com/AmirAbedini78/excel-dev/actions/runs/44770790058) (PR) / push [44770814148](https://github.com/AmirAbedini78/excel-dev/actions/runs/44770814148) |
| CI scope | **Source-only** (`scripts/release_gate.py`). Docker PHP+MySQL E2E **skipped** in Actions (no sandbox). Green CI ≠ RC1 acceptance. |
| `release/erpsmart-rc1` tip | `829770317c98bc220440551af38ecb856c8a4104` (parent of merge #30) |
| Prior handoff Final SHA | `c9f6eaf…` in `RC1-HANDOFF.md` — **stale** vs merge tip `6da6a03` |
| Schema constant | `RuntimeCache::SCHEMA_VERSION = 10.24.0-rc1` |
| Central wiring | `app/bootstrap.php` calls `InventoryDomain::migrate`, `ManufacturingExecutionDomain::migrate`, `CostingAccountingDomain::migrate`; `InventoryDomain` delegates WMS API to `WarehouseStockApi` |

## PR lineage (RC1 tasks)

| Task | PR | State | Notes |
|------|-----|-------|-------|
| T4 Iran-first | #19 | MERGED → release | Localization |
| T6 Platform UX | #20 | MERGED → release | Cockpits |
| T2 Warehouse | #21 | MERGED → release | Stock API |
| T5 Financial/costing | #22 | MERGED → release | WIP/FG GL **BLOCKED** by design |
| T1 Manufacturing | #23 | MERGED → release | MF-1 execution |
| T3 Trading | #24 | MERGED → release | Cockpit / TR-1 traceability |
| T7 QA gate | #25 | MERGED → release | `rc1_acceptance_gate.py` |
| Dup T3/T7/T2 | #26/#27/#28 | CLOSED | Superseded |
| Late T1 | #29 | **OPEN** vs `release/erpsmart-rc1` | Extra commits not required for gate PASS on main; leave open/out of scope |
| Release → main | #30 | MERGED | RC1 bundle |

## Independent acceptance re-run (mandatory gate)

Command (local, disposable sandbox):

```text
RC1_REQUIRED=1
O2C_DB_HOST=host.docker.internal O2C_DB_PORT=3307
O2C_DB_USER=p2p O2C_DB_PASS=*** O2C_DB_NAME=erpsmart_rc1_test
RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED
python scripts/rc1_acceptance_gate.py --strict-pass
```

| Contract | Gate script | Independent classification |
|----------|-------------|----------------------------|
| TR-1 | PASS (exit 0) | **PASS_REPRODUCED** — AP 6000 → settle 0; stock_movements=1; receipt_gl=0 |
| TR-2 | PASS | **PASS_REPRODUCED** — invoice 1000; receipts 400+600; outstanding 0 |
| MF-1 | PASS | **PASS_REPRODUCED** — FG/issue/QC; `cost_class=UNVALUED`; `gl_posting=BLOCKED` |
| SYS-1 | PASS (profile smoke) | **FAIL_REPRODUCED** vs strict matrix — no real login/UI+business action |
| OPS-1 | PASS (env UNAVAILABLE) | **BLOCKED_EXTERNAL** — provenance unresolved; browser NOT_RUN; restore incomplete |

Sandbox identity: container `erpsmart-p2p-mysql-test` id `02eb215a87f6…`, MySQL `server_uuid=a73a8a84-e4bd-11f1-ad33-7e073bee960b`, port `3307`, PHP image `erpsmart-o2c-php:8.2`.

## Local full `release_gate.py` (not CI)

`python scripts/release_gate.py --require-php --require-node` on HEAD → **FAILED** (453 tests, 2 failures, ~923s):

1. `test_manufacturing_mf1_php_e2e.ManufacturingMf1PhpE2e.test_06_concurrent_issue_two_orders` — both issues rejected (reservation-scoped); expected 1 success / 1 insufficient.
2. `test_trading_cockpit_php_e2e_rc1…test_tr1_exact_figures_and_cockpit_timeline` — Persian substring assert mismatch (`تکراری` / yeh encoding) despite duplicate error payload present.

CI does not execute these Docker E2E cases.

## Discrepancies vs prior RC1_ACCEPTED claim

1. Prior campaign marked OPS-1 **PASS** with `UNAVAILABLE_NOT_DEPLOYED` — **redefines DONE**; independent audit rejects that for OPS-1 completeness.
2. SYS-1 documented residual: browser deferred — fails strict SYS-1 (login/UI).
3. Six durable deliverables were incomplete before this audit; this verification adds them.
4. Handoff Final SHA not equal to `origin/main` merge SHA.

## Architecture spot-check

- No parallel stock/GL ledgers introduced for RC1 paths reviewed.
- T5 costing: WIP/FG automatic GL remains **BLOCKED** / UNVALUED when evidence missing — correct non-claim.
- T2 stock API wired through `InventoryDomain` + bootstrap migrate.

## Verdict rationale

Engineering domain scenarios TR-1/TR-2/MF-1 **reproduce** on disposable MySQL. Mandatory OPS-1 (deployed-schema provenance + staging browser + complete restore drill) and SYS-1 UI login **do not**. Therefore **`RC1_BLOCKED`**, `NOT_DEPLOYED`.
