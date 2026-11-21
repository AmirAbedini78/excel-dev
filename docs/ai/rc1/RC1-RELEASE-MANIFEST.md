# RC1 Release Manifest

**Decision:** `RC1_ACCEPTED` on `main` — P1-001 Path2 data rehearsal PASS; P1-006 GHA SUCCESS on final SHA; local browser **LOCAL_BROWSER_VERIFIED**; staging browser optional P2  
**Production deployment:** **NOT_DEPLOYED** (forbidden this campaign)  
**Staging:** plan-ready; **not** `STAGING_VERIFIED` (remote staging auth not granted)

## Identity

| Field | Value |
|-------|--------|
| Repository | `AmirAbedini78/excel-dev` |
| Pre-closure `origin/main` SHA | `2a30f5abac6291af2e6d3ccf1f87446335c84bad` |
| Intermediate tips (historical) | PR #32 `52fa7f12…`; PR #35 `c5c644c…` |
| **Final `origin/main` SHA** | **`2c1cf6592f246553c66c3bc90d00385bf65d4c29`** |
| Closure merge | **PR #36** MERGED (`fix/rc1-final-legacy-data-ci-closure` → main) |
| Prior product / audit merges | PR #30, #31, #32 |
| Superseded late T1 PR | **#29 CLOSED** (superseded by PR #32) |
| Schema version (repo) | `10.24.0-rc1` (`RuntimeCache::SCHEMA_VERSION`) |
| Functional currency | IRR (Toman display opt-in) |

## Contract independent classes (pin)

| Contract | Class |
|----------|-------|
| TR-1 | PASS_REPRODUCED |
| TR-2 | PASS_REPRODUCED |
| MF-1 | PASS_REPRODUCED (lifecycle; WIP/FG valuation not fully supported; no auto GL) |
| SYS-1 | PASS_EXISTING_EVIDENCE_VERIFIED + LOCAL_BROWSER_VERIFIED (not STAGING_VERIFIED) |
| OPS-1 | PASS_PATH2_DISPOSABLE_REHEARSAL (`SCHEMA_UPGRADE_TESTED` disposable) |

## Runtime requirements

- PHP 8.2+ (local E2E uses Docker `erpsmart-o2c-php:8.2` + `pdo_mysql`; host PHP optional via Docker lint)
- MySQL 8.0 disposable for acceptance
- Python 3.12+ for gates
- Node 22 for JS syntax in commercial gate

## Source gate hashes / CI (final SHA)

| Check | Notes |
|-------|-------|
| Commercial MVP `release-gate` @ `2c1cf65…` | SUCCESS https://github.com/AmirAbedini78/excel-dev/actions/runs/44931684788 — **source-only / insufficient alone** |
| Mandatory RC1 MySQL+PHP @ `2c1cf65…` | SUCCESS https://github.com/AmirAbedini78/excel-dev/actions/runs/44931684872 |

**Note:** Commercial CI alone does **not** prove RC1 PHP+MySQL domain E2E. Local green ≠ GHA green.

## Staging upgrade / backup-restore

| Drill | Result |
|-------|--------|
| Privileged dump/restore + migrate×2 + rollback | **PASS** — `ops1_backup_restore_closure.json` (eng **CLOSED**) |
| Restore as restricted `p2p` without grant | **FAIL_ACCESS_DENIED_EXPECTED** |
| Prior deployed schema cumulative migrate | **PASS** Path2 anonymized data — `ops1_legacy_data_rehearsal.json` / `SCHEMA_UPGRADE_TESTED` disposable; no FRESH_INSTALL |

## MF costing / rollback caveats

- Additive RC1 schema only; no automated down-migration shipped.  
- Rollback = restore prior DB dump + prior code SHA; untested against production (none deployed).  
- Manufacturing **lifecycle** is tested (PASS_REPRODUCED).  
- WIP/FG valuation remains **not fully supported** / non-posting; do not expect GL FG entries from MF-1 (`UNVALUED` / `GL_BLOCKED`).  
- **No unsupported automatic WIP/FG GL** — policy locked in `T5-VALUATION-DECISION.md`.

## Zero production deploy statement

No cPanel, production DB, live bank/tax/Sayad, or production credentials were used. Local browser UAT is **LOCAL_BROWSER_VERIFIED** (`evidence/browser/`). Staging UAT and commercial go-live still require **explicit owner authorization**. See `RC1-STAGING-READINESS-FA.md`.
