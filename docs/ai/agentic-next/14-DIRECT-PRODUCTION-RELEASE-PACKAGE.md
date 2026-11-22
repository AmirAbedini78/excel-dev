# 14 — Direct-Production Agentic AI Release Package

**Authority:** T0 (package + Go/No-Go only)  
**Owner decision:** Skip staging; prepare **controlled DIRECT-PRODUCTION** package for explicit operator approval.  
**Hard rule:** **DO NOT deploy automatically.** No live cPanel / file / DB mutation from this document.

---

## 1. Immutable release identity

| Field | Value |
|-------|--------|
| Repository | `AmirAbedini78/excel-dev` |
| **Immutable Agentic AI tip SHA** | `e9797662d85807a3a128d548e6557439800a689b` |
| Short | `e979766` |
| Tip merge | PR **#53** (`docs(ai): local UAT rerun + MCP operator enable runbook`) |
| Includes | Waves AI1–AI4 + PR #52 MCP secure path + PR #53 docs/UAT |
| **Not** the release SHA | RC1 archive `14fcf69…` (staging pin only; **ancestor**, not full AI tip) |
| Prior claimed tip | `a53019c…` (PR #52) — superseded by `e979766` |
| `AiSchema::VERSION` | `1.2.0` |
| Agent task schema | `erpsmart.agent_task.v1` |
| MCP | **DISABLED BY DEFAULT** — do **not** set `mcp_enabled=1` in this package |

Freeze command (operator):

```bash
git fetch origin main
git rev-parse origin/main
# must equal e9797662d85807a3a128d548e6557439800a689b
```

---

## 2. Package artifacts (operator cache — rebuildable)

Built from the immutable SHA (local evidence under `storage/cache/agentic_prod_release/`; **not** a deploy authorization).

| Artifact | Role |
|----------|------|
| `erpsmart-agentic-ai-php-e9797662d85807a3a128d548e6557439800a689b.zip` | PHP control plane + UI assets for **controlled** sync |
| `erpsmart-agentic-ai-worker-e9797662d85807a3a128d548e6557439800a689b.zip` | Separate Python Worker / engine update |

### Rebuild

```bash
SHA=e9797662d85807a3a128d548e6557439800a689b
git archive --format=zip -o erpsmart-agentic-ai-php-$SHA.zip $SHA \
  app assets sample_import \
  storage/.htaccess storage/cache/.htaccess storage/uploads/.htaccess \
  storage/exports/.gitkeep storage/imports/.gitkeep storage/logs/.gitkeep \
  storage/maintenance/.gitkeep storage/maintenance/.gitignore \
  .htaccess index.php openapi-v2.json openapi.json API_V2_SAAS_FA.md \
  api_tokens.php api_v2.php v5_api.php accounting_api.php file_download.php v4_files.php \
  api_docs.php api_token_admin.php api_v1.php io.php v3_2_api.php install.php cron.php api.php \
  ai_api.php ai_live.php copilot_api.php mcp_api.php migrate_v2.php \
  README_FA.md INSTALL_CPanel_FA.md DEPLOY_GIT_CPanel_FA.md .cpanel.yml

git archive --format=zip -o erpsmart-agentic-ai-worker-$SHA.zip $SHA engine
python scripts/rc1_staging_package_hash.py erpsmart-agentic-ai-php-$SHA.zip
python scripts/rc1_staging_package_hash.py erpsmart-agentic-ai-worker-$SHA.zip
```

### Recorded SHA-256 (local rebuild 2026-11-22)

| Package | Archive SHA-256 | Tree manifest SHA-256 | Files |
|---------|-----------------|------------------------|------:|
| PHP | `a08a4117a1d336cac6e70ecfd167d7747217119cf7f6ad1a98f8838ba77a6320` | `3562b2f20e79289cabb25f348163735138d961cd4346cc06560b2e8e54a090f6` | 145 |
| Worker | `41710f7241edb7d267b2f0aeccb7aa736c2b98240179e47e45c273b8717d6066` | `7a9b2f6498228fba245c73cee957cb3bb46ed2f7811ed6a26f1c3bf7add32e54` | 42 |

Re-verify before any operator action: `Get-FileHash -Algorithm SHA256 <zip>` / `sha256sum`.

**Secrets excluded by design:** `app/config.php`, `engine/config.json`, `engine/.env*`, SQL dumps, keys — never ship runtime secrets in the ZIP.

---

## 3. Required PHP / runtime inventory vs `.cpanel.yml`

Deploy target (audit only): `/home3/zzflgmfd/excel2.bcsrp/`

| Path / asset | Needed for Agentic AI | In `.cpanel.yml` today | Notes |
|--------------|----------------------:|------------------------|-------|
| `app/` (AiSchema, AiRagAcl, McpFacade, BusinessCopilot*, AiAgentTaskState, …) | yes | **yes** (`cp -R app`) | OK |
| `assets/` (copilot / workspace JS/CSS) | yes | **yes** | OK |
| `ai_api.php` | yes | **yes** | Worker control plane |
| `ai_live.php` | yes | **yes** | Live SSE/JSON |
| `index.php` (+ `?copilot_api=1`) | yes | **yes** | Primary Copilot route |
| `copilot_api.php` | compatibility wrapper | **NO** | Gap — include in controlled sync |
| `mcp_api.php` | facade entry (503 when off) | **NO** | Keep file present; **do not enable** MCP |
| `engine/` | Worker runtime | **NO** (by design) | **Separate Worker package** |
| `storage/` | uploads/cache/logs | **yes** (`cp -R storage`) | **P0 risk** — blind overwrite of user data |
| Env `app/config.php` | runtime DB/secrets | not in git archive | **Never overwrite** from package |

### Controlled deploy plan (mandatory)

1. **Do not** treat raw `.cpanel.yml` auto-deploy as safe for this release.  
2. Sync PHP package paths **excluding** `storage/**` contents (keep only `.htaccess` / empty scaffold if missing).  
3. **Never** overwrite `app/config.php`, worker tokens, `engine/config.json`, or customer uploads.  
4. Update Worker from the **Worker ZIP** on the worker host only; restart Worker after backup of `engine/config.json` + route files.  
5. MCP stays at setting absent/`0`.

---

## 4. Worker update package

| Item | Value |
|------|--------|
| Source tree | `engine/` @ `e979766` |
| Notable modules | `worker.py`, `agent_task_state.py`, `agent_intelligence_kernel.py`, `rag.py`, `provider_gateway.py`, `commercial_hardening.py`, `agent_guard.py` |
| Preserve on host | `engine/config.json`, `.env*`, `engine/data/**` model routes / cooldowns |
| Post-update | Worker restart; register against `https://<host>/ai_api.php`; confirm node online |

---

## 5. AI DB migrations + rollback (operator-controlled; **no prod migrate in this campaign**)

| Item | Detail |
|------|--------|
| Mechanism | `AiSchema::migrate()` (bootstrap / install path) — additive `CREATE TABLE IF NOT EXISTS` + column upgrades |
| Target version | `ai_schema_version=1.2.0` |
| Additive tables | `ai_worker_*`, `ai_conversations`, `ai_jobs`, `ai_action_proposals`, `ai_suggestions`, `ai_feedback`, `ai_semantic_routes`, `ai_rag_sources`, `ai_rag_documents`, `ai_rag_document_acl` |
| Upgrades | `ai_action_proposals.idempotency_key`; `ai_suggestions.dedupe_key` |
| Permissions seeded | `ai.use`, `ai.actions.approve`, `ai.workers.manage`, `ai.audit.view`, `ai.rag.retrieve`, `ai.rag.manage` |

### Disposable rehearsal (synthetic legacy AI subset — **not** a production dump)

Evidence: `storage/cache/agentic_prod_release/ai_migrate_rehearsal.json`

| Check | Result |
|-------|--------|
| Legacy AI tables before | 3 |
| AI tables after | 11 |
| Jobs preserved | 1 |
| RAG ACL present | yes |
| Idempotency / dedupe columns | yes |
| Rollback sketch | `DROP` additive `ai_rag_*` only; jobs retained |

### Operator migrate plan (human only)

1. Full MySQL backup + SHA-256 of dump.  
2. Restore dump to **disposable** DB (prefer **anonymized real legacy** copy — still required for final Go).  
3. Run app bootstrap / AiSchema migrate on disposable only; record `ai_schema_version`.  
4. Smoke AI tables + permissions; no ERP domain mutation expected.  
5. Production migrate only after explicit owner approval + maintenance window.

### Rollback

1. Restore pre-migrate DB dump (authoritative).  
2. Optional: drop additive RAG tables if forward-only cleanup needed (does **not** replace dump restore).  
3. Redeploy previous known-good PHP SHA + prior Worker tree.  
4. Keep MCP disabled; revoke new worker tokens if issued.

---

## 6. Gate results (tip `e979766`)

### Commercial MVP / RC1 (CI on tip) — necessary, **not** sufficient for full Agentic AI

| Gate | Result | Evidence |
|------|--------|----------|
| Commercial MVP Gate | **SUCCESS** | https://github.com/AmirAbedini78/excel-dev/actions/runs/45066386417 |
| RC1 MySQL+PHP Gate | **SUCCESS** | https://github.com/AmirAbedini78/excel-dev/actions/runs/45066386515 |

### Agentic AI integration / security (local on tip)

| Suite | Result |
|-------|--------|
| PHP Wave1 agent_task + security harness | **PASS** |
| PHP enterprise RAG ACL | **PASS** |
| PHP MCP security | **PASS** (`MCP_SECURITY_PASS`) |
| PHP T3 contract descriptors | **PASS** |
| Python Wave1/2/3/4 + release contracts | **81 ok / 1 skip / 1 fail** (82 ran) |

### Missing / incomplete coverage (report separately)

| Gap | Severity | Notes |
|-----|----------|-------|
| `test_mf_tools_mysql_unvalued_gl_blocked_and_permission` | P1 local | Failed: MySQL user `p2p` denied DB `erpsmart_mf1_test` (fixture/env) — not covered by Commercial MVP alone |
| Disposable MySQL cross-tenant AI fixture | P1 | Skipped (tracked backlog) |
| Full 60/40/30 eval matrix | backlog | Not claimed |
| Live cloud provider qualification | backlog | Needs owner credentials |
| Anonymized **real** legacy DB migrate | **P0 for prod migrate Go** | Synthetic rehearsal only |
| Remote staging UAT | N/A (owner skipped staging) | No `STAGING_VERIFIED` |

---

## 7. MCP default-off proof (must remain off in this package)

| Proof | Detail |
|-------|--------|
| Code | `McpFacade::isEnabled` requires setting exactly `"1"`; default `'0'`; `enabled_by_default => false` |
| HTTP | `mcp_api.php` documents disabled-by-default; returns disabled/503 posture when unset |
| Seeds | No install/SQL/config seed sets `mcp_enabled=1` in committed sources |
| Tests | `tests/php_mcp_security_test.php` + `tests/test_mcp_security_logic.py` PASS |
| Ops | Keep production `mcp_enabled` absent/`0`; enable only via `13-MCP-OPERATOR-ENABLE-RUNBOOK.md` on **non-prod** after separate authorization |

---

## 8. Pre-deploy backup / maintenance / post-deploy smoke / rollback

### Pre-deploy

- [ ] Confirm tip SHA == `e9797662d85807a3a128d548e6557439800a689b`
- [ ] Recompute ZIP SHA-256 match section 2
- [ ] Full files + MySQL backup; record dump SHA-256
- [ ] Snapshot Worker `config.json` + data routes
- [ ] Confirm `mcp_enabled` is not `1`

### Maintenance

- [ ] Enable maintenance / drain AI jobs
- [ ] Controlled PHP sync (**exclude** `storage` user data + `app/config.php`)
- [ ] Worker package update + restart
- [ ] Operator-approved AiSchema migrate **only** after disposable anonymized rehearsal sign-off

### Post-deploy smoke

- [ ] Login + dashboard
- [ ] Copilot sidecar (`index.php?copilot_api=1`) loads
- [ ] `ai_api.php` worker register/heartbeat
- [ ] `ai_live.php` job poll
- [ ] `mcp_api.php` remains disabled (503 / disabled policy)
- [ ] No GL/MF valuation claims; writes stay proposal + human approve

### Rollback

- [ ] Restore files from backup / prior SHA
- [ ] Restore DB dump if migrate applied
- [ ] Restore Worker tree + config
- [ ] Re-smoke login + AI disabled-safe paths

---

## 9. Operator Go / No-Go checklist

**Recommended posture until all P0 boxes are checked: NO-GO.**

| # | Check | P0/P1 | Initials / date |
|---|-------|-------|-----------------|
| 1 | SHA freeze verified `e979766…` | P0 | |
| 2 | PHP + Worker archive hashes match | P0 | |
| 3 | Controlled sync plan excludes `storage` user data + `app/config.php` (do **not** rely on blind `.cpanel.yml`) | P0 | |
| 4 | Pre-deploy backup + dump SHA recorded | P0 | |
| 5 | Anonymized **real** legacy DB disposable migrate PASS recorded | P0 | |
| 6 | MCP remains disabled (`mcp_enabled` ≠ `1`) | P0 | |
| 7 | Commercial MVP + RC1 CI SUCCESS on tip acknowledged | P0 | |
| 8 | Agentic AI PHP security suites PASS acknowledged | P0 | |
| 9 | MF MySQL tool fixture / coverage gap accepted or re-run PASS | P1 | |
| 10 | Explicit **owner written approval** for production cutover | P0 | |
| 11 | Maintenance window + rollback owner named | P0 | |

| Decision | Mark one |
|----------|----------|
| **GO** (owner approves cutover under above controls) | ☐ |
| **NO-GO** (package retained; no production mutation) | ☐ |

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
- `07-MCP-SECURITY-AND-CONTRACT.md`
- `10-TESTS-EVALUATION-AND-METRICS.md`
