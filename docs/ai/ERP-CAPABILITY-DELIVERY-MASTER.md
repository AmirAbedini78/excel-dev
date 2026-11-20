# ERPSMART Capability Delivery Master (Gap Matrix)

**Maintained:** 2026-11-20 (system-2)
**Repo:** `C:\Projects\excel-dev`
**Merged baseline (v10.19.1):** `7df6776eb0dcce749dee5d62969db4001b727a9e` (PR #11)
**In-flight:** v10.20.0 AR settlement on `feature/erp-ar-settlement-v10.20.0` (**NOT_DEPLOYED**)

| Capability / Cycle | Status | Tests / evidence | Gap |
|---|---|---|---|
| O2C fulfillment | `DATABASE_TESTED` | PHP E2E 10.18.1 | staging/prod NOT |
| AR posting + integrity | `DATABASE_TESTED` | PHP E2E 10.19/10.19.1 | staging/prod NOT |
| **AR settlement / allocation** | `DATABASE_TESTED` (sandbox) | `test_ar_settlement_*1020*` | staging/prod NOT; bank recon separate |

### Phases
- v10.19.1 — MERGED (PR #11), NOT_DEPLOYED
- v10.20.0 — IN FLIGHT — customer receipt + allocation

### Non-claims
- No production deploy / no bank reconciliation claimed from receipt posting.
