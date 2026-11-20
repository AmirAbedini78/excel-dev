# T6 Browser / Screenshot Notes

**Date:** 2026-11-21 (T0 final RC1 acceptance closure — Agent B lane completed by T0)  
**Final main SHA:** `52fa7f12f465653d6e445d484f1a8dda48215f45`  
**Environment:** Windows system-2; Docker MySQL `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / `server_uuid=a73a8a84-e4bd-11f1-ad33-7e073bee960b` / port 3307 available; Docker PHP CLI `erpsmart-o2c-php:8.2` available.

## Classification: `BLOCKED_EXTERNAL` (not PASS)

| Label | Status |
|-------|--------|
| `LOCAL_BROWSER_VERIFIED` | **NO** |
| `STAGING_VERIFIED` | **NO** |
| Screenshots | **NOT_RUN** (no genuine captures) |

Real authenticated browser walkthrough was **not** completed. PHP harness E2E / profile smoke are **not** substitutes for SYS-1 UI evidence.

### Exact blockers (reproduced 2026-11-21)

1. **Host PHP CLI not installed** (`php` not on PATH).
2. **No `app/config.php`** in workspace (gitignored private config; required by `bootstrap.php` / `install.php` for HTTP app).
3. **No docker-compose / HTTP app image** to serve `index.php` with sessions against disposable MySQL.
4. **Cursor IDE browser tabs empty** — no staging-like URL to attach.
5. **No authenticated staging URL** + **no non-prod seeded login credentials** provisioned for RC1 cockpits.
6. Production/cPanel deploy **forbidden** this campaign.

### Cases (all blocked)

| Case | Result |
|------|--------|
| login/logout | BLOCKED — no HTTP app + credentials |
| company select | BLOCKED |
| trading + mfg cockpits | BLOCKED |
| service/retail action | BLOCKED |
| RTL / Jalali / IRR-Toman | BLOCKED |
| company isolation | BLOCKED |
| negative perms | BLOCKED |
| CSRF negative | BLOCKED |
| errors/empty states | BLOCKED |
| one E2E UI write action | BLOCKED |

### UAT procedure (owner — reproducible)

1. On a **non-prod** host, create disposable DB + `app/config.php` pointing only at that DB (never production).
2. Serve this SHA over HTTP (container or host PHP 8.2+) so `index.php` is reachable.
3. Seed non-prod user with known password + company A (trading/mfg) and company B (isolation) + service/retail profile company; role lacking `accounting.purchase.manage` for negative perms.
4. Authenticated walkthrough:
   - Login → logout → login  
   - Select company A → `index.php?page=trading_cockpit` (RTL Persian, Jalali, IRR/Toman labels)  
   - One trading UI action already covered by TR-1 figures (no inventing Paid/Bank Reconciled)  
   - `index.php?page=manufacturing_cockpit` — BOM/plan or empty; QC/issue/FG blocked banners when evidence missing  
   - Service/retail company: manufacturing not forced  
   - Switch company B → company A documents hidden  
   - Role without purchase manage → write controls hidden/disabled  
   - POST forged `action=ui_cockpit_post_stock` without CSRF → expect 403  
5. Capture genuine screenshots only:
   - `docs/ai/rc1/screenshots/trading-cockpit-rtl.png`
   - `docs/ai/rc1/screenshots/manufacturing-cockpit-blocked.png`
   - `docs/ai/rc1/screenshots/company-isolation.png`
   - `docs/ai/rc1/screenshots/unauthorized-write-403.png`
6. Until then: SYS-1 / OPS-1 browser remain **`BLOCKED_EXTERNAL`** — do **not** mark PASS.

## Screenshots

**NOT_RUN** — `docs/ai/rc1/screenshots/` absent; do not treat synthetic/faked images as evidence.
