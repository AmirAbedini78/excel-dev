# ERPSMART RC1 — Consolidated Handoff

**Verdict:** `RC1_ACCEPTED`  
**Release branch:** `release/erpsmart-rc1`  
**Final SHA:** `995ac8f083f6ffb5446d7c2def7d4c6c5410dc92`  
**Date:** 2026-11-20  
**Deploy:** `NOT_DEPLOYED` (cPanel/production forbidden this campaign)

## Go / No-Go (08-RC1-GO-NOGO-AND-SCHEDULE.md)

| Measure | Result |
|---------|--------|
| Acceptance contracts | **5/5 PASS** |
| P0 open | **0** (RC1-P0-001/002/003 RESOLVED) |
| P1 open | **0** (RC1-P1-001 closed — provenance `UNAVAILABLE_NOT_DEPLOYED`) |
| DATABASE_TESTED | **true** (real PHP `erpsmart-o2c-php:8.2` + MySQL sandbox) |
| Product deploy | **NOT_DEPLOYED** |

## Gate evidence

```text
RC1_REQUIRED=1
O2C_DB_HOST=host.docker.internal O2C_DB_PORT=3307
O2C_DB_USER=p2p O2C_DB_PASS=p2p_test O2C_DB_NAME=erpsmart_rc1_test
RC1_PROD_SCHEMA_PROVENANCE=UNAVAILABLE_NOT_DEPLOYED
python scripts/rc1_acceptance_gate.py --strict-pass
```

| Contract | Status | Evidence |
|----------|--------|----------|
| TR-1 | PASS | `docs/ai/rc1/evidence/tr1_acceptance.json` |
| TR-2 | PASS | `docs/ai/rc1/evidence/tr2_acceptance.json` |
| MF-1 | PASS | `docs/ai/rc1/evidence/mf1_acceptance.json`, `mf1_e2e.json` |
| SYS-1 | PASS | `docs/ai/rc1/evidence/sys1_acceptance.json` |
| OPS-1 | PASS | `docs/ai/rc1/evidence/ops1_acceptance.json` |
| Gate rollup | PASS=5 | `docs/ai/rc1/evidence/rc1_gate_report.json` |

Sandbox: container `erpsmart-p2p-mysql-test` id `02eb215a87f6…`, port `3307`. Isolated DBs per contract (`erpsmart_rc1_tr1_test`, `erpsmart_rc1_tr2_test`, `erpsmart_mf1_test`, `erpsmart_rc1_sys1_test`, `erpsmart_rc1_test`).

## Lane integration (merged into release)

| Lane | Merged PR | Notes |
|------|-----------|-------|
| T2 Warehouse stock API | [#21](https://github.com/AmirAbedini78/excel-dev/pull/21) | Critical path; `WarehouseStockApi` + `InventoryDomain` delegates |
| T5 Costing | [#22](https://github.com/AmirAbedini78/excel-dev/pull/22) | WIP/FG GL **BLOCKED** |
| T1 Manufacturing | [#23](https://github.com/AmirAbedini78/excel-dev/pull/23) | MF-1 execution + harness |
| T4 Iran-first | [#19](https://github.com/AmirAbedini78/excel-dev/pull/19) | Localization |
| T3 Trading | [#24](https://github.com/AmirAbedini78/excel-dev/pull/24) | Trading cockpit |
| T6 Platform UX | [#20](https://github.com/AmirAbedini78/excel-dev/pull/20) | Cockpits + safe AI |
| T7 QA gate | [#25](https://github.com/AmirAbedini78/excel-dev/pull/25) | `rc1_acceptance_gate.py` |

Duplicate late PRs `#26`/`#27`/`#28` superseded.

## Explicit non-claims / post-RC

- No taxpayer / Sayad / bank direct integrations.
- No statutory payroll; no full foreign FX/customs GL.
- No WIP/FG automatic GL valuation (BLOCKED by design).
- Commercial MVP source gate ? RC1 E2E proof.
- No production/cPanel deploy performed or authorized.
- Final merge of `release/erpsmart-rc1` ? `main` may require protected-branch review; user alone authorizes deploy.

## Worktrees

`C:\Projects\erp-worktrees\rc1-t{0..7}` — freeze new feature scope; QA/stabilize only.

## Sign-off

| Role | Decision |
|------|----------|
| T0 Integration | **RC1_ACCEPTED** |
| T7 Gate | 5/5 PASS, evidence under `docs/ai/rc1/evidence/` |
| User deploy | **NOT REQUESTED / NOT_DEPLOYED** |
