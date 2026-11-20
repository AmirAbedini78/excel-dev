# T6 Browser / Screenshot Notes

**Date:** 2026-11-21 (Agent B — authenticated local browser UAT)  
**Repo HEAD during run:** `d75dd6abb88086c34613472ace247d87369839d0` (`fix/rc1-stabilization-p1-closure`)  
**Campaign expected main SHA:** `52fa7f12f465653d6e445d484f1a8dda48215f45` (not checked out on this agent)  
**Environment:** Windows; Docker MySQL `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / port `3307` / `server_uuid=a73a8a84-e4bd-11f1-ad33-7e073bee960b`; PHP via Docker image `erpsmart-o2c-php:8.2` (host PHP CLI absent); **no staging URL**.

## Status: `LOCAL_BROWSER_VERIFIED` (not `STAGING_VERIFIED`)

Authenticated browser walkthrough completed against an **isolated local** HTTP app + disposable MySQL DB + synthetic seed data.  
**Not** staging/production. Do **not** claim `STAGING_VERIFIED`. Do **not** redefine SYS-1 acceptance JSON smoke as full browser PASS.

### How local app was served
1. Disposable DB: `erpsmart_rc1_browser_uat` on sandbox MySQL (`p2p` / `p2p_test`).
2. Container: `erpsmart-rc1-browser-uat`  
   `docker run -d --name erpsmart-rc1-browser-uat -v <repo>:/app -w /app -p 8765:8765 --add-host=host.docker.internal:host-gateway erpsmart-o2c-php:8.2 php -S 0.0.0.0:8765 -t /app`
3. Fresh install via `install.php` → `app/config.php` (gitignored) pointing at disposable DB.  
   Admin (disposable): `rc1.uat@example.local` / `Rc1UatPass99!`
4. Enabled workspace modules for UAT: `production`, `service`, `p2p`.
5. Seeded company profiles + limited role via `docs/ai/rc1/evidence/browser/_seed_limited_uat.php`  
   - company 2: retail / IRR  
   - company 1: service / TOMAN  
   - user `rc1.limited@example.local` / role `rc1_viewer` (**no** `accounting.purchase.manage`)

### Browser tool
**cursor-ide-browser MCP** (Playwright CLI available on host as `npx playwright` 1.65.1 but MCP browser used for interactive UAT). CSRF probes via `curl.exe` against same local URL.

## Case results

| Case | Result | Notes |
|------|--------|-------|
| login / logout | DONE | Admin login; logout returned to `page=login` |
| company select | DONE | Cockpit company bar + dashboard `company_id` filter |
| trading cockpit | DONE | TR-1 RTL empty pipeline; metrics 0 |
| manufacturing cockpit | DONE | MF-1 empty + GL valuation **BLOCKED** + QC blocked banners |
| service / retail | DONE | Service tickets empty; retail profile (no manufacturing required) |
| RTL | DONE | `dir=rtl` Persian UI throughout |
| Jalali | DONE | Header `امروز: 1405/08/30`; calendar آبان 1405 |
| IRR / Toman | DONE | Settings: retail IRR; service TOMAN (ledger note: IRR functional) |
| isolation | DONE | Calendar filter company 1 → day tasks only for that company |
| negative perms | DONE | `RC1 Viewer` trading role list shows only `*.view` (no purchase.manage writes) |
| CSRF | DONE | No/bad CSRF → **HTTP 419** `CSRF token mismatch`; valid CSRF + `ui_cockpit_post_stock` refused by cockpit (flash / no mutation) |
| errors / empty | DONE | Empty cockpits, empty service tickets, MF blocked banners |
| one E2E UI action | DONE | Dashboard company filter to company 1 (isolation) |

## Screenshots (real only)
Under `docs/ai/rc1/evidence/browser/`:
- `01-login-jalali-calendar.png`
- `02-trading-cockpit-rtl-empty.png`
- `03-manufacturing-cockpit-blocked.png`
- `04-service-empty.png`
- `05-retail-irr-profile.png`
- `06-company-isolation-calendar.png`
- `07-service-toman-profile.png`
- `08-logout-login.png`
- `09-limited-viewer-trading.png`
- `csrf_probe.log`
- `session.log`

## Classification
- **Browser UAT:** `LOCAL_BROWSER_VERIFIED`
- **Staging UAT:** **NOT** `STAGING_VERIFIED` (no staging URL provisioned)
- **SYS-1 recommendation:** Browser residual may be recorded as `LOCAL_BROWSER_VERIFIED`. Do **not** mark SYS-1 contract `PASS` for staging/production acceptance. Independent RC1 remains blocked on **staging browser** (if required by owner policy) and **OPS-1 provenance (P1-001)**. Do not invent `STAGING_VERIFIED` or FRESH_INSTALL.

## Owner actions still required for `STAGING_VERIFIED`
1. Provide authenticated **non-prod staging URL** for this SHA (or agreed deploy of stabilization branch).
2. Re-run the same case table against staging; capture screenshots under `docs/ai/rc1/evidence/browser/staging/` (or agreed path).
3. Confirm disposable credentials / CSRF session on staging (not production/cPanel).
4. Keep P1-001 provenance separate — browser local evidence does not close schema provenance.
