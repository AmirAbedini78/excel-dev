# Financial & Regulatory Gap Matrix (Iran) — v10.21

**Rule:** Do not invent rates, deadlines, or integration protocols. Record jurisdiction, effective date, source URL/document, and rule version before enabling claims.

| Area | Status | Verified source? | Notes |
|---|---|---|---|
| VAT configuration | NOT_STARTED | No | Keep configurable; no default statutory rate claimed |
| Electronic invoicing / taxpayer | NOT_STARTED | No | `taxpayer_status` fields exist as metadata only |
| Cheque / Sayad workflow | DATA_MODEL_ONLY | No external API | Distinct from bank clearance |
| Payroll / insurance / tax | CRUD_PILOT / PARTIAL | No | Extended schema exists; not PRODUCTION |
| Statutory reports | NOT_STARTED | No | — |
| Bank reconciliation | NOT_STARTED | — | Explicitly separate from AR receipt posting |

## Staging plan (not executed)
1. Backup disposable staging DB.
2. Migrate cumulative schema through 10.21.0.
3. Smoke: fiscal open, AR map, cash GL map, profile set, one O2C→AR→receipt→allocate.
4. Rollback rehearsal on snapshot.
**Production deploy: NOT AUTHORIZED.**
