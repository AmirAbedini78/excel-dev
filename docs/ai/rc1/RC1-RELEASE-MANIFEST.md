# RC1 Release Manifest

**Decision:** `RC1_BLOCKED` — engineering package merged to main; **acceptance incomplete**  
**Production deployment:** **NOT_DEPLOYED** (forbidden this campaign)

## Identity

| Field | Value |
|-------|--------|
| Repository | `AmirAbedini78/excel-dev` |
| Verified `origin/main` SHA | `6da6a03796e1f89b5279424899adad5b5a23915c` |
| Release integration branch | `release/erpsmart-rc1` @ `829770317c98bc220440551af38ecb856c8a4104` |
| Merge PR | #30 |
| Schema version (repo) | `10.24.0-rc1` (`RuntimeCache::SCHEMA_VERSION`) |
| Functional currency | IRR (Toman display opt-in) |

## Runtime requirements

- PHP 8.2+ (CI uses host PHP lint; local E2E uses Docker `erpsmart-o2c-php:8.2`)
- MySQL 8.0 disposable for acceptance
- Python 3.12+ for gates
- Node 22 for JS syntax in commercial gate

## Source gate hashes / CI

| Check | SHA | Status | URL |
|-------|-----|--------|-----|
| Commercial MVP `release-gate` (push) | `6da6a03…` | SUCCESS | https://github.com/AmirAbedini78/excel-dev/actions/runs/44770814148 |
| Commercial MVP (PR #30) | merge tip | SUCCESS | https://github.com/AmirAbedini78/excel-dev/actions/runs/44770790058 |

**Note:** CI does **not** run RC1 PHP+MySQL domain E2E.

## RC1 domain E2E (local independent)

| Gate | SHA | Exit | Artifact |
|------|-----|------|----------|
| `scripts/rc1_acceptance_gate.py --strict-pass` | `6da6a03…` | 0 | `evidence/rc1_gate_report.json` |

Independent **acceptance** still **BLOCKED** on OPS-1/SYS-1 residuals (see blockers).

## Key RC1 file / migration surface (non-exhaustive)

- `app/bootstrap.php` — Inventory / MF / Costing migrate wiring  
- `app/Core/WarehouseStockApi.php`, `InventoryDomain.php`  
- `app/Core/ManufacturingExecutionDomain.php`, `ManufacturingInventoryAdapter.php`  
- `app/Core/CostingAccountingDomain.php` (GL valuation BLOCKED paths)  
- `app/Core/TradingCockpitDomain.php`, Iran localization cores  
- `scripts/rc1_acceptance_gate.py`, `tests/rc1/*`, `tests/harness/rc1_*`  

## Staging upgrade / backup-restore

| Drill | Result |
|-------|--------|
| Disposable dump `erpsmart_rc1_tr1_test` | PASS — `ops1_tr1_backup.sql` sha256 `11BCA2A91F692E89E369857FD729D3E3DC82D608E4D2FFB82A5BA9F72F17C028` |
| Restore to new disposable DB as `p2p` | **FAIL** Access denied |
| Prior deployed schema cumulative migrate | **BLOCKED_EXTERNAL** — provenance UNAVAILABLE |

## Rollback caveats

- Additive RC1 schema only; no automated down-migration shipped.  
- Rollback = restore prior DB dump + prior code SHA; untested against production (none deployed).  
- WIP/FG valuation remains non-posting; do not expect GL FG entries from MF-1.

## Zero production deploy statement

No cPanel, production DB, live bank/tax/Sayad, or production credentials were used. Staging UAT and commercial go-live require **explicit user action** after blockers clear.
