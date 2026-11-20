# ERPSMART HANDOFF — v10.21 Iran-First Foundations

**NOT_DEPLOYED**

## Baseline
| Item | Value |
|---|---|
| Parent | v10.20.1 merge `b2cc0fb6fdda0608b5d6900f7a697e8976c582e1` |
| Branch | `feature/erp-iran-first-foundations-v10.21.0` |
| SCHEMA / ModuleRegistry | `10.21.0` |

## Delivered
- `BusinessProfileDomain` + table `erp_company_business_profiles`
- Profiles: trading, manufacturing, retail, service, hybrid (defaults only — canonical entities shared)
- Explicit IRR↔Toman display helpers (`displayToIrrScaled` / `irrScaledToDisplay`); functional currency remains IRR
- Settings UI `acc_set_business_profile` (permission `accounting.settings.manage`)
- Docs: architecture (51), trading/mfg roadmap (52), regulatory gap (53), capability master
- Contracts: `tests/test_iran_first_contracts_1021.py`
- Local release_gate: PASS (402 tests, skipped=8)

## Not delivered / blockers
- Full trading or manufacturing vertical completion
- Verified VAT / e-invoice / Sayad / payroll statutory claims (no invented legal sources)
- AP posting kernel
- Production deploy

## Staging migration + smoke (not executed)
1. Verify disposable staging identity (refuse unverified `*test*` names alone).
2. Backup → migrate cumulative schema to `RuntimeCache::SCHEMA_VERSION=10.21.0` (includes `BusinessProfileDomain::migrate`).
3. Smoke: set company profile + display currency; open fiscal; AR map; per-cash GL map; O2C→AR invoice→receipt→allocate→reverse.
4. Confirm ledger amounts remain IRR when UI display=TOMAN.
5. Rollback rehearsal from backup.

## Next priorities
1. AP posting kernel + payable settlement (mirrors AR integrity).
2. One DATABASE_TESTED domestic trading path: PO→receipt→AP→payment.
3. One manufacturing pilot with costing tests.
4. Jalali round-trip hardening with evidence.
5. Regulatory items only after authoritative source + rule version recorded.

## Git (filled at merge)
| Item | Value |
|---|---|
| PR | _(pending)_ |
| Merge SHA | _(pending)_ |
| Post-merge CI | _(pending)_ |
