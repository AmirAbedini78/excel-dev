# ع¯ط²ط§ط±ط´ ط¢ظ…ط§ط¯ع¯غŒ Staging — ERPSMART RC1

**طھط§ط±غŒط®:** غ²غ°غ²غ¶-غ±غ±-غ²غ±  
**ظ…ط§ظ„ع© غŒع©ظ¾ط§ط±ع†ظ‡â€Œط³ط§ط²غŒ:** T0 Release Engineering / Staging Acceptance  
**SHA ط¨ط³طھظ‡ظ” staging (ظ…ط±ط¬ط¹ ظˆط§ط­ط¯):** `14fcf69c669f63ad33dc40f6e10043aa580e04d0`  
**ظ¾ط§غŒظ‡ظ” ظ…ط­طµظˆظ„ (ظ†غŒط§غŒ ظˆغŒعکع¯غŒ):** `2c1cf6592f246553c66c3bc90d00385bf65d4c29`  
**ظ¾ط°غŒط±ط´ ظ…ظ‡ظ†ط¯ط³غŒ RC1:** `RC1_ACCEPTED`  
**طھظˆظ„غŒط¯:** `NOT_AUTHORIZED` / `NOT_DEPLOYED`  
**ط¯ط³طھط±ط³غŒ remote staging:** **ظ…ط¬ط§ط² ظ†ط´ط¯ظ‡** (ظپظ‚ط· ط¨ط±ظ†ط§ظ…ظ‡)  
**Pre-flight ط§ط¬ط±ط§:** executor ظ…ظ‡ط§ط¬ط±طھ staging + ظ¾ع©غŒط¬ + UAT ط±ظˆغŒ ط´ط§ط®ظ‡ظ” ط¢ظ…ط§ط¯ظ‡â€Œط³ط§ط²غŒ — **ط¨ط¯ظˆظ†** ط§ط¯ط¹ط§غŒ `STAGING_VERIFIED`

> `RC1_ACCEPTED` â‰  `PRODUCTION_READY` â‰  `STAGING_VERIFIED`

**ط§ط¨ط²ط§ط±ظ‡ط§غŒ pre-flight (ط¨ط¯ظˆظ† remote):** `scripts/rc1_staging_schema_migrate.php` آ· `RC1-STAGING-REMOTE-AUTH-CHECKLIST.md` آ· `RC1-STAGING-PACKAGE-INTEGRITY.md` آ· `RC1-STAGING-UAT-PROCEDURES.md`

---

## غ±. ظ‡ظˆغŒطھ ظ¾ط§غŒظ‡ ظˆ ط§ظ†ط¬ظ…ط§ط¯ ظˆغŒعکع¯غŒ

| ظ…ظˆط±ط¯ | ظ…ظ‚ط¯ط§ط± |
|------|--------|
| ظ…ط®ط²ظ† | `AmirAbedini78/excel-dev` |
| ط´ط§ط®ظ‡ | `main` |
| SHA ط¨ط³طھظ‡ظ” staging | `14fcf69c669f63ad33dc40f6e10043aa580e04d0` |
| ظ¾ط§غŒظ‡ظ” ظ…ط­طµظˆظ„ | `2c1cf659…` + PR #38 + safety patch |
| ط§ط¯ط؛ط§ظ… ظ…ط±ط¬ط¹ ظ…ط­طµظˆظ„ | PR #36 MERGEDط› safeguards: PR #38 + ط§غŒظ† ظ¾ع† |
| ظ†ط³ط®ظ‡ظ” ط§ط³ع©غŒظ…ط§ | `10.24.0-rc1` |
| Feature freeze | ظپط¹ط§ظ„ — ظپظ‚ط· ظ†ظ‚طµâ€Œظ‡ط§غŒ ط¢ظ…ط§ط¯ع¯غŒ staging |

## غ². ظˆط¶ط¹غŒطھ CI ظ¾ط³ ط§ط² ط§ط¯ط؛ط§ظ… (ط±ظˆغŒ ظ‡ظ…ط§ظ† SHA)

| ط¯ط±ظˆط§ط²ظ‡ | ظ†طھغŒط¬ظ‡ | Run |
|--------|--------|-----|
| Commercial MVP Gate | **SUCCESS** | https://github.com/AmirAbedini78/excel-dev/actions/runs/44931684788 |
| RC1 MySQL+PHP Gate | **SUCCESS** | https://github.com/AmirAbedini78/excel-dev/actions/runs/44931684872 |

Commercial MVP ط¨ظ‡â€Œطھظ†ظ‡ط§غŒغŒ ط¨ط±ط§غŒ RC1 ع©ط§ظپغŒ ظ†غŒط³طھط› ط¯ط±ظˆط§ط²ظ‡ظ” MySQL+PHP ط¨ط§ fail-on-skip ط§ظ„ط²ط§ظ…غŒ ط§ط³طھ ظˆ ط±ظˆغŒ ط§غŒظ† SHA ط³ط¨ط² ط§ط³طھ.

## غ³. ط¢ط±طھغŒظپع©طھ ط§ظ†طھط´ط§ط± ظˆ PHP/MySQL

| ظ…ظˆط±ط¯ | ظˆط¶ط¹غŒطھ |
|------|--------|
| ظ…ظ†ط¨ط¹ طھط؛غŒغŒط±ظ†ط§ظ¾ط°غŒط± | `git archive` / checkout ط§ط² SHA ط¨ط§ظ„ط§ |
| ط³ط§ط®طھ ظ¾غŒط´ظ†ظ‡ط§ط¯غŒ | `git archive --format=zip -o erpsmart-rc1-staging.zip 14fcf69c669f63ad33dc40f6e10043aa580e04d0` (ط¨ط¯ظˆظ† `.env` / dump / `storage/cache` ظ…ط­ظ„غŒ) |
| ط³ط§ط®طھظ‡â€Œط´ط¯ظ‡ ط§غŒظ† ظ¾ط§ط³ | ظ¾ط³ ط§ط² ط§ط¯ط؛ط§ظ… ظ¾ع† ط§غŒظ…ظ†غŒطŒ ZIP ط±ط§ ط§ط² SHA ط¨ط³طھظ‡ ط¨ط§ط²ط³ط§ط²غŒ ظˆ checksum ط±ط§ ط«ط¨طھ ع©ظ†غŒط¯ — digest ظ‚ط¯غŒظ…غŒ `2c1cf65` ظ…ط¹طھط¨ط± ظ†غŒط³طھ |
| PHP | **8.2** (`erpsmart-o2c-php:8.2`ط› ext: `pdo_mysql`) |
| MySQL | **8.0** (طھطµظˆغŒط± `mysql:8.0` ط¯ط± GHA ظˆ sandbox ظ…ط­ظ„غŒ) |
| ط§ط±ط² ع©ط§ط±ع©ط±ط¯غŒ | IRR (ظ†ظ…ط§غŒط´ طھظˆظ…ط§ظ† ط§ط®طھغŒط§ط±غŒ) |

## غ´. ط¢ظ…ط§ط¯ع¯غŒ DB / ظ…ظ‡ط§ط¬ط±طھ / طھط·ط¨غŒظ‚ ط±ع©ظˆط±ط¯غŒ

| ظ…ظˆط±ط¯ | ط·ط¨ظ‚ظ‡ / ظ†طھغŒط¬ظ‡ |
|------|----------------|
| ط§ط³طھظ‚ط±ط§ط± ظ‚ط¨ظ„غŒ | `EXISTING_DEPLOYMENT_CONFIRMED` |
| Path2 dump ظ†ط§ط´ظ†ط§ط³ | `PATH2_ANON_SCHEMA_DATA_VERIFIED` (ط®ط§ط±ط¬ ط§ط² Gitط› leak_scan PASS) |
| migrateأ—2 ط±ظˆغŒ clone غŒع©â€Œط¨ط§ط±ظ…طµط±ظپ | **PASS** (غ±غ±غ°â†’غ±غ¸غ² ط¬ط¯ظˆظ„) |
| طھط·ط¨غŒظ‚ ظ…ط§ظ„غŒ/ظ…ظˆط¬ظˆط¯غŒ | **PASS** — vouchers غ´غ· / lines غ±غ±غ·ط› Dr=Cr `18063580000.00`ط› stock qty `4.0000`ط› inventory qty `1094.0000`ط› mismatch=0 |
| `SCHEMA_UPGRADE_TESTED` | ظپظ‚ط· disposable Path2 |
| ظ…ط³غŒط± migrate | CLI/ط¢ظپظ„ط§غŒظ† ط§ظ¾ط±ط§طھظˆط± — **ظ†ظ‡** ط§ظˆظ„غŒظ† HTTP hit |
| Preflight ع©ط´ ط§ط³ع©غŒظ…ط§ | ظ¾ط§ع©â€Œط³ط§ط²غŒ ظ‡ط¯ظپظ…ظ†ط¯ `schema.json` ط¨ط±ط§غŒ fingerprint ظ‡ظ…ط§ظ† DB rehearsal |
| `FRESH_INSTALL` | **ظپط±ط¶ ظ†ط´ط¯ظ‡** |

ط´ظˆط§ظ‡ط¯: `ops1_legacy_data_rehearsal.json`, `ops1_legacy_data_reconciliation.json`, `agent_c_provenance_status.json`, `RC1-MIGRATION-PREFLIGHT.md`.

## غµ. ظ¾ط´طھغŒط¨ط§ظ† / ط¨ط§ط²غŒط§ط¨غŒ / Rollback

| Drill | ظ†طھغŒط¬ظ‡ |
|-------|--------|
| Dump/restore ظ…ظ…طھط§ط² + migrateأ—2 | **PASS** (`ops1_backup_restore_closure.json`) |
| Restore ط¨ط§ ع©ط§ط±ط¨ط± ظ…ط­ط¯ظˆط¯ ط¨ط¯ظˆظ† grant | `FAIL_ACCESS_DENIED_EXPECTED` |
| Rollback rehearsal | restore ط§ط² dump ط¨ظ‡ ط§ط³ع©غŒظ…ط§غŒ ط¬ط¯ط§ (DDL طھط±ط§ع©ظ†ط´غŒ ظ†غŒط³طھ) |
| Runbook | `RC1-ROLLBACK-RUNBOOK.md` — ظپظ‚ط· disposableط› طھظˆظ„غŒط¯ ط®ط§ط±ط¬ ط§ط² ط¨ط§ظ†ط¯ ظ…ط§ظ„ع©/DBA |

## غ¶. UAT ظ…ط±ظˆط±ع¯ط± ع©ط³ط¨â€Œظˆع©ط§ط±

| ط·ط¨ظ‚ظ‡ | ظˆط¶ط¹غŒطھ |
|------|--------|
| `LOCAL_BROWSER_VERIFIED` | **ط¨ظ„ظ‡** — غ¹ PNG ظˆط§ظ‚ط¹غŒ + ظ„ط§ع¯ CSRF/session |
| `STAGING_VERIFIED` | **ط®غŒط±** — URL staging ظˆ ظ…ط¬ظˆط² remote ط¯ط§ط¯ظ‡ ظ†ط´ط¯ظ‡ |

ظ¾ظˆط´ط´ ظ…ط­ظ„غŒ (ط´ظˆط§ظ‡ط¯ ظ‚ط¨ظ„غŒ): login/logoutطŒ tradingطŒ manufacturing (ط¨ظ†ط± GL BLOCKED)طŒ service/retailطŒ RTL/JalaliطŒ IRR/TomanطŒ ط§غŒط²ظˆظ„ظ‡ ط´ط±ع©طھطŒ ظ†ظ‚ط´ ظ…ط­ط¯ظˆط¯طŒ CSRF غ´غ±غ¹.  
ظ…ط³غŒط±: `docs/ai/rc1/evidence/browser/` ظˆ `T6-BROWSER-NOTES.md`.

## غ·. ط§ظ…ظ†غŒطھ / ط¹ظ…ظ„غŒط§طھ / ع©غŒظپغŒطھ

| ظ…ظˆط±ط¯ | غŒط§ط¯ط¯ط§ط´طھ |
|------|---------|
| Secret scan (release_gate) | ط§ظ„ع¯ظˆغŒ طھظˆع©ظ†/ع©ظ„غŒط¯ ط®طµظˆطµغŒ ط±ظˆغŒ ظپط§غŒظ„â€Œظ‡ط§غŒ tracked |
| Dump ط®ط§ظ… / PII | **ظ…ظ…ظ†ظˆط¹ ط¯ط± Git**ط› ع©ط§ظ†ط¯غŒط¯ dump = UNSAFE (ظپظ‚ط· offline) |
| ط±ع¯ط±ط³غŒظˆظ† TR-1..OPS-1 | GHA RC1 gate SUCCESS ط¨ط§ fail-on-skip ط±ظˆغŒ SHA ظ†ظ‡ط§غŒغŒ |
| ظ‡ظ…ط²ظ…ط§ظ†غŒ MF | طھط³طھ concurrent issue: غŒع© ط¨ط±ظ†ط¯ظ‡ + غŒع© ط±ط¯ط› ظ…ظˆط¬ظˆط¯غŒ ظ…ظ†ظپغŒ ظ†ظ…غŒâ€Œط´ظˆط¯ |
| ظ…غŒط²ط¨ط§ظ†غŒ ط§ط´طھط±ط§ع©غŒ/cPanel | غŒط§ط¯ط¯ط§ط´طھâ€Œظ‡ط§غŒ OPS1ط› ظ‡غŒع† mutation ط±ظˆغŒ cPanel ط¯ط± ط§غŒظ† ع©ط§ط±ط²ط§ط± |

## غ¸. ط¨ط±ظ†ط§ظ…ظ‡ ط§ط³طھظ‚ط±ط§ط± Staging (ظپظ‚ط· ظ…ط³طھظ†ط¯ط§طھ — ط¨ط¯ظˆظ† remote)

1. **ظ…ط¹ظ…ط§ط±غŒ ظ‡ط¯ظپ:** ط³ط§ط¨â€Œط¯ط§ظ…غŒظ†/ط§ع©ط§ظ†طھ ط؛غŒط±طھظˆظ„غŒط¯ ط¬ط¯ط§ط› DB staging ط¬ط¯ط§ ط§ط² production MySQL.  
2. **ط¨ط³طھظ‡:** ZIP ط§ط² SHA `2c1cf65…` ط¨ط¯ظˆظ† secrets.  
3. **ظ¾غŒع©ط±ط¨ظ†ط¯غŒ:** `app/config.php` gitignoredط› `app_key` ط¬ط¯غŒط¯ stagingط› ظ‡ط±ع¯ط² ع©ظ¾غŒ production secrets.  
4. **DB:** restore dump ظ†ط§ط´ظ†ط§ط³ غŒط§ seed ظ…طµظ†ظˆط¹غŒ â†’ `scripts/rc1_staging_schema_migrate.php` (ظ†ظ‡ `install.php` / ظ†ظ‡ ط§ظˆظ„غŒظ† HTTP) â†’ طھط£غŒغŒط¯ schema cache ظپظ‚ط· staging.  
5. **Maintenance:** ظ‚ظپظ„ `storage/maintenance/rc1_offline_migrate.lock` (غµغ°غ³ ظˆط¨ طھط§ ط§طھظ…ط§ظ… migrate)ط› ظ‚ط·ط¹ cron/worker/SMS/ظ¾ط±ط¯ط§ط®طھ ط²ظ†ط¯ظ‡ ط±ظˆغŒ staging.  
6. **Smoke/UAT:** ط·ط¨ظ‚ `RC1-STAGING-UAT-PROCEDURES.md` ظˆ ط§ط³ع©ظ„طھ Playwright `tests/staging_uat/` (ظپظ‚ط· ط¨ط§ `STAGING_BASE_URL` ظ…ط¬ط§ط²).  
7. **Rollback:** ط¨ط§ط²ع¯ط±ط¯ط§ظ†غŒ dump pre-migrate + ع©ط¯ SHA ظ‚ط¨ظ„غŒ (`RC1-ROLLBACK-RUNBOOK.md`).  
8. **Go/No-Go:** ط¨ط¯ظˆظ† ظ…ط¬ظˆط² طµط±غŒط­ ظ…ط§ظ„ع© ط·ط¨ظ‚ `RC1-STAGING-REMOTE-AUTH-CHECKLIST.md` â†’ ظ‡غŒع† طھط؛غŒغŒط± remote.  
9. **ظ¾ع©غŒط¬:** `RC1-STAGING-PACKAGE-INTEGRITY.md` + `RC1-STAGING-RELEASE-PACKAGE-MANIFEST.md`.

## غ¹. ظ…ط­ط¯ظˆط¯غŒطھ MF ظˆ ط§ط¯ط¹ط§ظ‡ط§غŒ ظ…ظ…ظ†ظˆط¹

- WIP/FG valuation ط¯ط± RC1 **ع©ط§ظ…ظ„ ظ¾ط´طھغŒط¨ط§ظ†غŒ ظ†ظ…غŒâ€Œط´ظˆط¯**ط› ط·ط¨ظ‚ظ‡ ظ‡ط²غŒظ†ظ‡ ظ…غŒâ€Œطھظˆط§ظ†ط¯ `UNVALUED` ط¨ط§ط´ط¯.  
- `WIP/FG GL = BLOCKED` — ط¨ط¯ظˆظ† GL ط®ظˆط¯ع©ط§ط± WIP/FG (`T5-VALUATION-DECISION.md`).  
- ظ‡غŒع† ط§ط¯ط¹ط§غŒ ط§ظ†ط·ط¨ط§ظ‚ ظ…ظ‚ط±ط±ط§طھغŒ ط§غŒط±ط§ظ†غŒ (ظ…ط§ظ„غŒط§طھ/طµغŒط§ط¯/ط¨ط§ظ†ع© ط²ظ†ط¯ظ‡) ط¯ط± ط§غŒظ† ع©ط§ط±ط²ط§ط± ط³ط§ط®طھظ‡ ظ†ط´ط¯ظ‡.  
- `RC1_ACCEPTED` ط¨ظ‡â€Œظ…ط¹ظ†غŒ `PRODUCTION_READY` ظ†غŒط³طھ.

## غ±غ°. ط§ظ‚ط¯ط§ظ… ظ…ط§ظ„ع© ظˆ ط­ع©ظ…

### ط¨ط±ط§غŒ `STAGING_VERIFIED` ظ„ط§ط²ظ… ط§ط³طھ
1. ظ…ط¬ظˆط² طµط±غŒط­ remote staging (URL + ط¯ط³طھط±ط³غŒ ط؛غŒط±طھظˆظ„غŒط¯).  
2. ط§ط³طھظ‚ط±ط§ط± ط¨ط³طھظ‡ ط§ط² SHA `2c1cf65…` ط±ظˆغŒ staging ط¬ط¯ط§.  
3. UAT ظ…ط±ظˆط±ع¯ط± ظˆط§ظ‚ط¹غŒ ط±ظˆغŒ staging ط¨ط§ ط§ط³ع©ط±غŒظ†â€Œط´ط§طھ ط¬ط¯غŒط¯ ط²غŒط± `evidence/browser/staging/`.  
4. طھط£غŒغŒط¯ migrate ط¢ظپظ„ط§غŒظ† ط±ظˆغŒ DB staging ظˆ smoke checklist.

### ط­ع©ظ… ط§غŒظ† ظ¾ط§ط³
ط¨ظ‡ ط¨ط®ط´ ط§ظ†طھظ‡ط§غŒغŒ ع¯ط²ط§ط±ط´ غŒع©ظ¾ط§ط±ع†ظ‡ظ” T0 ظ…ط±ط§ط¬ط¹ظ‡ ط´ظˆط¯ (غŒع© ظ…ظ‚ط¯ط§ط± ط§ط²: `STAGING_READY` | `STAGING_VERIFIED` | `STAGING_BLOCKED`).
