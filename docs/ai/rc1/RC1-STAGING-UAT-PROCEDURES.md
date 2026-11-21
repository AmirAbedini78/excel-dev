# RC1 Operational Staging UAT Procedures (Agent C)

**Lane:** C — Operational Staging UAT (procedures + test skeleton only)  
**Campaign:** ERPSMART RC1 Staging Pre-Flight  
**Date:** 2026-11-21  
**Authoritative staging-release SHA:** `POST_MERGE_MAIN_TIP_CI_GREEN`  
**Product feature baseline:** `2c1cf6592f246553c66c3bc90d00385bf65d4c29`  
**Schema:** `10.24.0-rc1` (`RuntimeCache::SCHEMA_VERSION`)

---

## Status and authorization (read first)

| Label | Meaning | Current (2026-11-21) |
|-------|---------|------------------------|
| `LOCAL_BROWSER_VERIFIED` | Disposable local HTTP + synthetic seed UAT | **YES** — see `T6-BROWSER-NOTES.md` |
| `STAGING_VERIFIED` | Authorized isolated staging host + evidence | **NO** — staging URL **not** provisioned; remote auth **not** granted |

**These procedures are written for future authorized staging execution.** Agents and operators must **not** run them against production, cPanel production accounts, or any host not explicitly designated staging by the owner.

**Inventory:** `RC1-STAGING-UAT-INVENTORY.md` (auto vs manual vs skeleton). Page-open â‰  full supplier-payment proof.

**Hard constraints (non-negotiable):**

- Do **not** claim `STAGING_VERIFIED` until owner-provided **isolated staging** exists and Sections 12â€“13 exit criteria are met.
- No remote staging access / no production mutation by agents.
- No new ERP business features during UAT — report defects; do not extend RC1 scope.
- MF residual: WIP/FG valuation may show **`UNVALUED`**; GL paths **`GL_BLOCKED`** — **PASS** if banners match policy (`T5-VALUATION-DECISION.md`), **FAIL** only if UI falsely claims full valuation or auto GL.

**Related documents:**

- Deployment plan smoke index: `RC1-STAGING-DEPLOYMENT-PLAN.md` آ§6  
- Local browser baseline: `T6-BROWSER-NOTES.md`  
- Role matrix: `T6-ROLE-ACTION-MATRIX.md`  
- Rollback: `RC1-ROLLBACK-RUNBOOK.md`  
- Playwright skeleton (optional automation): `tests/staging_uat/README.md`

---

## 1. Evidence conventions (staging)

| Item | Rule |
|------|------|
| Root directory | `docs/ai/rc1/evidence/browser/staging/` (create when staging is authorized) |
| Naming | `<procedure-id>_<short-desc>_<YYYYMMDD-HHMM>.png` (e.g. `UAT-AUTH-001_logout_20261121-1430.png`) |
| Session log | `session.log` — URL (host only, no secrets), SHA, operator id, browser, result summary |
| CSRF probes | `csrf_probe.log` — HTTP status lines only |
| DB / migrate proof | Separate OPS lane artifacts; UAT references migrateأ—2 PASS before browser UAT |
| Secrets | **Never** commit passwords, `app_key`, or full DSN in evidence |

**Local rehearsal reference (not staging):** `docs/ai/rc1/evidence/browser/` (Agent B, `LOCAL_BROWSER_VERIFIED`).

---

## 2. Global preconditions (all procedures)

1. **Owner authorization** for remote staging (scope: staging host only) — see `RC1-STAGING-DEPLOYMENT-PLAN.md` آ§10.
2. **Staging URL** (HTTPS) recorded in writing; confirmed **â‰  production** hostname/DB.
3. Application code tree pinned to SHA `2c1cf659…` on staging document root.
4. **Offline migrate أ—2** completed on staging DB before first business UAT session (not first HTTP hit).
5. Staging `app/config.php` points **only** at staging DSN; `app_key` is staging-specific.
6. Disposable or owner-approved **staging credentials** (admin + at least one limited viewer + company-scoped users if seeded).
7. Workspace modules enabled per owner seed policy (minimum for RC1 UAT: `p2p`, `procurement`, `production`, `service` as needed).
8. Maintenance mode **off** for UAT window; abort owner named.

If any precondition fails, **stop** — record blocker in `session.log`; do **not** substitute production.

---

## 3. Procedure template

Each procedure below uses:

- **Goal** — what risk or acceptance point is exercised  
- **Preconditions** — in addition to آ§2  
- **Steps** — ordered, executable  
- **Pass criteria** — objective  
- **Evidence** — files to capture  

Map to deployment smoke IDs (`RC1-STAGING-DEPLOYMENT-PLAN.md` آ§6): **S1â€“S12** where noted.

---

## 4. Authentication (login / logout)

### UAT-AUTH-001 — Staging login page health (maps **S1**)

| Field | Detail |
|-------|--------|
| **Goal** | Confirm staging app serves login without 5xx; RTL shell present. |
| **Preconditions** | Staging URL reachable; no maintenance banner (unless testing maintenance separately). |
| **Steps** | 1. Navigate to staging base URL (typically redirects to `?page=login`). 2. Confirm Persian RTL layout (`dir=rtl` or RTL styling). 3. Confirm login form fields (email/username, password). 4. Note Jalali "todayâ€‌ if shown on shell. |
| **Pass criteria** | HTTP 200; login form visible; no unhandled exception page. |
| **Evidence** | Screenshot `UAT-AUTH-001_login-rtl.png`; note hostname in `session.log`. |

### UAT-AUTH-002 — Successful login (admin)

| Field | Detail |
|-------|--------|
| **Goal** | Session establishment on staging. |
| **Preconditions** | Valid staging admin credential from owner (out-of-band). |
| **Steps** | 1. Submit login with staging admin. 2. Land on dashboard or default post-auth page. 3. Confirm nav renders (modules per seed). |
| **Pass criteria** | No auth error flash; authenticated nav visible. |
| **Evidence** | `UAT-AUTH-002_post-login-nav.png` (redact any PII if non-synthetic data). |

### UAT-AUTH-003 — Logout and session clear (maps **S2**)

| Field | Detail |
|-------|--------|
| **Goal** | Logout destroys session; CSRF on logout honored. |
| **Preconditions** | Logged in as admin. |
| **Steps** | 1. Trigger logout (`?page=login` action `logout` via UI). 2. Confirm return to login. 3. Attempt back-nav to protected page — expect redirect to login. |
| **Pass criteria** | Logout completes; protected pages require re-auth. |
| **Evidence** | `UAT-AUTH-003_logout-login.png`; local reference `08-logout-login.png`. |

### UAT-AUTH-004 — Negative login

| Field | Detail |
|-------|--------|
| **Goal** | Invalid credentials do not authenticate. |
| **Preconditions** | Logged out. |
| **Steps** | Submit wrong password for known user. |
| **Pass criteria** | Stay on login; danger flash or equivalent; no dashboard access. |
| **Evidence** | `UAT-AUTH-004_bad-password.png`. |

---

## 5. Company isolation and tenant context

### UAT-TENANT-001 — Company selector / cockpit bar (maps **S3**)

| Field | Detail |
|-------|--------|
| **Goal** | Operator can select company; selection persists in UI context. |
| **Preconditions** | â‰¥2 companies visible to user (seed or legacy anonymized data). |
| **Steps** | 1. Open trading or manufacturing cockpit. 2. Select company A in company bar / filter. 3. Note displayed company name/id. 4. Switch to company B; confirm UI updates. |
| **Pass criteria** | Company switch does not 500; selected company reflected in cockpit/dashboard chrome. |
| **Evidence** | `UAT-TENANT-001_company-bar.png`. |

### UAT-TENANT-002 — Data isolation on dashboard / calendar (maps **S3**)

| Field | Detail |
|-------|--------|
| **Goal** | Filtered views show only selected company's tasks/events where applicable. |
| **Preconditions** | Seed or data with distinct per-company calendar/tasks (local pattern: company 1 vs 2). |
| **Steps** | 1. Dashboard â†’ set `company_id` filter to company 1. 2. Record visible task labels/companies. 3. Switch filter to company 2; repeat. |
| **Pass criteria** | Lists differ appropriately; no cross-company rows under wrong filter (or empty if no data — document empty). |
| **Evidence** | `UAT-TENANT-002_calendar-isolation.png`; local reference `06-company-isolation-calendar.png`. |

### UAT-TENANT-003 — RBAC: limited viewer (maps **S8**)

| Field | Detail |
|-------|--------|
| **Goal** | Low-privilege role sees view-only trading surface; no forbidden writes in UI. |
| **Preconditions** | User with role like `rc1_viewer` (no `accounting.purchase.manage`). |
| **Steps** | 1. Login as limited user. 2. Open trading cockpit / procurement views. 3. Enumerate visible actions vs `T6-ROLE-ACTION-MATRIX.md`. 4. If any POST affordance exists, attempt harmless read-only navigation only — **do not** brute-force write APIs on production-like data without owner OK. |
| **Pass criteria** | UI does not expose manage actions reserved for purchase.manage; server remains authoritative (403 on forbidden POST if probed in CSRF suite). |
| **Evidence** | `UAT-TENANT-003_limited-viewer.png`; local reference `09-limited-viewer-trading.png`. |

---

## 6. P2P â†’ pay (TR-1 / AP)

### UAT-P2P-001 — P2P module entry and pipeline visibility

| Field | Detail |
|-------|--------|
| **Goal** | P2P / procurement surfaces load on staging without error. |
| **Preconditions** | `p2p` + `procurement` modules enabled; user with `procurement.view` or admin. |
| **Steps** | 1. Navigate `?page=p2p` and `?page=procurement`. 2. Open trading cockpit `?page=trading_cockpit`. 3. Observe pipeline cards (may be empty). |
| **Pass criteria** | HTTP 200; RTL; no false "PAIDâ€‌ / `BANK_RECONCILED` claims on empty pipeline (`T6-SAFE-AI-GUARDRAILS.md`). |
| **Evidence** | `UAT-P2P-001_trading-cockpit.png`; maps **S4**. |

### UAT-P2P-002 — TR-1 document path (read / status)

| Field | Detail |
|-------|--------|
| **Goal** | Staging data (if seeded) shows coherent PO â†’ receipt â†’ invoice â†’ AP workflow **states** in UI or reports. |
| **Preconditions** | Owner-approved **synthetic** TR-1 chain on staging **or** read-only verification on anonymized legacy rows — **no live supplier payments**. |
| **Steps** | 1. Locate a posted PO / GR / vendor bill per seed manifest. 2. Trace statuses in UI lists. 3. Cross-check outstanding AP in finance views if present. |
| **Pass criteria** | Status labels match backend state; duplicate invoice code surfaces controlled error (if repro case exists). |
| **Evidence** | Screenshots per document id; optional export of list filters (no secrets). |

### UAT-P2P-003 — AP payment / allocation (pay)

| Field | Detail |
|-------|--------|
| **Goal** | Treasurer path can draft payment / allocation **only** if owner authorized synthetic pay on staging. |
| **Preconditions** | User with `accounting.treasury.manage`; open AP with allocatable balance on **staging test** vendor. |
| **Steps** | 1. Open treasury/AP payment UI. 2. Create payment draft for test amount. 3. Allocate to test invoice. 4. Verify cockpit does **not** overstate PAID without allocation. |
| **Pass criteria** | Payment workflow completes on staging test data; balances consistent; cockpit still honest per guardrails. |
| **Evidence** | Before/after screenshots; voucher/payment id in `session.log` (no bank tokens). |

**RC1 note:** If staging has **no** seeded pay data, mark **SKIP (no seed)** — empty pipeline still passes **S4/S10** with documented empty state.

---

## 7. Sales â†’ settle (TR-2 / AR)

### UAT-SALES-001 — Sales module and O2C entry

| Field | Detail |
|-------|--------|
| **Goal** | Sales / trade pages load for authorized user. |
| **Preconditions** | Sales module enabled; `accounting.sales.manage` or view-equivalent for read-only pass. |
| **Steps** | Navigate `?page=trade` and sales-related nav entries per `ModuleRegistry`. |
| **Pass criteria** | No 500; RTL. |
| **Evidence** | `UAT-SALES-001_sales-entry.png`. |

### UAT-SALES-002 — Invoice â†’ receipt â†’ settle (read or synthetic)

| Field | Detail |
|-------|--------|
| **Goal** | TR-2 chain reaches settled outstanding `0.00` on **staging test** customer when owner seeds scenario (mirrors disposable TR-2 acceptance). |
| **Preconditions** | Synthetic sales order + invoice + receipt on staging **or** read-only verify existing anonymized row. |
| **Steps** | 1. Open customer AR outstanding. 2. Post receipt (if authorized synthetic). 3. Confirm outstanding zero on that test invoice. |
| **Pass criteria** | Settlement math correct; no silent stock GL side effects beyond scope. |
| **Evidence** | AR outstanding before/after screenshots. |

**RC1 note:** Empty sales pipeline â†’ **SKIP (no seed)** with **S10** empty-state pass.

---

## 8. Manufacturing lifecycle (MF-1; valuation blocked)

### UAT-MF-001 — Manufacturing cockpit load (maps **S5**)

| Field | Detail |
|-------|--------|
| **Goal** | MF cockpit renders with **expected blocked** valuation / GL messaging. |
| **Preconditions** | `production` module enabled; `production.view`. |
| **Steps** | 1. Open `?page=manufacturing_cockpit`. 2. Read banners for WIP/FG valuation and GL. 3. Confirm empty BOM/plan lists acceptable. |
| **Pass criteria** | Valuation shown as **BLOCKED** / `UNVALUED` policy text; **no** UI claim of full WIP/FG GL auto-post. QC blocked state visible if no T1 evidence. |
| **Evidence** | `UAT-MF-001_mfg-blocked.png`; local reference `03-manufacturing-cockpit-blocked.png`. |

### UAT-MF-002 — MF lifecycle views (BOM / plan / issue / FG)

| Field | Detail |
|-------|--------|
| **Goal** | Navigate MF-1 pages without 500; mutations only via authorized domain APIs if owner seeds T2 scenario. |
| **Preconditions** | Optional seeded BOM/plan on staging. |
| **Steps** | 1. List BOMs/plans. 2. If seed exists: issue/FG via **domain** UI (not `ui_cockpit_post_stock`). 3. Observe stock/concurrency messages. |
| **Pass criteria** | Concurrent issue rules respected; negative stock not shown; valuation remains blocked in cockpit after issue. |
| **Evidence** | Screenshots per step; reference disposable concurrent issue test in CI if UI not seeded. |

### UAT-MF-003 — Cockpit POST hard deny

| Field | Detail |
|-------|--------|
| **Goal** | Cockpit cannot mutate stock/GL directly. |
| **Preconditions** | Authenticated session with CSRF token. |
| **Steps** | Covered in **UAT-SEC-002**; confirm MF/trading cockpits same guard. |
| **Pass criteria** | `ui_cockpit_post_stock` / `ui_cockpit_post_gl` â†’ 403 or flash refusal; no DB mutation. |
| **Evidence** | `csrf_probe.log` entry + screenshot if flash shown. |

---

## 9. Service and retail profiles

### UAT-SVC-001 — Service tickets empty / list (maps **S6**)

| Field | Detail |
|-------|--------|
| **Goal** | Service module loads for service company profile. |
| **Preconditions** | `service` module enabled; company with service business profile. |
| **Steps** | Open `?page=service`; list tickets. |
| **Pass criteria** | Empty list OK; no 500. |
| **Evidence** | `UAT-SVC-001_service-empty.png`; local `04-service-empty.png`. |

### UAT-RTL-RETAIL-001 — Retail profile (IRR)

| Field | Detail |
|-------|--------|
| **Goal** | Retail company shows retail-oriented settings / IRR display where configured. |
| **Preconditions** | Company 2 (or equivalent) retail profile per seed. |
| **Steps** | 1. Select retail company. 2. Open settings or profile surface showing currency/display. |
| **Pass criteria** | IRR retail labeling coherent; no manufacturing requirement for retail-only nav. |
| **Evidence** | `UAT-RTL-RETAIL-001_retail-irr.png`; local `05-retail-irr-profile.png`. |

---

## 10. Finance and stock reconciliation spot checks

### UAT-FIN-001 — Trial balance / voucher Dr=Cr (maps **S12**)

| Field | Detail |
|-------|--------|
| **Goal** | Spot-check accounting integrity on staging DB post-migrate. |
| **Preconditions** | Owner or DBA provides **read-only** query result **or** finance report URL; migrate recon already PASS (disposable pattern: vouchers 47, Dr=Cr balanced). |
| **Steps** | 1. Run or view agreed voucher balance report on staging. 2. Sample one posted voucher lines. 3. Confirm Dr = Cr for sample. |
| **Pass criteria** | No unexplained imbalance on sample; counts within owner tolerance vs pre-migrate notes. |
| **Evidence** | Screenshot of report (redacted); attach OPS recon JSON reference if available. |

### UAT-FIN-002 — Stock quantity spot check (maps **S12**)

| Field | Detail |
|-------|--------|
| **Goal** | Inventory qty unchanged vs pre-UAT baseline for agreed SKU/location sample. |
| **Preconditions** | Pre-migrate row count / qty noted in staging migrate checklist. |
| **Steps** | 1. Open inventory/stock report for sample item. 2. Compare to baseline manifest. |
| **Pass criteria** | No silent qty drift during migrate/UAT window. |
| **Evidence** | Report screenshot; baseline reference in `session.log`. |

### UAT-FIN-003 — Post-migrate schema gate (maps **S11**)

| Field | Detail |
|-------|--------|
| **Goal** | First browser hits do not re-trigger destructive migrate. |
| **Preconditions** | Offline migrate أ—2 complete; staging `schema.json` consistent. |
| **Steps** | 1. Owner confirms migrate logs archived. 2. Load dashboard + settings (no `run_migration` click). 3. DBA verifies no unexpected DDL in MySQL general log during UAT window (if enabled). |
| **Pass criteria** | App stable; schema version `10.24.0-rc1` in cache/metadata. |
| **Evidence** | Screenshot settings/system info if exposed; OPS migrate log reference. |

---

## 11. RTL, Jalali, IRR / Toman

### UAT-UX-001 — RTL layout (maps **S4â€“S7**)

| Field | Detail |
|-------|--------|
| **Goal** | Persian RTL across login, dashboard, cockpits. |
| **Steps** | Visually inspect `dir=rtl` and nav alignment on 3 pages. |
| **Pass criteria** | Consistent RTL; readable Persian labels. |
| **Evidence** | `UAT-UX-001_rtl-nav.png`. |

### UAT-UX-002 — Jalali dates (maps **S7**)

| Field | Detail |
|-------|--------|
| **Goal** | Header "todayâ€‌ and calendar month use Jalali. |
| **Steps** | 1. Note dashboard header date. 2. Open calendar/month view; compare month name (e.g. ط¢ط¨ط§ظ†). |
| **Pass criteria** | Jalali coherent with wall-clock test day (owner timezone documented). |
| **Evidence** | `UAT-UX-002_jalali-calendar.png`; local `01-login-jalali-calendar.png`. |

### UAT-UX-003 — IRR functional vs Toman display (maps **S6**)

| Field | Detail |
|-------|--------|
| **Goal** | Functional currency IRR; Toman display opt-in per company profile. |
| **Steps** | 1. Retail company â†’ IRR display. 2. Service company â†’ TOMAN display where configured; note ledger still IRR functional per settings copy. |
| **Pass criteria** | Settings match `php_iran_localization_rc1_test` / owner spec; no invented regulatory claims. |
| **Evidence** | `UAT-UX-003_irr-toman.png`; local `07-service-toman-profile.png`. |

---

## 12. RBAC, tenant, CSRF

### UAT-SEC-001 — CSRF mismatch (maps **S9**)

| Field | Detail |
|-------|--------|
| **Goal** | State-changing requests without valid CSRF fail closed. |
| **Preconditions** | Authenticated session cookie; use owner-approved curl/script **against staging only**. |
| **Steps** | 1. POST logout or mutating action without `_csrf` token. 2. POST with invalid token. |
| **Pass criteria** | HTTP **419** `CSRF token mismatch` (or documented equivalent). |
| **Evidence** | `csrf_probe.log`; local pattern in `T6-BROWSER-NOTES.md`. |

### UAT-SEC-002 — Cockpit mutate POST refused

| Field | Detail |
|-------|--------|
| **Goal** | `ui_cockpit_post_stock` / `ui_cockpit_post_gl` denied. |
| **Steps** | Valid CSRF + forbidden cockpit action (staging only). |
| **Pass criteria** | 403 or flash; **no** stock/voucher mutation. |
| **Evidence** | Log line + optional screenshot. |

### UAT-SEC-003 — Tenant permission enforcement

| Field | Detail |
|-------|--------|
| **Goal** | Server-side `Tenant::can` / `requirePermission` blocks unauthorized pages. |
| **Steps** | As limited user, deep-link to `?page=settings` (nonâ€“platform-admin) if applicable — expect 403. |
| **Pass criteria** | HTTP 403 or redirect; no data leak. |
| **Evidence** | `UAT-SEC-003_settings-403.png`. |

---

## 13. Error and empty states

### UAT-EMPTY-001 — Empty pipelines (maps **S10**)

| Field | Detail |
|-------|--------|
| **Goal** | Empty trading/MF/service views degrade gracefully. |
| **Steps** | Open cockpits with no open documents. |
| **Pass criteria** | Zero metrics OK; blocked MF banners still present; no PHP stack traces. |
| **Evidence** | Composite screenshot or separate files per module. |

### UAT-EMPTY-002 — Controlled business errors

| Field | Detail |
|-------|--------|
| **Goal** | Known duplicate / validation errors show user-safe Persian messages. |
| **Preconditions** | Optional repro of `P2P_SUPPLIER_INVOICE_DUPLICATE` on staging test vendor only. |
| **Steps** | Trigger duplicate per disposable test recipe (owner approval). |
| **Pass criteria** | Error surfaced; balances unchanged. |
| **Evidence** | Screenshot + test id in log. |

---

## 14. Backup and rollback verification (staging operator)

**Scope:** Owner/DBA executes; UAT agent **verifies evidence exists** before/after browser suite. Adapted from `RC1-ROLLBACK-RUNBOOK.md` and deployment plan آ§7.

### UAT-OPS-001 — Pre-migrate dump captured

| Field | Detail |
|-------|--------|
| **Goal** | Prove rollback point exists before code cut. |
| **Steps** | 1. Confirm dump file path + SHA-256 recorded offline. 2. Confirm DB name = staging only. |
| **Pass criteria** | Written checklist M1 complete (`RC1-STAGING-DEPLOYMENT-PLAN.md` آ§4.3). |
| **Evidence** | Hash + timestamp in operator log (not in Git if sensitive). |

### UAT-OPS-002 — Rollback drill (tabletop or executed)

| Field | Detail |
|-------|--------|
| **Goal** | Team can restore staging DB from pre-migrate dump within maintenance window. |
| **Steps** | 1. Tabletop: walk steps Aâ€“D in rollback runbook. **Or** 2. Executed restore on **non-prod** clone schema (owner choice). |
| **Pass criteria** | Restore yields expected table count; app boots with prior code SHA if rolled back. |
| **Evidence** | Operator sign-off; reference disposable `ops1_backup_restore_closure.json` pattern. |

### UAT-OPS-003 — Cache isolation on rollback

| Field | Detail |
|-------|--------|
| **Goal** | Only staging RuntimeCache fingerprint cleared after restore/migrate mismatch. |
| **Steps** | Document path to staging `schema.json`; confirm prod cache paths not referenced. |
| **Pass criteria** | Staging-only cache ops in runbook. |
| **Evidence** | Checklist tick + path redacted in log. |

### UAT-OPS-004 — Post-rollback smoke (if drill executed)

| Field | Detail |
|-------|--------|
| **Goal** | After restore, minimal **S1â€“S2** pass before re-attempt migrate. |
| **Steps** | Login + logout only on rolled-back tree. |
| **Pass criteria** | Auth works; schema version matches rolled-back expectation. |
| **Evidence** | `UAT-OPS-004_post-rollback-login.png`. |

---

## 15. Traceability matrix (procedures â†’ smoke S1â€“S12)

| Smoke ID | Procedures |
|----------|------------|
| S1 | UAT-AUTH-001 |
| S2 | UAT-AUTH-002, UAT-AUTH-003 |
| S3 | UAT-TENANT-001, UAT-TENANT-002 |
| S4 | UAT-P2P-001, UAT-UX-001 |
| S5 | UAT-MF-001, UAT-MF-003 |
| S6 | UAT-SVC-001, UAT-RTL-RETAIL-001, UAT-UX-003 |
| S7 | UAT-UX-002 |
| S8 | UAT-TENANT-003 |
| S9 | UAT-SEC-001, UAT-SEC-002 |
| S10 | UAT-EMPTY-001, UAT-EMPTY-002 |
| S11 | UAT-FIN-003 |
| S12 | UAT-FIN-001, UAT-FIN-002 |

---

## 16. Exit criteria for `STAGING_VERIFIED` (owner claim only)

All must be true — **agents do not self-award the label:**

1. Isolated staging URL + DB confirmed in writing.  
2. Code SHA `2c1cf659…` deployed on staging.  
3. Offline migrate أ—2 PASS on staging DB (evidence).  
4. Procedures **UAT-AUTH-001** through **UAT-SEC-003** and **UAT-EMPTY-001** executed with evidence under `evidence/browser/staging/`.  
5. **S5** MF blocked banners verified — not treated as defects.  
6. **UAT-OPS-001** rollback dump provenance recorded.  
7. No production hostname/credentials used.  
8. Owner signs UAT summary.

Until then: remain **`LOCAL_BROWSER_VERIFIED`** at best for browser class; **`STAGING_VERIFIED` = NO**.

---

## 17. Document control

| Field | Value |
|-------|--------|
| Path | `docs/ai/rc1/RC1-STAGING-UAT-PROCEDURES.md` |
| Author lane | Agent C — Operational Staging UAT |
| Remote execution | **None** in this pass |
| Production | **Not authorized** |