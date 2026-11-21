# 08 — Providers and Model Qualification (Wave3 / T4)

**Status:** Scaffold hooks + admin UI path  
**Baseline:** extends `engine/provider_gateway.py` — **does not replace** Ollama / Gemini / xAI / Cursor adapters  
**Authority:** T4; routing-policy changes that break privacy need T0.

---

## 1. Reuse map

| Component | Action |
|-----------|--------|
| `_cloud_chat` / `_xai_chat` / `_cursor_agent_chat` / local Ollama path | **REUSE** — unchanged adapters |
| `install_provider_gateway` routing | **REUSE** — privacy (`local_only`) preserved |
| New hooks | `discover_provider_models`, `qualify_provider_connection`, `qualify_provider_inference`, `build_qualification_report` |
| Admin UI | `AiModule` engine section + `AiProviderQualification` |

---

## 2. Discovery

`discover_provider_models(cfg, local_models=..., probe_cloud=..., http_get=...)`

- Always returns Ollama/local configured models.
- When `provider_strategy == local_only`: **suppresses** cloud / xAI / cursor inventories even if `probe_cloud=True`.
- Privacy flags in payload:
  - `local_only_blocks_cloud: true` under `local_only`
  - `cloud_fallback_ignores_privacy: false` always (forbidden behavior)

---

## 3. Connection qualification

`qualify_provider_connection(cfg, provider, http_get=..., local_reachable=...)`

- Smoke reachability only — **no ERP business data**.
- Cloud/xAI/cursor under `local_only` → `connection: blocked` / `provider_blocked_by_local_only_policy`.
- Without injected probes → `skipped` (fail soft for offline CI).

---

## 4. Inference qualification

`qualify_provider_inference(cfg, provider, model, chat_fn=...)`

- Bounded hook; callers supply `chat_fn` (worker chat / adapter).
- Cloud providers under `local_only` → `inference: blocked` **without** invoking `chat_fn`.
- Does not qualify a model from JSON shape alone (product kernel preflight remains in `agent_kernel_smoke.py`).

`build_qualification_report(...)` assembles admin-safe JSON (`provider-qual-v1`) for UI persistence.

---

## 5. Admin UI path (`AiModule`)

On **تنظیمات موتور AI** (`section=engine`):

- Shows MCP policy summary (**enabled: no** by default).
- Shows configured model catalog from `AiEnginePreferences::models()`.
- Accepts a gateway-produced qualification JSON (no secrets) via `ai_save_provider_qualification`.
- Persists via `AiProviderQualification::saveReport` into workspace `settings_json` (sanitized).

Workers/operators run discovery/qualification on the gateway host, then paste the report — keys never enter cPanel.

---

## 6. Privacy / cloud policy

| Strategy | Cloud discovery | Cloud connection/inference |
|----------|-----------------|----------------------------|
| `local_only` | Omitted | **Blocked** |
| `local_first` / `cloud_first` / `cloud_only` | Config (+ optional probe) | Allowed when provider enabled |

**Forbidden:** any path that falls back to cloud while ignoring `local_only` / privacy policy.

---

## 7. Tests

```bash
python -m unittest tests.test_mcp_provider_wave3.ProviderDiscoveryQualification -v
python -m unittest tests.test_provider_gateway -v
```

Evidence must show:

1. `local_only` blocks cloud discovery and qualification hooks.
2. Existing adapters still present in `provider_gateway.py`.
3. Report schema `provider-qual-v1` for admin path.

---

## 8. Relation to MCP

Provider qualification is independent of MCP enablement. MCP remains **disabled by default** (`07-MCP-SECURITY-AND-CONTRACT.md`). Qualification does not grant MCP write authority.
