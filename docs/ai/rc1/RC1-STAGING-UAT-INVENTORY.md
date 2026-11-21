# RC1 Staging UAT Inventory — classification (truthful)

**Scope:** `tests/staging_uat/` only.  
**Rule:** Opening a procurement page ≠ full supplier-payment proof. Do **not** weaken acceptance to paint green.

**Labels:**

| Class | Meaning |
|-------|---------|
| `AUTOMATED_AND_EXECUTABLE` | Spec has real assertions and can run when `STAGING_BASE_URL` (+creds if needed) are set |
| `MANUAL_PROCEDURE` | Owner/operator steps live in `RC1-STAGING-UAT-PROCEDURES.md` (or annotations) |
| `SKELETON_ONLY` | Scaffold / TODO; not full acceptance evidence |
| `SKIPPED` | Fail-closed skip when env/flag missing |
| `NOT_RUN` | Not executed against authorized remote staging in this campaign |

---

## File classification

| Path | Class | Notes |
|------|-------|-------|
| `staging-guard.ts` | `AUTOMATED_AND_EXECUTABLE` | URL required; production-like host refuse |
| `playwright.config.example.ts` | `SKELETON_ONLY` | Example config; not a test |
| `README.md` | `MANUAL_PROCEDURE` | Setup + claim rules |
| `specs/auth.spec.ts` | `AUTOMATED_AND_EXECUTABLE` + `SKIPPED` without creds | Login page RTL always; login/logout skipped without env |
| `specs/csrf-guard.spec.ts` | `AUTOMATED_AND_EXECUTABLE` | Reachability / CSRF surface smoke when URL set |
| `specs/localization.spec.ts` | `AUTOMATED_AND_EXECUTABLE` | RTL/localization smoke |
| `specs/cockpits.spec.ts` | `AUTOMATED_AND_EXECUTABLE` | Cockpit page load smoke — **not** full MF/TR acceptance |
| `specs/company-isolation.spec.ts` | `AUTOMATED_AND_EXECUTABLE` / `SKELETON_ONLY` | Isolation checks limited without seeded multi-company data |
| `specs/sales-settle.spec.ts` | `SKELETON_ONLY` + `SKIPPED` + `MANUAL_PROCEDURE` | Needs owner seed; full settle is procedure doc |
| `specs/p2p-pay.spec.ts` | `SKELETON_ONLY` + `SKIPPED` + `MANUAL_PROCEDURE` | Page open ≠ pay/allocation proof; `STAGING_UAT_ENABLE_PAY_FLOW=1` + seed required |

---

## Campaign status (this safety patch)

| Item | Status |
|------|--------|
| Suite run against authorized remote staging | `NOT_RUN` |
| `STAGING_VERIFIED` | **Not claimed** |
| Disposable PHP/MySQL staging-executor safety tests | Covered under `tests/rc1/test_rc1_staging_migrate_executor.py` (CI) — **not** a substitute for staging UAT |

---

## Acceptance honesty

- Green Playwright smoke on staging URL does **not** close UAT-P2P-003 (supplier payment) or full O2C settle.
- Manual procedures remain authoritative for payment, treasury allocation, and multi-company isolation proofs.
