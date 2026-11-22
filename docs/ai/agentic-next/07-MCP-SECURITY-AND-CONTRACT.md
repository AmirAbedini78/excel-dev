# 07 - MCP Security and Contract

Protocol version: 2024-11-05. Facade: `erp-mcp-facade-v1`.

## Default posture

**DISABLED BY DEFAULT** (`mcp_enabled` setting must be explicitly `"1"`).  
Missing/unset/`0` ⇒ fail-closed (`mcp_disabled`). Writes remain proposal-only even when enabled; MCP never approves or executes mutations.

## Auth / scope

| Gate | Behavior |
|------|----------|
| Bearer `acct_ws_*` | Required; revoked/expired/inactive rejected |
| Permission | Token must include `read` scope → projected as `ai.use` |
| Workspace | Token workspace bootstrapped; mismatch ⇒ forbidden |
| Company | **Server membership authoritative**; `X-ERP-Company-Ids` may only narrow via `McpFacade::resolveAllowedCompanyIds` — never expand / never trust alone |
| Tools | AiToolRegistry allowlist only; shell/SQL/filesystem forbidden |
| Mutations | `store_proposal_only`; `tools/approve` / `proposals/execute` / bypass flags blocked |

## Secure enablement path (ops)

1. Ship tip with adversarial suite green (`tests/php_mcp_security_test.php`, `tests/test_mcp_security_logic.py`).
2. On **non-production** only, set `mcp_enabled=1` via settings UI/DB — do not commit the flag.
3. Issue workspace API token with `read` scope; call `mcp_api.php` with Bearer + optional company narrow header.
4. Confirm GET health returns `enabled:true` and write tools return `awaiting_human_approval`.
5. Keep production / cPanel at `mcp_enabled=0` until separate owner authorization.

## Evidence

- PHP: `tests/php_mcp_security_test.php` → `MCP_SECURITY_PASS`
- Python mirror: `tests/test_mcp_security_logic.py`
- HTTP entry: `mcp_api.php` (503 when disabled)

**Status:** Secure enablement **path** ready (fail-closed default retained). Operational enable on a host still requires ops to flip `mcp_enabled` on an isolated non-prod environment — not auto-enabled by this repo.
