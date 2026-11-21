# 07 — MCP Security and Contract (Wave3 / T4)

**Status:** Scaffold for security acceptance  
**Baseline:** `feature/mcp-provider-wave3`  
**Authority:** T4 owns this file; amendments that break Wave1 tool contract need T0.

---

## 1. Non-negotiables

| Rule | Enforcement |
|------|-------------|
| **MCP DISABLED BY DEFAULT** | Setting `mcp_enabled` must be exactly `1`; missing/`0` → facade returns `mcp_disabled` (HTTP 503) |
| Facade only — **not** a second ERP engine | Tools projected from `AiToolRegistry::descriptors()` only |
| Writes = **proposals only** | Proposal-mode tools call store path; never `executeProposal` / approve via MCP |
| No shell / SQL / filesystem tools | `McpFacade::FORBIDDEN_TOOL_NAMES` + allowlist filter |
| No approval bypass | Forbidden methods (`tools/approve`, `proposals/execute`, …) and argument flags (`execute_immediately`, `skip_approval`, …) rejected |
| Company scope | Every `tools/call` requires `company_id` in allowed set for the token |

---

## 2. Protocol versions

| Field | Value |
|-------|--------|
| MCP protocol revision spoken | **`2024-11-05`** (`McpFacade::PROTOCOL_VERSION`) |
| ERPSMART facade contract | **`erp-mcp-facade-v1`** (`McpFacade::FACADE_VERSION`) |
| HTTP headers | `X-MCP-Protocol-Version`, `X-ERP-MCP-Facade-Version` |

JSON-RPC 2.0 methods supported when enabled:

- `initialize`, `ping`
- `tools/list`, `tools/call`
- `resources/list`, `resources/read` (policy URIs only: `erp://policy/mcp`, `erp://policy/ai-tools`)

---

## 3. Surfaces

| Path | Role |
|------|------|
| `app/Core/McpFacade.php` | Pure security + protocol facade (testable without DB) |
| `mcp_api.php` | Authenticated HTTP entry; Bearer `acct_ws_*` workspace API token + `read` scope |
| `AiToolRegistry` | Sole capability catalog / read + proposal execution engine |

Enabling MCP (ops only, after security acceptance):

```text
settings.mcp_enabled = 1
```

Never ship product defaults with this set.

---

## 4. Auth and tenancy

1. Bearer workspace API token required (`acct_ws_*` / `workspace_api_tokens`).
2. Token must include `read` scope (transport gate).
3. Facade requires permission projection `ai.use`.
4. `workspace_id` comes from the token; company allowlist from `X-ERP-Company-Ids` or tenant companies.
5. Wrong / missing company → `wrong_company` / `company_required` (HTTP 403).
6. Bad / missing token → `unauthorized` (HTTP 401).

---

## 5. Write path

```
MCP tools/call (proposal tool)
  → McpFacade::resolveToolCall (allowlist + bypass checks)
  → AiToolRegistry::executeForWorker → storeProposal
  → status: awaiting_human_approval
  → Human approves only via ERP UI:
       AiRepository::approveProposal → AiToolRegistry::executeProposal
```

MCP **must not** expose approve/execute methods.

---

## 6. Security tests (required evidence)

| Case | Test |
|------|------|
| Disabled by default | `tests/php_mcp_security_test.php`, `tests/test_mcp_provider_wave3.py` |
| Bad credentials | assertAuth / handle → `unauthorized` |
| Wrong company | `tools/call` with foreign `company_id` → `wrong_company` |
| Forged tools | Unknown / shell / sql / filesystem → rejected |
| Approval bypass | `execute_immediately`, `tools/approve`, `proposals/execute` → blocked |

Run:

```bash
php tests/php_mcp_security_test.php
python -m unittest tests.test_mcp_provider_wave3 -v
```

---

## 7. Out of scope / deferred

- Enabling MCP in production defaults or installer
- Third-party MCP servers in production
- OAuth client registry UI (token reuse of workspace API is the scaffold)
- Streaming MCP transports beyond HTTP JSON-RPC POST

**Acceptance for AI3:** security suite green + this contract reviewed; MCP still default-off.
