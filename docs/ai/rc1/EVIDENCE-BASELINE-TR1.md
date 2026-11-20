# Baseline TR-1 Evidence (pre-integration)

**Date:** 2026-11-20  
**Branch:** `release/erpsmart-rc1` @ `039e15b` (post-G0 docs)  
**Command:** `python -m unittest tests.test_ap_trading_php_e2e_1023 -v`  
**Result:** PASS (1 test, 135.037s)

## Sandbox identity
- Container: `erpsmart-p2p-mysql-test`
- ID: `02eb215a87f6a726cf4efb1546f4b09865c9eb6eec9de1e9dc0e6b3163672db6`
- Image: `mysql:8.0` (8.0.46)
- Port: `3307`
- DB: `erpsmart_p2p_test`
- PHP: Docker `erpsmart-o2c-php:8.2`

## Scenario
PO 10×1000 IRR → GR 6 → inv 4+2 → AP 6000 → pay 2500 (remain 3500) → pay 3500 (remain 0). No VAT invented.

## Status
DATABASE_TESTED on disposable sandbox. Staging/prod: NOT_DEPLOYED.  
Re-prove on final RC1 SHA after lane merges.
