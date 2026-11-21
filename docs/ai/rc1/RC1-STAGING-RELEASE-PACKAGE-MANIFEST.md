# RC1 Staging Release Package Manifest

**Agent B deliverable** — reproducible staging artifact metadata (no deploy authorization).

---

## Release identity

| Field | Value |
|-------|--------|
| Repository | `AmirAbedini78/excel-dev` |
| **Immutable product SHA** | **`2c1cf6592f246553c66c3bc90d00385bf65d4c29`** |
| Short pin | `2c1cf65` |
| Closure | PR #36 merged (`fix/rc1-final-legacy-data-ci-closure`) |
| Schema (repo constant) | `10.24.0-rc1` (`RuntimeCache::SCHEMA_VERSION`) |
| Functional currency | IRR (Toman display optional) |
| RC1 engineering decision | `RC1_ACCEPTED` (on `main` at product SHA) |

### Explicit non-claims

| Label | Status |
|-------|--------|
| **STAGING_VERIFIED** | **NO** — remote staging auth not granted |
| **PRODUCTION_READY** | **NO** |
| **PRODUCTION_DEPLOYED** | **NO** |
| Local browser class | `LOCAL_BROWSER_VERIFIED` (separate from this manifest) |

---

## Package artifact

| Field | Guidance |
|-------|----------|
| Suggested filename | `erpsmart-rc1-staging-2c1cf65.zip` |
| Build command | `git archive --format=zip -o erpsmart-rc1-staging-2c1cf65.zip 2c1cf6592f246553c66c3bc90d00385bf65d4c29` |
| Integrity doc | `RC1-STAGING-PACKAGE-INTEGRITY.md` (excludes, PHP/MySQL, cache) |
| Prior measured ZIP (T0, same SHA, local) | ~2 119 407 bytes; SHA-256 `6F7E7011B9D3FF834A392E9004476E91981C30FF5C900857B1B6771D66DF6380` — **recompute** on every new archive |

---

## How to compute file hashes

### A. Whole-archive SHA-256 (release boundary)

**PowerShell:**

```powershell
Get-FileHash -Algorithm SHA256 erpsmart-rc1-staging-2c1cf65.zip
```

**POSIX:**

```bash
sha256sum erpsmart-rc1-staging-2c1cf65.zip
```

### B. Per-file manifest inside extracted tree (reproducibility audit)

Use the repo helper (no network):

```bash
python scripts/rc1_staging_package_hash.py --root ./erpsmart-rc1-staging-extract
```

Outputs:

- `package_root` path  
- `file_count` / `total_bytes` (excluding secret patterns)  
- `manifest_sha256` — SHA-256 over sorted `relative_path<TAB>file_sha256` lines  

For a **ZIP without extracting**:

```bash
python scripts/rc1_staging_package_hash.py erpsmart-rc1-staging-2c1cf65.zip
```

### C. Git tree identity (source, not transport)

```bash
git rev-parse 2c1cf6592f246553c66c3bc90d00385bf65d4c29
git archive --format=tar 2c1cf6592f246553c66c3bc90d00385bf65d4c29 | sha256sum
```

Tar digest differs from ZIP digest; record which format you ship.

---

## Dependency inventory

### Runtime (staging host — required to run app)

| Component | Version / notes |
|-----------|-----------------|
| **PHP** | **8.2+** (8.2 reference in CI/Docker) |
| PHP extensions | `pdo_mysql`, `json`, `mbstring`, `openssl`, `session`, `hash`, `zip`, `xml`/`dom`/`simplexml`, `curl`, `fileinfo` — see integrity doc |
| **MySQL** | **8.0.x**, `utf8mb4_unicode_ci` |
| Web server | Apache/nginx + mod_rewrite or equivalent (`.htaccess` present under `storage/`) |
| Composer | **None** — project is not Composer-managed |

### Quality gates (build/CI — not required on cPanel runtime)

| Component | Version | Where used |
|-----------|---------|------------|
| **Python** | **3.12+** | `scripts/release_gate.py`, `scripts/rc1_ci_mysql_php_gate.py`, legacy rehearsal scripts |
| Python pip | `pymysql` | RC1 MySQL+PHP GHA job |
| **Node.js** | **22** | Commercial MVP gate — `node --check` on `assets/**/*.js` |
| **Docker** | Optional locally | `erpsmart-o2c-php:8.2` image build; MySQL 8.0 service in GHA |
| PHP CLI | Optional on Windows | `release_gate.py --require-php` or Docker lint batch |

### Optional / cloud-only (exclude from staging package)

| Component | Notes |
|-----------|--------|
| `engine/worker.py`, Ollama/Gemini/Grok | Not needed for RC1 PHP+MySQL acceptance; secrets under `engine/.env.*` must not ship |
| AI worker tokens | DB table `ai_worker_tokens` — revoke on staging |

---

## CI evidence @ product SHA (source gates)

| Workflow | Result @ `2c1cf65…` |
|----------|---------------------|
| Commercial MVP Gate | SUCCESS — [run 44931684788](https://github.com/AmirAbedini78/excel-dev/actions/runs/44931684788) |
| RC1 MySQL+PHP Gate | SUCCESS — [run 44931684872](https://github.com/AmirAbedini78/excel-dev/actions/runs/44931684872) |

**Note:** Commercial MVP alone is **insufficient** for RC1 domain E2E; both gates on this SHA are the pinned pair in `RC1-RELEASE-MANIFEST.md`.

---

## Package contents summary (logical)

| Include | Exclude |
|---------|---------|
| `app/`, `assets/`, `index.php`, `install.php`, `cron.php`, `ai_api.php`, scripts, tests, docs, `.github/` | `app/config.php`, dumps, `storage/cache/runtime_cache/**`, `__pycache__`, local evidence temps, engine secrets |

Full exclude table: `RC1-STAGING-PACKAGE-INTEGRITY.md` §2.

---

## Operator sign-off block (blank)

| Check | Owner initials / date |
|-------|------------------------|
| `git rev-parse` == `2c1cf659…` | |
| Archive SHA-256 recorded | |
| No secrets in ZIP (spot check / hash script) | |
| Staging DB isolated from production | |
| `app/config.php` created on host only | |
| **`STAGING_VERIFIED`** | **Not claimed in this manifest** |

---

## References

- `RC1-RELEASE-MANIFEST.md`
- `RC1-STAGING-PACKAGE-INTEGRITY.md`
- `app/config.sample.php`
- `app/bootstrap.php` → `RuntimeCache::boot`, schema gate
- `app/Core/RuntimeCache.php`

**Manifest version:** Agent B staging pre-flight · 2026-11-21