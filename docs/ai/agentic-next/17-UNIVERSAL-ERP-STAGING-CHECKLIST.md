# Universal ERP Agent — Staging subdomain checklist (NOT main domain)

**Non-negotiable:** no production / main-domain deploy from this campaign.

## Package / host

1. Build from CI-green tip of `feature/universal-erp-agent` (or merged SHA).
2. Deploy only to a **separate staging subdomain** + cloned MySQL.
3. Preserve existing `storage/`, runtime config, Worker tokens, and `.env` secrets — never overwrite with repo fixtures.
4. cPanel shared hosting compatible: **no new Docker/Redis/Node daemon requirement** for control plane. Worker remains existing outbound HTTPS model.
5. Confirm `mcp_enabled` is unset/`0` on staging unless ops explicitly enables for isolated MCP UAT.

## Smoke (staging)

1. `php tests/php_universal_ai_agent_contract_test.php` → PASS (static).
2. `python -m unittest tests.test_universal_ai_agent_routing -v` → PASS.
3. Commercial + RC1 gates green on the PR.
4. Manual: Persian catalog ask → search → read → navigate deep link opens exact form.
5. Manual: propose Suite leave/expense create → card shows **پیشنهاد — اجرا نشده** → second user approves → real ID/link/audit; creator cannot approve.
6. Manual: refuse / fail-closed for payroll, GL generic write, physical delete, cross-company ID.
7. Provider smoke (optional): Gemini/Grok via env credentials only; missing keys → `BLOCKED_MISSING_CREDENTIAL` (do not enable prod).

## Rollback

1. Restore previous code package on subdomain.
2. DB: additive tables for Suite/Enterprise remain; do not drop without backup plan.
3. Disable new enterprise_* modules if needed; AI tools fail closed when modules off.

## Owner config needed

- Staging URL + DB credentials (out of git)
- Role grants for `ai.use`, suite module view/manage/approve, enterprise group permissions
- Optional provider API keys in env only
