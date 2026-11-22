# 14 — Direct-Production Agentic AI Release Package

**Authority:** T0 (package + Go/No-Go only)  
**Owner decision:** Skip staging; prepare **controlled DIRECT-PRODUCTION** package for explicit operator approval.  
**Hard rule:** **DO NOT deploy automatically.** No live cPanel / file / DB mutation from this document.

---

## 1. Immutable release identity

| Field | Value |
|-------|--------|
| Repository | `AmirAbedini78/excel-dev` |
| **Prior Agentic AI freeze SHA** | `e9797662d85807a3a128d548e6557439800a689b` (PR #53 waves AI1–AI4 + MCP path) |
| **Stabilization overlay** | PR closing cPanel sync P0 + real legacy AI migrate + MF MySQL fixture (this package) |
| **Release tip SHA (immutable)** | `5bb1e2739905a3280d47277a57427e88c964d7ab` (merge PR #55) |
| Short | `5bb1e27` |
| `AiSchema::VERSION` | `1.2.0` |
| Agent task schema | `erpsmart.agent_task.v1` |
| MCP | **DISABLED BY DEFAULT** — do **not** set `mcp_enabled=1` in this package |

Freeze command (operator, after stabilization merge):

```bash
git fetch origin main
git rev-parse origin/main
# must equal the recorded stabilization merge SHA in §1 tip table
```

---

## 2. Package artifacts (operator cache — rebuildable)

Built from the **final reviewed tip SHA** (local evidence under `storage/cache/agentic_prod_release/`; **not** a deploy authorization).

| Artifact | Role |
|----------|------|
| `erpsmart-agentic-ai-php-<TIP>.zip` | PHP control plane + UI assets for **controlled** sync |
| `erpsmart-agentic-ai-worker-<TIP>.zip` | Separate Python Worker / engine update |

### Rebuild

```bash
SHA=<TIP_SHA>
git archive --format=zip -o erpsmart-agentic-ai-php-$SHA.zip $SHA \
  app assets sample_import \
  storage/.htaccess storage/cache/.htaccess storage/uploads/.htaccess \
  storage/exports/.gitkeep storage/imports/.gitkeep storage/logs/.gitkeep \
  storage/maintenance/.gitkeep storage/maintenance/.gitignore \
  .htaccess index.php openapi-v2.json openapi.json API_V2_SAAS_FA.md \
  api_tokens.php api_v2.php v5_api.php accounting_api.php file_download.php v4_files.php \
  api_docs.php api_token_admin.php api_v1.php io.php v3_2_api.php cron.php api.php \
  ai_api.php ai_live.php copilot_api.php mcp_api.php migrate_v2.php \
  README_FA.md INSTALL_CPanel_FA.md DEPLOY_GIT_CPanel_FA.md .cpanel.yml

git archive --format=zip -o erpsmart-agentic-ai-worker-$SHA.zip $SHA engine
python scripts/rc1_staging_package_hash.py erpsmart-agentic-ai-php-$SHA.zip
python scripts/rc1_staging_package_hash.py erpsmart-agentic-ai-worker-$SHA.zip
```

**Note:** `install.php` is intentionally **excluded** from the PHP archive allowlist used by controlled sync (no public installer overwrite).

### Recorded SHA-256 (local rebuild @ `5bb1e27`)

| Package | Archive SHA-256 | Tree manifest SHA-256 | Files |
|---------|-----------------|------------------------|------:|
| PHP | `c07610ff9fc23d9d1553fd91c7e91967d280ee23c394143bb01210bc533e03f1` | `3961543e851a75904965a9df46a42ae2b10650dd700359f93fec4c2de9bbb015` | 144 |
| Worker | `7b6927d808b4f17e8e4a546dde0d8ab75c08d3fe7f175e79cd8dbe6572604739` | `7a9b2f6498228fba245c73cee957cb3bb46ed2f7811ed6a26f1c3bf7add32e54` | 42 |

Re-verify before any operator action: `Get-FileHash -Algorithm SHA256 <zip>` / `sha256sum`.

**Secrets excluded by design:** `app/config.php`, `engine/config.json`, `engine/.env*`, SQL dumps, keys — never ship runtime secrets in the ZIP.

---

## 3. Required PHP / runtime inventory vs `.cpanel.yml`

Deploy target (audit only — **do not retarget**): `/home3/zzflgmfd/excel2.bcsrp/`

| Path / asset | Needed for Agentic AI | Prior `.cpanel.yml` | Stabilization |
|--------------|----------------------:|---------------------|---------------|
| `app/` (AiSchema, AiRagAcl, McpFacade, …) | yes | yes | via controlled sync |
| `assets/` | yes | yes | via controlled sync |
| `ai_api.php` / `ai_live.php` / `index.php` | yes | yes | via controlled sync |
| `copilot_api.php` | yes | **was NO** | **allowlisted** |
| `mcp_api.php` | yes (503 when off) | **was NO** | **allowlisted**; MCP stays off |
| `engine/` | Worker | NO | **separate Worker ZIP** |
| `storage/` user data | preserve | **P0 blind `cp -R`** | **never overwrite** |
| `app/config.php` | preserve | risk | **never overwrite** |

### Authoritative controlled deploy method

Script: `scripts/agentic_controlled_cpanel_sync.py`

| Property | Behavior |
|----------|----------|
| Default | **Non-mutating dry-run** + file manifest |
| Apply | Requires `--apply` **and** `--i-understand-production-risk` **and** destination identity marker |
| Identity | `.erpsmart_deploy_identity` must equal `excel2.bcsrp:/home3/zzflgmfd/excel2.bcsrp/` |
| Storage | Existing uploads/logs/cache bytes preserved; additive missing `.htaccess`/`.gitkeep` only |
| Config | `app/config.php` never overwritten |
| Deletes | **Never** delete unknown destination files |
| Migrations | **Not run** by this script |
| Worker | Out of scope |
| Selftest | `--selftest` disposable FS fixture — **PASS** (evidence: `storage/cache/agentic_prod_release/controlled_sync_selftest.json`) |

`.cpanel.yml` is **fail-closed** (echo + `/bin/false`) so Git auto-deploy cannot blind-overwrite storage.

---

## 4. Worker update package

| Item | Value |
|------|--------|
| Source tree | `engine/` @ tip SHA |
| Preserve on host | `engine/config.json`, `.env*`, `engine/data/**` |
| Post-update | Worker restart; register against `https://<host>/ai_api.php`; confirm node online |

---

## 5. AI DB migrations + rollback (operator-controlled; **no prod migrate in this campaign**)

| Item | Detail |
|------|--------|
| Mechanism | Cumulative migrate via `scripts/lib/rc1_cumulative_migrate.php` including `AiSchema::migrate()` |
| Target version | `ai_schema_version=1.2.0` |
| Additive RAG | `ai_rag_documents`, `ai_rag_document_acl` |
| Columns | `idempotency_key`, `dedupe_key` present |
| Permissions | `ai.use`, `ai.actions.approve`, `ai.workers.manage`, `ai.audit.view`, `ai.rag.retrieve`, `ai.rag.manage` |
| Agent task | `erpsmart.agent_task.v1` (JSON under `ai_jobs.result_json`) |

### Real anonymized legacy rehearsal — **PASS**

| Field | Value |
|-------|--------|
| Provenance | `prior-cpanel-anon-20261121` |
| Anon dump SHA-256 | `F3B92C0088A874A739FCD64BF25FA714010078C3EB95DFDF81ADC92335841DE2` |
| Dump git | **never committed** |
| Disposable MySQL | `erpsmart-p2p-mysql-test` / `@@server_uuid=c097f150-e6b0-11f1-84d8-9ad0ee01f487` / port `3307` |
| Command | `python scripts/agentic_legacy_ai_migrate_rehearsal.py` |
| Evidence | `docs/ai/agentic-next/evidence/agentic_legacy_ai_migrate_rehearsal.json` |
| Before → after | AI schema `1.1.0` → `1.2.0`; tables 110 → 184; migrate×2 OK |
| ERP recon | **PASS** (0 mismatches; vouchers/stock preserved) |
| AI recon | **PASS** (jobs/conversations preserved; RAG ACL tables added) |
| Rollback restore | **PASS** (baseline clone 110 tables / 169 ai_jobs) |
| Production migrate | **NOT_RUN** |

---

## 6. Gate results (stabilization campaign)

### Commercial MVP / RC1

| Gate | Result | Notes |
|------|--------|-------|
| Commercial MVP (`scripts/release_gate.py`) | **PASS** local | ALL SOURCE VALIDATION GATES PASSED |
| RC1 MySQL+PHP (`scripts/rc1_ci_mysql_php_gate.py`) | **PASS** local | Disposable DB ensure + MF + trading + staging executor |

### Agentic AI integration / security (local)

| Suite | Result |
|-------|--------|
| PHP Wave1 security harness | **PASS** (`T6_WAVE1_SECURITY_HARNESS_PASS`) |
| PHP agent_task state | **PASS** |
| PHP enterprise RAG ACL | **PASS** |
| PHP MCP security | **PASS** (`MCP_SECURITY_PASS`) |
| Python RAG ACL / MCP / Wave1 / Wave4 / release contracts | **PASS** |
| `test_mf_tools_mysql_unvalued_gl_blocked_and_permission` | **PASS** (was fixture fail; now creates `erpsmart_mf1_test` + GRANT) |
| Cross-company AI auth (MF permission + kernel contracts) | **PASS** |
| Disposable MySQL cross-tenant fixture suite | **SKIP** (fixture file absent — backlog; not treated as P0 cutover blocker once MF cross-company PASS) |

### Unavailable / non-mandatory

| Item | Class |
|------|--------|
| Live cloud provider qualification | backlog / owner credentials |
| Full 60/40/30 eval matrix | backlog |
| Remote staging UAT | N/A (owner skipped staging) |

---

## 7. MCP default-off proof (must remain off in this package)

| Proof | Detail |
|-------|--------|
| Code | `McpFacade::isEnabled` requires setting exactly `"1"`; default `'0'` |
| HTTP | `mcp_api.php` returns disabled/503 when unset |
| Tests | PHP + Python MCP suites **PASS** |
| Ops | Keep production `mcp_enabled` absent/`0` |

---

## 8. Pre-deploy backup / maintenance / post-deploy smoke / rollback

### Pre-deploy

- [ ] Confirm tip SHA == recorded stabilization merge
- [ ] Recompute ZIP SHA-256 match §2
- [ ] Full files + MySQL backup; record dump SHA-256
- [ ] Snapshot Worker `config.json` + data routes
- [ ] Confirm `mcp_enabled` is not `1`
- [ ] Write `.erpsmart_deploy_identity` marker on destination
- [ ] Dry-run controlled sync manifest reviewed

### Maintenance

- [ ] Enable maintenance / drain AI jobs
- [ ] Controlled PHP sync (`--apply` only after dry-run + backup)
- [ ] Worker package update + restart
- [ ] Operator-approved AiSchema migrate **only** after this disposable rehearsal sign-off

### Post-deploy smoke

- [ ] Login + dashboard
- [ ] Copilot sidecar loads
- [ ] `ai_api.php` worker register/heartbeat
- [ ] `mcp_api.php` remains disabled
- [ ] No GL/MF valuation claims; UNVALUED/GL_BLOCKED preserved

### Rollback

- [ ] Restore files from controlled-sync backup / prior SHA
- [ ] Restore DB dump if migrate applied
- [ ] Restore Worker tree + config

---

## 9. Operator Go / No-Go checklist

**Engineering P0 blockers for package safety closed in stabilization.**  
**Cutover still requires owner written approval + backup + maintenance window.**

| # | Check | P0/P1 | Status |
|---|-------|-------|--------|
| 1 | Tip SHA freeze verified after merge | P0 | **CLOSED** `5bb1e27` |
| 2 | PHP + Worker archive hashes match tip | P0 | **CLOSED** (local rebuild recorded) |
| 3 | Controlled sync excludes storage user data + `app/config.php`; `.cpanel.yml` fail-closed | P0 | **CLOSED** |
| 4 | Pre-deploy backup + dump SHA recorded | P0 | operator |
| 5 | Anonymized **real** legacy DB disposable migrate PASS | P0 | **CLOSED** |
| 6 | MCP remains disabled | P0 | **CLOSED** |
| 7 | Commercial MVP + RC1 PASS on tip | P0 | local PASS; CI on PR |
| 8 | Agentic AI PHP security suites PASS | P0 | **CLOSED** |
| 9 | MF MySQL tool UNVALUED/GL_BLOCKED PASS | P0/P1 | **CLOSED (PASS)** |
| 10 | Explicit **owner written approval** for production cutover | P0 | **OPEN** |
| 11 | Maintenance window + rollback owner named | P0 | **OPEN** |

| Decision | Mark |
|----------|------|
| **GO** (owner approves cutover under above controls) | ☐ |
| **NO-GO** (package retained; no production mutation) | ☑ **until §9 items 1–2,4,10–11 + CI** |

---

## 10. Explicit non-claims

| Label | Status |
|-------|--------|
| `PRODUCTION_DEPLOYED` | **NO** |
| Auto cPanel / DB mutation by T0 | **NO** |
| Staging skipped per owner | **YES** |
| MCP activated | **NO** |
| Full Agentic eval matrix complete | **NO** |

---

## References

- `00-MASTER-STATUS.md`
- `11-RELEASE-AND-DEPLOYMENT-PLAN.md`
- `13-MCP-OPERATOR-ENABLE-RUNBOOK.md`
- `scripts/agentic_controlled_cpanel_sync.py`
- `scripts/agentic_legacy_ai_migrate_rehearsal.py`
- `docs/ai/agentic-next/evidence/agentic_legacy_ai_migrate_rehearsal.json`
