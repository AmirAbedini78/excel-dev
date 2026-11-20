# ERPSMART Capability Delivery Master (Gap Matrix)

**Maintained:** 2026-11-20 (system-2)
**Baseline:** `f5025b3` (v10.20.0) · **In-flight:** v10.20.1 treasury integrity · **NOT_DEPLOYED**

| Capability | Status | Evidence | Gap |
|---|---|---|---|
| O2C fulfillment | DATABASE_TESTED | PHP E2E 10.18.1 | staging/prod NOT |
| AR posting + integrity | DATABASE_TESTED | PHP E2E 10.19.x | staging/prod NOT |
| AR settlement | DATABASE_TESTED | PHP E2E 10.20 | staging/prod NOT |
| Treasury cash↔GL integrity | IN_FLIGHT 10.20.1 | integrity E2E | — |
| Bank reconciliation | NOT_STARTED | — | never claimed by receipt post |
| Cheque/Sayad lifecycle | DATA_MODEL_ONLY / CRUD | acc_checks | not cleared funds |
| Iran-first localization | PARTIAL | Jalali UI exists | formalize in 10.21 |
| Trading vertical | PARTIAL | P2P + inventory | roadmap 10.21 |
| Manufacturing vertical | CRUD_PILOT / PARTIAL | BOM/production | roadmap 10.21 |

### Phases
- v10.20.0 MERGED (PR #12)
- v10.20.1 IN FLIGHT — treasury integrity
- v10.21 — Iran-first foundations (after 10.20.1)
