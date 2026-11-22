# T6 Browser / Screenshot Notes

**Date:** 2026-11-22 (rerun on `origin/main` tip)  
**Repo HEAD during run:** `a53019c6531e9be29274a6159b725737cde64547`  
**Environment:** Windows; Docker MySQL `erpsmart-p2p-mysql-test` / `9c00f40fc78a…` / port `3307` / `server_uuid=c097f150-e6b0-11f1-84d8-9ad0ee01f487`; PHP via Docker image `erpsmart-o2c-php:8.2` (host PHP CLI absent); **no staging URL**.

## Status: `LOCAL_BROWSER_VERIFIED` (not `STAGING_VERIFIED`)

Authenticated browser walkthrough completed against an **isolated local** HTTP app + disposable MySQL DB + synthetic seed data.  
**Not** staging/production. Do **not** claim `STAGING_VERIFIED`. Do **not** redefine SYS-1 acceptance JSON smoke as full browser PASS.

### How local app was served
1. Disposable DB: `erpsmart_rc1_browser_uat` on sandbox MySQL (`p2p` / `p2p_test`).
2. Container: `erpsmart-rc1-browser-uat`  
   `docker run -d --name erpsmart-rc1-browser-uat -v <repo>:/app -w /app -p 8765:8765 --add-host=host.docker.internal:host-gateway erpsmart-o2c-php:8.2 php -S 0.0.0.0:8765 -t /app`
3. Fresh cumulative migrate + admin via `docs/ai/rc1/evidence/browser/_fresh_install_seed.php` (clears/avoids stale RuntimeCache schema-ready short-circuit; sets platform admin + workspace owner membership; enables modules).  
   Admin (disposable): `rc1.uat@example.local` / `Rc1UatPass99!`
4. Enabled workspace modules for UAT: `production`, `service`, `p2p`, `procurement`, `inventory`.
5. Seeded company profiles + limited role via `docs/ai/rc1/evidence/browser/_seed_limited_uat.php`  
   - company 2: retail / IRR  
   - company 1: service / TOMAN  
   - user `rc1.limited@example.local` / role `rc1_viewer` (**no** `accounting.purchase.manage`)

### Browser tool
**Playwright** with system Chrome (`channel: 'chrome'`) — Cursor ide-browser MCP could not reach host loopback (`chrome-error://chromewebdata/`); Playwright CDN browser zip was geo-blocked. CSRF/RBAC probes via Python urllib against same local URL. Helpers: `_local_uat_playwright.mjs`, `_local_http_uat.py`.

### Offline staging executor (same sandbox, separate DB)
`python -m unittest tests.rc1.test_rc1_staging_migrate_executor` → **12/12 OK** on disposable DB `erpsmart_rc1_staging_executor_test`. Label remains **not** `STAGING_VERIFIED`.

## Case results

| Case | Result | Notes |
|------|--------|-------|
| login / logout | DONE | Admin login; logout returned to `page=login` |
| company select | DONE | Calendar `company_id` filter + settings company query |
| trading cockpit | DONE | TR-1 RTL cockpit title `کابین تجارت (TR-1)` |
| manufacturing cockpit | DONE | MF-1 cockpit + blocked/valuation banners present |
| service / retail | DONE | Service page empty-capable; retail/service profiles seeded |
| RTL | DONE | `dir=rtl` Persian UI throughout |
| Jalali | DONE | Calendar / header Jalali markers present |
| IRR / Toman | DONE | Profiles: retail IRR; service TOMAN (ledger note: IRR functional) |
| isolation | DONE | Calendar filter company 1 |
| negative perms | DONE | Limited viewer trading access without purchase.manage |
| CSRF | DONE | No/bad CSRF → **HTTP 419** `CSRF token mismatch` |
| errors / empty | DONE | Empty/blocked cockpits acceptable |
| one E2E UI action | DONE | Company-scoped calendar navigation |

## Screenshots (real only)
Under `docs/ai/rc1/evidence/browser/` (refreshed 2026-11-22):
- `01-login-jalali-calendar.png`
- `02-trading-cockpit-rtl-empty.png`
- `03-manufacturing-cockpit-blocked.png`
- `04-service-empty.png`
- `05-retail-irr-profile.png`
- `06-company-isolation-calendar.png`
- `07-service-toman-profile.png`
- `08-logout-login.png`
- `09-limited-viewer-trading.png`
- `csrf_probe_rerun.log`
- `local_http_uat_rerun.json`
- `local_uat_playwright_rerun.json`
- `session.log`

## Classification
- **Browser UAT:** `LOCAL_BROWSER_VERIFIED`
- **Staging UAT:** **NOT** `STAGING_VERIFIED` (no staging URL provisioned)
- **SYS-1 recommendation:** Browser residual may be recorded as `LOCAL_BROWSER_VERIFIED`. Do **not** mark SYS-1 contract `PASS` for staging/production acceptance.

## Owner actions still required for `STAGING_VERIFIED`
1. Provide authenticated **non-prod staging URL** + DB credentials for this SHA (or agreed deploy).
2. Sign `RC1-STAGING-REMOTE-AUTH-CHECKLIST.md`.
3. Re-run the same case table against staging; capture screenshots under `docs/ai/rc1/evidence/browser/staging/`.
4. Confirm disposable credentials / CSRF session on staging (not production/cPanel).
