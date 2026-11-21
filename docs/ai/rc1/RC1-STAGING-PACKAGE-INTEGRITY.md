# RC1 Staging Package Integrity (Agent B)

**Campaign:** ERPSMART RC1 Staging Safety Patch — Security & package audit  
**Authoritative staging-release SHA:** `POST_MERGE_MAIN_TIP_CI_GREEN`  
**Product feature baseline (ancestry only):** `2c1cf6592f246553c66c3bc90d00385bf65d4c29`  
**Schema constant:** `RuntimeCache::SCHEMA_VERSION = 10.24.0-rc1`  
**Date:** 2026-11-21  

**Status labels (mandatory):** This document does **not** authorize deploy. **`NOT STAGING_VERIFIED`** آ· **`NOT PRODUCTION_READY`**.  
`RC1_ACCEPTED` on product baseline â‰  staging host proof. Product-only `2c1cf65` ZIP is **not** the staging executable.

---

## 1. Reproducible export from immutable SHA

### 1.1 Verify ref (offline)

```powershell
cd C:\Projects\excel-dev   # or your clone path
git fetch origin
git rev-parse POST_MERGE_MAIN_TIP_CI_GREEN^{commit}
# Must print: POST_MERGE_MAIN_TIP_CI_GREEN
```

### 1.2 `git archive` (preferred)

Produces a **clean tree at exactly one commit** — no dirty working-tree files.

```powershell
git archive --format=zip `
  -o erpsmart-rc1-staging.zip `
  POST_MERGE_MAIN_TIP_CI_GREEN
```

POSIX:

```bash
git archive --format=zip \
  -o erpsmart-rc1-staging.zip \
  POST_MERGE_MAIN_TIP_CI_GREEN
```

Record archive SHA-256 (see آ§1.4 and `RC1-STAGING-RELEASE-PACKAGE-MANIFEST.md`).

### 1.3 Alternative: `git checkout` + clean copy

Use only if archive is unavailable:

1. `git worktree add ../erpsmart-rc1-export POST_MERGE_MAIN_TIP_CI_GREEN`
2. Copy tree **after** applying the exclude list (آ§2) into a fresh ZIP.
3. Remove worktree when done.

**Never** zip the developer's live `storage/cache`, local `app/config.php`, or untracked evidence temps.

### 1.4 Package fingerprint

| Field | Example (prior T0 rehearsal @ same SHA; **recompute** on each build) |
|-------|------------------------------------------------------------------------|
| Staging-release git SHA | `POST_MERGE_MAIN_TIP_CI_GREEN` |
| Product baseline (ancestry) | `2c1cf6592f246553c66c3bc90d00385bf65d4c29` |
| Archive name | `erpsmart-rc1-staging.zip` |
| Byte size | ~2.1 MB (tree-only; varies slightly with doc tips) |
| SHA-256 (whole ZIP) | Record at build time — prior doc cited `6F7E7011…6380` for one local archive |

Helper: `python scripts/rc1_staging_package_hash.py erpsmart-rc1-staging.zip`

---

## 2. Exclude list (must not ship to staging)

Apply at **extract/post-process** even if `git archive` omitted gitignored paths (archive usually **does not** include `app/config.php` because it is gitignored and untracked).

### 2.1 Always exclude

| Category | Paths / patterns |
|----------|------------------|
| Local app secrets | `app/config.php`, `.env`, `.env.*`, `engine/.env.*`, `engine/config.gemini.json`, `engine/config.cloud.json`, `engine/config.json` (if present locally) |
| Database dumps | `*.sql`, `*.sql.gz`, `*.dump`, `*backup*`, unredacted legacy exports |
| Keys / certs | `*.pem`, `*.key`, `id_rsa*`, `*.pfx`, SSH private keys |
| Runtime cache | `storage/cache/runtime_cache/**`, `storage/cache/rc1_legacy_rehearsal/**`, `**/*.cache`, `**/*.cache.lock` |
| Logs / imports / exports (runtime) | `storage/logs/*.log`, `storage/imports/*` (except `.gitkeep`), `storage/exports/*` (except `.gitkeep`) |
| Python bytecode | `**/__pycache__/**`, `**/*.pyc`, `engine/.venv/**` |
| AI engine data | `engine/data/**` |
| Browser / gate evidence temps | `docs/ai/rc1/evidence/browser/_tmp_*`, `docs/ai/rc1/evidence/browser/_cookies*.txt`, `docs/ai/rc1/evidence/**/*.html` (scratch captures), local `*_bootstrap_check.php` under evidence |
| OS / IDE | `.DS_Store`, `Thumbs.db`, `.vscode/`, `.idea/` |
| Old patch trees | `_backup_before_*`, `accounting_v3_patch/`, similar |

### 2.2 Keep in package (empty dirs)

Per `.gitignore`, ship **folder placeholders**:

- `storage/logs/.gitkeep`, `storage/imports/.gitkeep`, `storage/exports/.gitkeep`
- `storage/.htaccess`, `storage/cache/.htaccess`, `storage/uploads/.htaccess` (deny web access to cache/uploads where present)

### 2.3 Git note

There is **no** root `.gitattributes` `export-ignore` today — exclusion is **operator procedure**, not automatic in `git archive`.

---

## 3. PHP 8.2+ extensions (from repo usage)

There is **no** `composer.json`; runtime is plain PHP. CI pins **PHP 8.2** (`tests/harness/Dockerfile.o2c-php`, GHA `RC1 MySQL+PHP Gate`).

### 3.1 Required on staging host

| Extension | Why (code / gate) |
|-----------|-------------------|
| **pdo** + **pdo_mysql** | `DB::connect`, all domain `migrate()` — GHA installs only `pdo_mysql` in reference image |
| **json** | `JSON_THROW_ON_ERROR`, settings, APIs |
| **mbstring** | `mb_strlen`, `mb_strtolower`, Persian UI (`install.php`, domains) |
| **openssl** | `openssl_encrypt` / `decrypt` for stored credentials (`app/Core/Helpers.php`) |
| **session** | `session_start()` in `app/bootstrap.php` |
| **hash** | `password_hash` / `password_verify`, `hash_equals`, fingerprints |
| **zip** (`ZipArchive`) | Excel import/export (`Xlsx.php`, `DataIO.php`) — hard error if missing |
| **xml**, **dom**, **simplexml** | XLSX parsing (`simplexml_load_string` in `Xlsx.php`) |
| **curl** | Ghasedak SMS (`Notify::sendSms`) when SMS settings are filled |
| **fileinfo** | Upload MIME sniff (`FileLibrary.php`) |

### 3.2 Strongly recommended

| Extension | Why |
|-----------|-----|
| **opcache** | Performance on shared hosting; observed in `RuntimeCache::stats()` |
| **apcu** | Optional object cache hint in stats — not required for RC1 |
| **sodium** or libsodium | Not required today; crypto uses OpenSSL |

### 3.3 Verify on host (cPanel â†’ PHP Extensions or CLI)

```bash
php -r "foreach (['pdo_mysql','mbstring','openssl','zip','curl','fileinfo','dom'] as \$e) echo \$e, ': ', extension_loaded(\$e)?'ok':'MISSING', PHP_EOL;"
php -v   # >= 8.2.0
```

Reference minimal image: `FROM php:8.2-cli` + `docker-php-ext-install pdo_mysql` — **staging needs the full list above**, not only `pdo_mysql`.

---

## 4. MySQL requirements

| Requirement | Value |
|-------------|--------|
| Version | **MySQL 8.0.x** (GHA `mysql:8.0`, rehearsal disposable DBs) |
| Charset / collation | `utf8mb4` / `utf8mb4_unicode_ci` (see `app/config.sample.php`) |
| User grants | `CREATE`, `ALTER`, `INDEX`, `INSERT`, `UPDATE`, `DELETE`, `SELECT` on **staging-only** schema |
| Privileged ops | Dump/restore/migrate drills used a privileged user; restricted `p2p`-style user **must not** restore dumps (`FAIL_ACCESS_DENIED_EXPECTED` in OPS-1) |
| Migrate path | **Offline CLI** — `scripts/rc1_legacy_schema_migrate_rehearsal.php` (or owner equivalent) — **not** first HTTP hit, **not** `install.php` as upgrade path for legacy |
| Path1 | **`FRESH_INSTALL` invalid** — owner confirmed `EXISTING_DEPLOYMENT_CONFIRMED` |

---

## 5. Storage & permissions (shared hosting / cPanel)

| Path | Permission / rule |
|------|-------------------|
| `storage/cache/` | Writable by PHP user (typically `0750` dirs, `0640` files per `RuntimeCache`) |
| `storage/logs/`, `storage/imports/`, `storage/exports/`, `storage/uploads/` | Writable for runtime |
| `app/config.php` | **Not in git** — create on host only (`0750` or stricter; never world-readable) |
| Document root | Point at repo root (or `public/` if host uses subdomain docroot — match existing prod pattern) |
| `.htaccess` under `storage/` | Keep deny rules; do not expose cache/logs via HTTP |

If `storage/cache` is not writable, `RuntimeCache::boot` falls back to **system temp** — causes **wrong fingerprint / schema gate desync**; fix permissions instead of relying on temp.

---

## 6. Staging-only configuration checklist

Copy `app/config.sample.php` â†’ `app/config.php` on the host (never commit).

| Item | Staging rule |
|------|----------------|
| `base_url` | HTTPS staging URL (owner-provided) |
| `timezone` | `Asia/Tehran` (default) |
| `db.*` | **Dedicated staging** database — never production DSN |
| `app_key` | **New** 64-char hex (`bin2hex(random_bytes(32))` like `install.php`) |

### 6.1 `app_key` / encryption / sanitized legacy

- `enforce_data_persistence_guard()` (`app/Core/Helpers.php`) binds `app_key` to `settings.encryption_key_fingerprint_v4`.
- Restoring an **anonymized legacy dump**: `app_key` in staging `config.php` must match the key used when credentials in that dump were encrypted, **or** re-key credentials per runbook before go-live smoke.
- Mismatch â†’ hard `RuntimeException` (Persian message) — **expected safety behavior**.
- For disposable rehearsal DBs, use rehearsal key documented offline — never production key.

### 6.2 Session / HTTPS

- `session_start()` in bootstrap — enable **HTTPS** on staging vhost; set `session.cookie_secure = 1` and `session.cookie_samesite = Lax` (or `Strict`) via `php.ini` / cPanel MultiPHP INI.
- `Auth::login` / `logout` call `session_regenerate_id(true)`.
- CSRF: `$_SESSION['_csrf']` — local UAT logged 419 on bad token; keep sessions on single staging host only.

### 6.3 Settings to neutralize on staging

In DB `settings` (after restore) or via admin UI — clear or stub:

- `notifications_sms_to`, Ghasedak API material
- SMTP live inboxes (`smtp_username`, `smtp_password`, `notifications_email_to`)
- `edge_service_url`, `edge_service_token`
- `api_enabled` â†’ `0` if external API surface must stay dark
- Rotate `cron_secret` in settings vs production

---

## 7. Disable live side effects on staging

| Surface | Risk | Staging safe mode |
|---------|------|-------------------|
| `cron.php` | Due reminders â†’ **email/SMS** | **Do not** schedule cPanel cron hitting staging URL; or use wrong secret so 403 |
| `Notify::sendDueReminders` | SMTP + Ghasedak HTTP | Empty SMS/email targets; no API keys |
| `ai_api.php` / worker tokens | External LLM + tool execution | Do not deploy `engine/` worker to staging unless isolated; revoke worker tokens; `api_enabled=0` |
| `AiSuggestionEngine` (via cron) | DB writes | Blocked if cron disabled |
| Banking / tax / Sayad | Regulatory | **No live integration claims**; smoke uses synthetic docs only |
| Payments | External PSP | Not part of RC1 gate; keep disabled / mock |

**Production cron/worker must not point at staging DB or URL.**

---

## 8. Safe logs policy

- Application logs: `storage/logs/*.log` (gitignored) — may contain user actions; **no PII in Git**.
- Staging logs: rotate via cPanel; do not ship logs in release ZIP.
- AI / worker debug: keep `engine/` secrets out of package (آ§2).
- Error display: `display_errors=Off` on staging mirror of prod.

---

## 9. Backup / restore notes

- **Before migrate:** full mysqldump of staging DB (privileged user).
- **Rollback:** restore pre-migrate dump + redeploy **prior code SHA** — no automated down-migration in RC1 (`RC1-RELEASE-MANIFEST.md`).
- **Evidence:** disposable drills `ops1_backup_restore_closure.json` — **not** production.
- Dumps with secrets/PII: **never** commit; candidate dump marked UNSAFE for Git in migration preflight.

---

## 10. RuntimeCache isolation

Implementation: `app/Core/RuntimeCache.php`

- Cache root: `storage/cache/runtime_cache/{db_hash}/` where `db_hash` = first 16 hex of SHA-256 of `host|port|database|username`.
- **Schema gate file:** `{db_hash}/schema.json` — if present and version matches `SCHEMA_VERSION`, HTTP requests **skip** heavy migrate.

### 10.1 Safe staging operations

| Action | Allowed when |
|--------|----------------|
| Delete **one** `schema.json` under staging fingerprint | Migrate desync on **staging DB only** after failed/partial offline migrate |
| `RuntimeCache::clearWorkspace(n)` | Staging workspace cache bust |
| `RuntimeCache::clearAll()` | Clears cache blobs but **leaves** `schema.json` (see code) — still treat as staging-only |

### 10.2 Forbidden

- Deleting production `storage/cache/runtime_cache/**` from a copied tree.
- Sharing one cache directory between prod and staging DBs (different fingerprints required).
- Assuming `git archive` cleared local cache — always purge local `runtime_cache` before upload if you used checkout-copy method.

---

## 11. cPanel constraints

| Constraint | Mitigation |
|------------|------------|
| PHP selector | Choose **8.2+** and enable extensions (آ§3) |
| MySQL prefix names | Use dedicated staging DB name — never prod schema |
| Cron URL | Disable or point nowhere |
| `max_execution_time` / `memory_limit` | Offline migrate may need CLI `php` with raised limits via SSH if owner allows — **agents do not SSH** |
| `open_basedir` | Must include `storage/` and app root |
| Git deploy | If used, deploy **exact** SHA `2c1cf65…`, not floating `main` |
| No `engine/` auto-deploy | `.cpanel.yml` may deploy `ai_api.php` but not worker secrets — keep AI cloud keys off staging |

---

## 12. Related documents

- `RC1-STAGING-RELEASE-PACKAGE-MANIFEST.md` — manifest + hash procedure  
- `RC1-RELEASE-MANIFEST.md` — product identity & gates  
- `RC1-STAGING-DEPLOYMENT-PLAN.md` — plan-only deploy steps  
- `RC1-MIGRATION-PREFLIGHT.md` — offline migrate & `schema.json` pitfalls  
- `RC1-STAGING-READINESS-FA.md` — readiness (Persian summary)

**Verdict:** Package integrity checklist for owner/operator. **`NOT STAGING_VERIFIED`** until remote UAT authorized.