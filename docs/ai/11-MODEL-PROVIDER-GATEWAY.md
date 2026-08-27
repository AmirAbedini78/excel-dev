# 11-MODEL-PROVIDER-GATEWAY — v10 Provider Contract

Status: `IMPLEMENTED / LIVE-VALIDATION-PENDING`

## Goal

ERPSMART must not be hard-locked to a single LLM runtime. v10 introduces a provider gateway while preserving the proven v9.3 financial safety boundaries.

The provider only changes **language-model transport**. It does not move accounting truth, validation, IDs, SQL, financial calculation, Proposal/Approval, or execution into the model.

## Supported strategies

```text
local_only   → Ollama only
local_first  → Ollama primary; OpenAI-compatible cloud fallback
cloud_first  → cloud primary; Ollama fallback
cloud_only   → OpenAI-compatible provider only
```

Default behavior is `local_first`. Existing runtime configs without cloud settings remain local-only in practice because `cloud_provider.enabled` defaults to false.

## Cloud provider contract

The first cloud adapter uses the OpenAI-compatible `/chat/completions` contract:

- system/user/assistant messages
- function/tool calling
- structured JSON / JSON-schema when supported
- bounded fallback from `json_schema` → `json_object` → normal output; server validators still own correctness
- role-specific models for fast/agent/analysis/fallback
- HTTPS required for remote endpoints
- API key stays only in local runtime config and never enters Worker registration metadata, Tool payloads, or persisted job metadata

Example configuration:

```json
{
  "provider_strategy": "local_first",
  "cloud_provider": {
    "enabled": true,
    "name": "openai_compatible",
    "base_url": "https://provider.example/v1",
    "api_key": "...",
    "chat_model": "...",
    "fast_model": "...",
    "agent_model": "...",
    "analysis_model": "..."
  }
}
```

## Availability topology

Cloud fallback in the **same Worker** solves `Ollama unavailable while Worker is running`.

It does **not** solve `the whole local PC is powered off`, because the Worker itself would be gone.

For that case ERPSMART supports a second always-on Worker node configured as `cloud_only`:

```text
                         cPanel Queue
                         /          \
                        /            \
          Local Worker               Cloud Worker
       local_first strategy        cloud_only strategy
       Ollama → cloud fallback      OpenAI-compatible API
```

Both nodes use the same existing queue/lease boundary. No inbound port is required on the local PC.

`engine/compose.cloud.example.yaml` + `engine/config.cloud.example.json` are the deployment template for the second node.

## Privacy / data-routing rule

Cloud use is explicit configuration, not an invisible default.

Before a real customer uses a third-party provider, its data-processing/privacy/retention policy must be reviewed. Sensitive customer data should remain local unless the deployment agreement permits the selected cloud provider.

Future policy routing may classify requests by sensitivity, but v10 Cycle 2 only provides the transport/fallback mechanism.

## Financial safety invariants retained

1. Current ledger/transaction facts come from server Tools.
2. LLM never gets DB credentials or arbitrary SQL.
3. LLM does not create ERP IDs.
4. Financial mutations remain Proposal-only until human approval.
5. Server-side domain validation owns execution.
6. RAG is not current ledger truth.
7. Forecast numeric output remains deterministic/statistical.

## Runtime metadata

Worker registration exposes only non-secret metadata:

```text
provider = gateway
provider_strategy = local_first | cloud_first | ...
providers_available = [ollama, provider-name]
models = local + configured cloud model names
```

Each LLM call records the actual provider/model in trace metrics. Job result metadata records `provider`, `providers_used`, and `provider_strategy`; credentials are never included.

## v10 Cycle 2 acceptance

### Required now

- existing local Ollama path still works after Worker rebuild;
- no change to Finance Tool/Proposal behavior;
- config without cloud settings remains compatible;
- provider-gateway contract tests pass;
- Docker image includes `provider_gateway.py`.

### Cloud live test

A real cloud test is performed only after a provider/API key is configured. Required test:

```text
local_first + local healthy → Ollama used
local_first + Ollama unavailable → configured cloud provider used
```

For `PC unavailable` high availability, bring up a second `cloud_only` Worker on an always-on node; that is deployment work, not a financial feature.
