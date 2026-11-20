# T6 Browser / Screenshot Notes

**Date:** 2026-11-20  
**Environment:** local worktree `rc1-t6` @ G0 + T6 patches  
**Browser automation:** not run against a live logged-in app in this lane (no local web server + session provisioned for RC1 cockpits).

## Manual acceptance checklist (when test app is up)
1. Login → company select → open `index.php?page=trading_cockpit`
2. Confirm RTL Persian labels, metric strip, empty state **or** PO pipeline rows
3. Follow next-action link PO → P2P → inventory → treasury without inventing Paid/Bank Reconciled
4. Open `index.php?page=manufacturing_cockpit`
5. Confirm BOM/plan tables or empty states; QC/issue/FG blocked banners when evidence missing
6. As a role lacking `accounting.purchase.manage`, confirm write buttons hidden/disabled in cockpit next-actions
7. POST forged `action=ui_cockpit_post_stock` → expect 403

## Screenshots
Deferred until T7/local stack serves authenticated pages. Place files under `docs/ai/rc1/screenshots/` when captured:
- `trading-cockpit-rtl.png`
- `manufacturing-cockpit-blocked.png`
