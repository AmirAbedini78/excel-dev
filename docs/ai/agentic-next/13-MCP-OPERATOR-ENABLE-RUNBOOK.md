# 13 — MCP Operator Enable Runbook (non-prod only)

**Status:** Secure path ready · **DISABLED BY DEFAULT** · Production / cPanel **NOT_AUTHORIZED**  
**Do not** commit `mcp_enabled=1` into shared config, seed SQL, or `.env` examples.

## Preconditions

1. Tip on `origin/main` with Commercial MVP + RC1 MySQL+PHP gates **SUCCESS**.
2. Adversarial suites green:
   - `php tests/php_mcp_security_test.php` → `MCP_SECURITY_PASS`
   - `python -m unittest tests.test_mcp_security_logic`
3. Target host is an **isolated non-production** environment (local lab or owner-provided staging). Never production MySQL / cPanel destination.

## Enable (runtime only)

1. Confirm setting absent/`0` ⇒ `mcp_api.php` returns disabled / HTTP 503 posture.
2. On the **non-prod** host only, set settings key `mcp_enabled` to exactly `"1"` via admin UI or DB row for that workspace — **not** via a git commit.
3. Issue a workspace API token (`acct_ws_*`) with `read` scope.
4. Call `mcp_api.php` with `Authorization: Bearer …`. Optional `X-ERP-Company-Ids` may **only narrow** membership; server membership remains authoritative.
5. Verify:
   - health/initialize reports `enabled: true`
   - write tools return `awaiting_human_approval` (proposal-only)
   - forged tools / approval bypass methods rejected
6. Keep production / cPanel at `mcp_enabled=0` until a separate owner authorization.

## Disable / rollback

- Set `mcp_enabled=0` (or delete the setting) on the host.
- Revoke workspace tokens used for MCP clients.
- No code rollback required for disable — fail-closed is the default.

## Explicit non-goals

- Enabling MCP on shared/prod config in-repo
- Auto-enable on deploy
- Claiming `STAGING_VERIFIED` from MCP enable alone
