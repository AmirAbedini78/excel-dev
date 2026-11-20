# T6 Browser / Screenshot Notes

**Date:** 2026-11-20 (P1 closure — T0 local tools)  
**Environment:** Windows system-2; Docker MySQL `erpsmart-p2p-mysql-test` / `02eb215a87f6…` / `server_uuid=a73a8a84-e4bd-11f1-ad33-7e073bee960b` available; **host PHP CLI not installed**; Cursor browser tabs empty; **no authenticated staging URL** provisioned for RC1 cockpits.

## Status: `BLOCKED_EXTERNAL` (RC1-P1-004)

Real authenticated browser walkthrough was **not** completed. Code inspection and PHP harness E2E are **not** substitutes for SYS-1 UI evidence.

### Exact missing dependencies
1. Host or containerized **HTTP** app serving `index.php` with session + seeded login for RC1 workspace/company  
2. Known test credentials + CSRF-capable session cookie  
3. Staging-like URL reachable by Cursor browser / UAT operator  
4. Screenshot capture under `docs/ai/rc1/screenshots/`

### Attempted this closure
- Cursor IDE browser tab list: **empty** (no staging URL to attach).  
- No production/cPanel deploy (forbidden this campaign).  
- Declined to invent screenshots or synthetic login evidence.

### UAT procedure (owner / staging)
1. Deploy/serve this SHA on disposable staging-like instance (not production).  
2. Login → select company A → open `index.php?page=trading_cockpit` (RTL Persian, Jalali/IRR-Toman labels).  
3. Perform one trading write path already covered by TR-1 figures (or next-action link) without inventing Paid/Bank Reconciled.  
4. Open `index.php?page=manufacturing_cockpit`; confirm BOM/plan or empty states; QC/issue/FG blocked banners when evidence missing.  
5. Service/retail profile company: confirm manufacturing not forced.  
6. Switch to company B (or second tenant) → confirm company A documents hidden.  
7. As role lacking `accounting.purchase.manage`, confirm write controls hidden/disabled.  
8. POST forged `action=ui_cockpit_post_stock` without CSRF → expect 403.  
9. Capture screenshots:
   - `docs/ai/rc1/screenshots/trading-cockpit-rtl.png`
   - `docs/ai/rc1/screenshots/manufacturing-cockpit-blocked.png`
   - `docs/ai/rc1/screenshots/company-isolation.png`
   - `docs/ai/rc1/screenshots/unauthorized-write-403.png`

## Screenshots
**NOT_RUN** — directory empty; do not treat synthetic/faked images as evidence.
