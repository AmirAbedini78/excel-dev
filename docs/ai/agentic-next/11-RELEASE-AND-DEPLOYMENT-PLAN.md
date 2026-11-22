# 11 — Release and Deployment Plan (NO remote execution)

**Status:** PLAN ONLY — this campaign does **not** authorize production, cPanel, or remote DB migration.

## Deployable identity (frozen for direct-production package)

| Item | Value |
|------|-------|
| Exact source SHA | **`e9797662d85807a3a128d548e6557439800a689b`** (PR #53 tip; full Agentic AI waves) |
| PHP control plane update | required — see package ZIP + `14-DIRECT-PRODUCTION-RELEASE-PACKAGE.md` |
| Worker image/update | **separate** Worker ZIP (`engine/` @ same SHA); preserve host `config.json` |
| Migrations | additive `AiSchema` `1.2.0` only; disposable rehearsal done; **prod migrate operator-only** |
| MCP | **DISABLED BY DEFAULT**; do **not** activate in this production package |
| Staging | **SKIPPED_BY_OWNER** — RC1 pin `14fcf69` is archive only, not AI tip |
| Local browser | prior `LOCAL_BROWSER_VERIFIED` evidence — not production |
| Production | Package ready for **explicit Go/No-Go**; **NOT_DEPLOYED** by T0 |
| `.cpanel.yml` audit | **Fail-closed** (auto-deploy disabled). Authoritative method: `scripts/agentic_controlled_cpanel_sync.py` (preserves storage + `app/config.php`; allowlists `copilot_api.php` / `mcp_api.php`) |

## Rollback

1. Redeploy previous known-good SHA (RC1 staging pin `14fcf69` product+executor package remains reference for staging ops).  
2. Disable Worker tokens / MCP flag.  
3. Leave ERP domain data untouched (AI tables are additive).  

## Operator checklist (future human action)

- [ ] Confirm synthetic/staging env only  
- [ ] Backup before migration  
- [ ] Run Commercial MVP + RC1 MySQL/PHP gates on SHA  
- [ ] Enable MCP only after T4/T6 security evidence  
- [ ] Explicit owner approval for any remote host  
