# 11 — Release and Deployment Plan (NO remote execution)

**Status:** PLAN ONLY — this campaign does **not** authorize production, cPanel, or remote DB migration.

## Deployable identity (to be filled at Wave4)

| Item | Value |
|------|-------|
| Exact source SHA | Wave4 `092e9cc`; acceptance tip `af4b1ff` (+ MCP secure-path PR when merged) |
| PHP control plane update | required |
| Worker image/update | required if kernel/RAG/provider changed |
| Migrations | additive `AiSchema` only; run on disposable DBs first |
| MCP | **DISABLED BY DEFAULT**; secure enablement path + operator runbook `13-MCP-OPERATOR-ENABLE-RUNBOOK.md` — ops may set `mcp_enabled=1` on non-prod only |
| Staging | Package pin `14fcf69` = `WAITING_FOR_STAGING_HOST` (not `STAGING_VERIFIED`) |
| Local browser | `LOCAL_BROWSER_VERIFIED` on tip `a53019c…` disposable stack — not staging |
| Production | **NOT_AUTHORIZED** / **NOT_DEPLOYED** |

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
