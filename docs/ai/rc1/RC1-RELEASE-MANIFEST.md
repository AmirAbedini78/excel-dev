# RC1 Release Manifest

**Decision:** `RC1_BLOCKED` — engineering P1-005 closed on stabilization; **acceptance incomplete** (browser + provenance)  
**Production deployment:** **NOT_DEPLOYED** (forbidden this campaign)

## Identity

| Field | Value |
|-------|--------|
| Repository | `AmirAbedini78/excel-dev` |
| Baseline `origin/main` SHA | `2a30f5abac6291af2e6d3ccf1f87446335c84bad` |
| Closure branch | `fix/rc1-stabilization-p1-closure` |
| Prior product merge | PR #30 |
| Prior audit docs merge | PR #31 |
| Superseded late T1 PR | #29 (close after closure merge) |
| Schema version (repo) | `10.24.0-rc1` (`RuntimeCache::SCHEMA_VERSION`) |
| Functional currency | IRR (Toman display opt-in) |

## Runtime requirements

- PHP 8.2+ (local E2E uses Docker `erpsmart-o2c-php:8.2`; host PHP optional via Docker lint)
- MySQL 8.0 disposable for acceptance
- Python 3.12+ for gates
- Node 22 for JS syntax in commercial gate

## Source gate hashes / CI

| Check | Notes |
|-------|-------|
| Commercial MVP `release-gate` | SUCCESS historically on `6da6a03…` but **source-only** |
| Mandatory RC1 MySQL+PHP | `docs/ai/rc1/ci/rc1-mysql-php-gate.yml ? `.github/workflows/`` — fails on skip |

**Note:** Commercial CI alone does **not** prove RC1 PHP+MySQL domain E2E.

## Staging upgrade / backup-restore

| Drill | Result |
|-------|--------|
| Privileged dump/restore + migrate×2 + rollback | **PASS** — `ops1_backup_restore_closure.json` |
| Restore as restricted `p2p` without grant | **FAIL_ACCESS_DENIED_EXPECTED** |
| Prior deployed schema cumulative migrate | **BLOCKED_EXTERNAL** — provenance UNAVAILABLE |

## Rollback caveats

- Additive RC1 schema only; no automated down-migration shipped.  
- Rollback = restore prior DB dump + prior code SHA; untested against production (none deployed).  
- WIP/FG valuation remains non-posting; do not expect GL FG entries from MF-1 (`UNVALUED` / `GL_BLOCKED`).

## Zero production deploy statement

No cPanel, production DB, live bank/tax/Sayad, or production credentials were used. Staging UAT and commercial go-live require **explicit user action** after external blockers clear.
