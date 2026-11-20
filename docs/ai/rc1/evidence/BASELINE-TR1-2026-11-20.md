# Baseline TR-1 evidence (pre-lane merge)

**Date:** 2026-11-20  
**SHA tested:** `039e15b` (release/erpsmart-rc1) / domain code from baseline `db6d6e5`  
**Command:** `python -m unittest tests.test_ap_trading_php_e2e_1023 -v`  
**Env:** `O2C_DB_HOST=host.docker.internal` `O2C_DB_PORT=3307` `O2C_DB_NAME=erpsmart_p2p_test` `O2C_DB_USER=p2p`  
**Sandbox identity:** container `erpsmart-p2p-mysql-test` / `02eb215a87f6a726cf4efb1546f4b09865c9eb6eec9de1e9dc0e6b3163672db6`  
**PHP:** Docker `erpsmart-o2c-php:8.2`

## Result
```
test_domestic_trading_settle_6000 ... ok
Ran 1 test in 135.037s
OK
```

## Contract amounts
PO 10×1000 IRR → GR 6 → invoices 4+2 → AP 6000 → pay 2500 (remain 3500) → pay 3500 (remain 0). No invented VAT.

## Status
DATABASE_TESTED: YES (disposable sandbox)  
STAGING/PROD: NOT_DEPLOYED  
Note: This proves pre-existing v10.23 kernel on RC1 baseline; T3/T7 must re-prove after integration merges.
