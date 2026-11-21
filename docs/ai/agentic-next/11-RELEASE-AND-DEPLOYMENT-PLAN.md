# 11 — Release and Deployment Plan (NO remote execution)

**Status:** PLAN ONLY — this campaign does **not** authorize production, cPanel, or remote DB migration.

## Deployable identity (to be filled at Wave4)

| Item | Value |
|------|-------|
| Exact source SHA | _pending Wave4 merge_ |
| PHP control plane update | required |
| Worker image/update | required if kernel/RAG/provider changed |
| Migrations | additive `AiSchema` only; run on disposable DBs first |
| MCP | remains **disabled** until security acceptance recorded |
| Production | **NOT_AUTHORIZED** |

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
