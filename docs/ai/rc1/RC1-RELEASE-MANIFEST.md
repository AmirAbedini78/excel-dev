# RC1 Release Manifest

**Decision:** `RC1_BLOCKED` — engineering P1-005 closed on Final main via PR #32; **acceptance incomplete** (browser + provenance + GHA MySQL workflow)  
**Production deployment:** **NOT_DEPLOYED** (forbidden this campaign)

## Identity

| Field | Value |
|-------|--------|
| Repository | `AmirAbedini78/excel-dev` |
| Pre-closure `origin/main` SHA | `2a30f5abac6291af2e6d3ccf1f87446335c84bad` |
| **Final `origin/main` SHA** | **`52fa7f12f465653d6e445d484f1a8dda48215f45`** |
| Closure merge | **PR #32** MERGED (`fix/rc1-stabilization-p1-closure` → main) |
| Prior product merge | PR #30 |
| Prior audit docs merge | PR #31 |
| Superseded late T1 PR | **#29 CLOSED** (superseded by PR #32; lock/DDL ideas absorbed) |
| Schema version (repo) | `10.24.0-rc1` (`RuntimeCache::SCHEMA_VERSION`) |
| Functional currency | IRR (Toman display opt-in) |

## Contract independent classes (pin)

| Contract | Class |
|----------|-------|
| TR-1 | PASS_REPRODUCED |
| TR-2 | PASS_REPRODUCED |
| MF-1 | PASS_REPRODUCED (lifecycle; WIP/FG valuation not fully supported; no auto GL) |
| SYS-1 | BLOCKED_EXTERNAL |
| OPS-1 | BLOCKED_EXTERNAL (restore eng CLOSED) |

## Runtime requirements

- PHP 8.2+ (local E2E uses Docker `erpsmart-o2c-php:8.2`; host PHP optional via Docker lint)
- MySQL 8.0 disposable for acceptance
- Python 3.12+ for gates
- Node 22 for JS syntax in commercial gate

## Source gate hashes / CI

| Check | Notes |
|-------|-------|
| Commercial MVP `release-gate` @ Final main | SUCCESS https://github.com/AmirAbedini78/excel-dev/actions/runs/44810790482 — **source-only / insufficient** |
| Mandatory RC1 MySQL+PHP | Proposed `docs/ai/rc1/ci/rc1-mysql-php-gate.yml` — **not** in `.github/workflows/`; real GHA **NOT_RUN**; **GHA E2E not enabled** |
| Owner auth to install workflow | `gh auth refresh -h github.com -s workflow` |

**Note:** Commercial CI alone does **not** prove RC1 PHP+MySQL domain E2E. Local green ≠ GHA green.

## Staging upgrade / backup-restore

| Drill | Result |
|-------|--------|
| Privileged dump/restore + migrate×2 + rollback | **PASS** — `ops1_backup_restore_closure.json` (eng **CLOSED**) |
| Restore as restricted `p2p` without grant | **FAIL_ACCESS_DENIED_EXPECTED** |
| Prior deployed schema cumulative migrate | **BLOCKED_EXTERNAL** — provenance `UNAVAILABLE_NOT_DEPLOYED`; no invented FRESH_INSTALL |

## MF costing / rollback caveats

- Additive RC1 schema only; no automated down-migration shipped.  
- Rollback = restore prior DB dump + prior code SHA; untested against production (none deployed).  
- Manufacturing **lifecycle** is tested (PASS_REPRODUCED).  
- WIP/FG valuation remains **not fully supported** / non-posting; do not expect GL FG entries from MF-1 (`UNVALUED` / `GL_BLOCKED`).  
- **No unsupported automatic WIP/FG GL** — policy locked in `T5-VALUATION-DECISION.md`.

## Zero production deploy statement

No cPanel, production DB, live bank/tax/Sayad, or production credentials were used. Staging UAT and commercial go-live require **explicit user action** after external blockers clear. Browser evidence remains **NOT_RUN** / `BLOCKED_EXTERNAL`.
