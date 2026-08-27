#!/usr/bin/env python3
"""ERPSMART v10 Model Provider Gateway.

Adds a provider abstraction on top of the existing Worker without changing any
financial/domain logic. The current local Ollama path remains the default.
Optionally, an OpenAI-compatible chat-completions provider can be used as:

* fallback when Ollama/model is unavailable (local_first)
* primary with local fallback (cloud_first)
* the only provider on an always-on worker node (cloud_only)
* disabled entirely (local_only)

The gateway never receives database credentials and does not change the Tool /
Proposal / Approval boundaries. Cloud providers only replace the language-model
transport used for planning/explanation/tool calling.
"""
from __future__ import annotations

import hashlib
import json
import socket
import time
import urllib.error
import urllib.request
from typing import Any
from urllib.parse import urlsplit


VALID_STRATEGIES = {"local_only", "local_first", "cloud_first", "cloud_only"}


class ProviderGatewayError(RuntimeError):
    pass


def _cloud_cfg(cfg: dict[str, Any]) -> dict[str, Any]:
    raw = cfg.get("cloud_provider")
    return dict(raw) if isinstance(raw, dict) else {}


def _cloud_enabled(cfg: dict[str, Any]) -> bool:
    cloud = _cloud_cfg(cfg)
    return bool(cloud.get("enabled"))


def validate_provider_config(cfg: dict[str, Any]) -> None:
    strategy = str(cfg.get("provider_strategy") or "local_first").strip().lower()
    if strategy not in VALID_STRATEGIES:
        raise ProviderGatewayError(f"provider_strategy_invalid:{strategy}")

    if strategy in {"cloud_first", "cloud_only"} and not _cloud_enabled(cfg):
        raise ProviderGatewayError("cloud_provider_required_by_strategy")

    if not _cloud_enabled(cfg):
        return

    cloud = _cloud_cfg(cfg)
    base_url = str(cloud.get("base_url") or "").strip()
    api_key = str(cloud.get("api_key") or "").strip()
    chat_model = str(cloud.get("chat_model") or "").strip()
    if not base_url or not api_key or not chat_model:
        raise ProviderGatewayError("cloud_provider_missing_base_url_api_key_or_chat_model")

    parsed = urlsplit(base_url)
    host = (parsed.hostname or "").lower()
    if parsed.scheme not in {"http", "https"} or not host:
        raise ProviderGatewayError("cloud_provider_base_url_invalid")
    if parsed.scheme != "https" and host not in {"localhost", "127.0.0.1", "::1"}:
        raise ProviderGatewayError("cloud_provider_remote_requires_https")

    extra_headers = cloud.get("extra_headers")
    if extra_headers is not None and not isinstance(extra_headers, dict):
        raise ProviderGatewayError("cloud_provider_extra_headers_must_be_object")


def _model_map(cfg: dict[str, Any], provider: str) -> dict[str, str]:
    if provider == "cloud":
        cloud = _cloud_cfg(cfg)
        chat = str(cloud.get("chat_model") or "").strip()
        return {
            "fast": str(cloud.get("fast_model") or chat).strip(),
            "agent": str(cloud.get("agent_model") or chat).strip(),
            "analysis": str(cloud.get("analysis_model") or chat).strip(),
            "fallback": chat,
        }

    chat = str(cfg.get("chat_model") or "").strip()
    return {
        "fast": str(cfg.get("fast_model") or "qwen3.5:0.8b").strip(),
        "agent": str(cfg.get("agent_model") or "qwen3.5:0.8b").strip(),
        "analysis": str(cfg.get("analysis_model") or "gemma3:4b").strip(),
        "fallback": chat,
    }


def _infer_role(cfg: dict[str, Any], model_name: str, hint: str | None) -> str:
    if hint in {"fast", "agent", "analysis", "fallback"}:
        return str(hint)
    for provider in ("local", "cloud"):
        for role, model in _model_map(cfg, provider).items():
            if model and model_name == model:
                return role
    return "fallback"


def _provider_order(worker: Any, role: str, requested_model: str) -> list[str]:
    strategy = str(worker._provider_strategy)
    local_model = worker._local_role_models.get(role, "")
    cloud_model = worker._cloud_role_models.get(role, "")

    # If caller explicitly supplied a cloud-only model, respect it first.
    if cloud_model and requested_model == cloud_model and requested_model != local_model:
        first = ["cloud", "local"]
    elif local_model and requested_model == local_model:
        first = ["local", "cloud"]
    elif strategy == "cloud_only":
        first = ["cloud"]
    elif strategy == "cloud_first":
        first = ["cloud", "local"]
    elif strategy == "local_only":
        first = ["local"]
    else:
        first = ["local", "cloud"]

    out: list[str] = []
    for provider in first:
        if provider == "local" and worker._local_available:
            out.append(provider)
        elif provider == "cloud" and worker._cloud_available:
            out.append(provider)
    return out


def _ensure_tool_call_id(call: dict[str, Any], index: int) -> dict[str, Any]:
    out = dict(call)
    fn = out.get("function") if isinstance(out.get("function"), dict) else {}
    out["type"] = str(out.get("type") or "function")
    if not str(out.get("id") or "").strip():
        basis = json.dumps(fn, sort_keys=True, ensure_ascii=False, default=str)
        out["id"] = "call_" + hashlib.sha256((basis + f"|{index}").encode("utf-8")).hexdigest()[:24]
    out["function"] = dict(fn)
    args = out["function"].get("arguments", {})
    if not isinstance(args, str):
        out["function"]["arguments"] = json.dumps(args if isinstance(args, dict) else {}, ensure_ascii=False, separators=(",", ":"))
    return out


def _openai_messages(messages: list[dict[str, Any]]) -> list[dict[str, Any]]:
    result: list[dict[str, Any]] = []
    pending: dict[str, list[str]] = {}

    for idx, raw in enumerate(messages):
        if not isinstance(raw, dict):
            continue
        role = str(raw.get("role") or "user")
        if role == "assistant":
            msg: dict[str, Any] = {"role": "assistant", "content": raw.get("content") or ""}
            calls_raw = raw.get("tool_calls")
            if isinstance(calls_raw, list) and calls_raw:
                calls: list[dict[str, Any]] = []
                for call_idx, call in enumerate(calls_raw):
                    if not isinstance(call, dict):
                        continue
                    normalized = _ensure_tool_call_id(call, call_idx)
                    name = str((normalized.get("function") or {}).get("name") or "")
                    if name:
                        pending.setdefault(name, []).append(str(normalized["id"]))
                    calls.append(normalized)
                if calls:
                    msg["tool_calls"] = calls
            result.append(msg)
            continue

        if role == "tool":
            name = str(raw.get("tool_name") or raw.get("name") or "")
            ids = pending.get(name) or []
            call_id = ids.pop(0) if ids else "call_" + hashlib.sha256(f"{name}|{idx}".encode()).hexdigest()[:24]
            msg = {
                "role": "tool",
                "tool_call_id": call_id,
                "content": str(raw.get("content") or ""),
            }
            if name:
                msg["name"] = name
            result.append(msg)
            continue

        msg = {"role": role, "content": raw.get("content") or ""}
        if raw.get("name"):
            msg["name"] = str(raw.get("name"))
        result.append(msg)

    return result


def _response_format_variants(response_format: Any | None) -> list[dict[str, Any] | None]:
    if response_format is None:
        return [None]
    if response_format == "json":
        return [{"type": "json_object"}, None]
    if isinstance(response_format, dict):
        return [
            {
                "type": "json_schema",
                "json_schema": {
                    "name": "erpsmart_response",
                    "strict": True,
                    "schema": response_format,
                },
            },
            {"type": "json_object"},
            None,
        ]
    return [None]


def _cloud_chat(
    worker: Any,
    job: dict[str, Any],
    round_no: int,
    messages: list[dict[str, Any]],
    tools: list[dict[str, Any]],
    role: str,
    num_predict: int | None,
    temperature: float | None,
    timeout_seconds: int | None,
    response_format: Any | None,
) -> dict[str, Any]:
    cloud = worker._cloud_cfg
    model_name = str(worker._cloud_role_models.get(role) or worker._cloud_role_models.get("fallback") or "").strip()
    if not model_name:
        raise ProviderGatewayError(f"cloud_model_missing_for_role:{role}")

    default_predict = int(worker.cfg.get("fast_num_predict" if role == "fast" else "num_predict", 160 if role == "fast" else 192))
    predict = max(16, min(4096, int(num_predict if num_predict is not None else default_predict)))
    temp = float(temperature if temperature is not None else worker.cfg.get("temperature", 0.2))
    timeout = max(15, min(900, int(timeout_seconds if timeout_seconds is not None else cloud.get("timeout_seconds", 120))))

    base_url = str(cloud["base_url"]).rstrip("/")
    url = base_url + "/chat/completions"
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": "Bearer " + str(cloud["api_key"]),
        "User-Agent": "ERPSMART-ProviderGateway/10.0",
    }
    for key, value in dict(cloud.get("extra_headers") or {}).items():
        key_s = str(key).strip()
        if not key_s or key_s.lower() in {"authorization", "content-type", "accept"}:
            continue
        headers[key_s] = str(value)

    common: dict[str, Any] = {
        "model": model_name,
        "messages": _openai_messages(messages),
        "temperature": temp,
        "max_tokens": predict,
        "stream": False,
    }
    if tools:
        common["tools"] = tools
        common["tool_choice"] = "auto"

    started = time.monotonic()
    last_error: Exception | None = None
    response: dict[str, Any] | None = None

    for rf in _response_format_variants(response_format):
        body = dict(common)
        if rf is not None:
            body["response_format"] = rf
        req = urllib.request.Request(
            url,
            data=json.dumps(body, ensure_ascii=False).encode("utf-8"),
            method="POST",
            headers=headers,
        )
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                raw = resp.read()
            parsed = json.loads(raw.decode("utf-8"))
            if not isinstance(parsed, dict):
                raise ProviderGatewayError("cloud_provider_non_object_response")
            response = parsed
            break
        except urllib.error.HTTPError as exc:
            last_error = ProviderGatewayError(f"cloud_provider_http_{exc.code}")
            # Some OpenAI-compatible providers do not support json_schema/json_object.
            # Retry only by degrading response_format; never change the prompt/tools.
            if rf is None:
                raise last_error from exc
        except (urllib.error.URLError, TimeoutError, socket.timeout, OSError) as exc:
            raise ProviderGatewayError(f"cloud_provider_network_error:{type(exc).__name__}") from exc
        except json.JSONDecodeError as exc:
            raise ProviderGatewayError("cloud_provider_invalid_json") from exc

    if response is None:
        raise last_error or ProviderGatewayError("cloud_provider_no_response")

    choices = response.get("choices")
    if not isinstance(choices, list) or not choices or not isinstance(choices[0], dict):
        raise ProviderGatewayError("cloud_provider_missing_choices")
    raw_message = choices[0].get("message")
    if not isinstance(raw_message, dict):
        raise ProviderGatewayError("cloud_provider_missing_message")

    message: dict[str, Any] = {
        "role": str(raw_message.get("role") or "assistant"),
        "content": str(raw_message.get("content") or ""),
    }
    raw_calls = raw_message.get("tool_calls")
    if isinstance(raw_calls, list) and raw_calls:
        message["tool_calls"] = [
            _ensure_tool_call_id(call, i)
            for i, call in enumerate(raw_calls)
            if isinstance(call, dict)
        ]

    usage = response.get("usage") if isinstance(response.get("usage"), dict) else {}
    elapsed = round(time.monotonic() - started, 2)
    metrics = {
        "provider": str(cloud.get("name") or "openai_compatible"),
        "model": str(response.get("model") or model_name),
        "round": round_no + 1,
        "elapsed_seconds": elapsed,
        "first_chunk_seconds": elapsed,
        "prompt_eval_count": usage.get("prompt_tokens"),
        "eval_count": usage.get("completion_tokens"),
    }
    worker.trace(job, "llm_done", "Model response received", metrics)
    return {"message": message, "_metrics": metrics, "_provider": metrics["provider"]}


def install_provider_gateway(worker_cls: type) -> None:
    if getattr(worker_cls, "_provider_gateway_v1_installed", False):
        return

    original_init = worker_cls.__init__
    original_model_for = worker_cls.model_for
    original_ollama_chat = worker_cls.ollama_chat
    original_process_agent = worker_cls.process_agent

    def provider_init(self: Any, cfg: dict[str, Any]) -> None:
        validate_provider_config(cfg)
        original_init(self, cfg)
        self._provider_strategy = str(cfg.get("provider_strategy") or "local_first").strip().lower()
        self._cloud_cfg = _cloud_cfg(cfg)
        self._local_role_models = _model_map(cfg, "local")
        self._cloud_role_models = _model_map(cfg, "cloud")
        self._local_models = list(self.models)
        self._local_available = bool(self._local_models) and self._provider_strategy != "cloud_only"
        self._cloud_available = _cloud_enabled(cfg) and self._provider_strategy != "local_only"
        self._provider_role_hint: str | None = None
        self._last_model_provider = "none"
        self._providers_used: list[str] = []

        cloud_models = [m for m in self._cloud_role_models.values() if m] if self._cloud_available else []
        self.models = list(dict.fromkeys([*self._local_models, *cloud_models]))
        self.base_payload["models"] = self.models
        meta = dict(self.base_payload.get("metadata") or {})
        meta.update({
            "provider": "gateway",
            "provider_strategy": self._provider_strategy,
            "providers_available": [
                p for p, enabled in (("ollama", self._local_available), (str(self._cloud_cfg.get("name") or "openai_compatible"), self._cloud_available))
                if enabled
            ],
        })
        self.base_payload["metadata"] = meta

        if self._provider_strategy == "cloud_only" and not self._cloud_available:
            raise ProviderGatewayError("cloud_only_provider_unavailable")
        if self._provider_strategy == "local_only" and not self._local_available:
            raise ProviderGatewayError("local_only_provider_unavailable")
        if not self._local_available and not self._cloud_available:
            raise ProviderGatewayError("no_model_provider_available")

    def provider_model_for(self: Any, role: str) -> str:
        role = role if role in {"fast", "agent", "analysis", "fallback"} else "fallback"
        self._provider_role_hint = role
        strategy = self._provider_strategy
        local_model = str(self._local_role_models.get(role) or "").strip()
        local_fallback = str(self._local_role_models.get("fallback") or "").strip()
        cloud_model = str(self._cloud_role_models.get(role) or "").strip()

        if strategy in {"cloud_only", "cloud_first"} and self._cloud_available and cloud_model:
            return cloud_model
        if self._local_available:
            if local_model in self._local_models:
                return local_model
            if local_fallback in self._local_models:
                return local_fallback
            if strategy == "local_only":
                raise RuntimeError(f"required_model_not_installed:{local_model}")
        if self._cloud_available and cloud_model:
            return cloud_model
        # Preserve the old failure semantics when no usable provider exists.
        return original_model_for(self, role)

    def provider_chat(
        self: Any,
        job: dict[str, Any],
        round_no: int,
        messages: list[dict[str, Any]],
        tools: list[dict[str, Any]],
        fast: bool = False,
        model: str | None = None,
        num_ctx: int | None = None,
        num_predict: int | None = None,
        temperature: float | None = None,
        timeout_seconds: int | None = None,
        response_format: Any | None = None,
        think_override: bool | None = None,
    ) -> dict[str, Any]:
        requested_model = str(model or provider_model_for(self, "fast" if fast else "fallback"))
        role = _infer_role(self.cfg, requested_model, self._provider_role_hint)
        self._provider_role_hint = None
        providers = _provider_order(self, role, requested_model)
        if not providers:
            raise ProviderGatewayError(f"no_provider_available_for_role:{role}")

        errors: list[str] = []
        for index, provider in enumerate(providers):
            try:
                if provider == "local":
                    local_model = str(self._local_role_models.get(role) or requested_model).strip()
                    result = original_ollama_chat(
                        self, job, round_no, messages, tools, fast=fast, model=local_model,
                        num_ctx=num_ctx, num_predict=num_predict, temperature=temperature,
                        timeout_seconds=timeout_seconds, response_format=response_format,
                        think_override=think_override,
                    )
                    actual_provider = "ollama"
                else:
                    result = _cloud_chat(
                        self, job, round_no, messages, tools, role,
                        num_predict, temperature, timeout_seconds, response_format,
                    )
                    actual_provider = str(result.get("_provider") or self._cloud_cfg.get("name") or "openai_compatible")

                self._last_model_provider = actual_provider
                if actual_provider not in self._providers_used:
                    self._providers_used.append(actual_provider)
                metrics = result.get("_metrics")
                if isinstance(metrics, dict):
                    metrics.setdefault("provider", actual_provider)
                return result
            except Exception as exc:
                errors.append(f"{provider}:{type(exc).__name__}")
                if index + 1 < len(providers):
                    self.trace(job, "provider_fallback", "Model provider failed; trying fallback", {
                        "failed_provider": provider,
                        "next_provider": providers[index + 1],
                        "role": role,
                        "reason": type(exc).__name__,
                    })
                    continue
                raise ProviderGatewayError("model_provider_chain_failed:" + ",".join(errors)) from exc

        raise ProviderGatewayError("model_provider_chain_exhausted")

    def provider_process_agent(self: Any, job: dict[str, Any], tools_desc: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
        self._last_model_provider = "none"
        self._providers_used = []
        text, meta = original_process_agent(self, job, tools_desc)
        if isinstance(meta, dict):
            if self._last_model_provider != "none":
                meta["provider"] = self._last_model_provider
            meta["providers_used"] = list(self._providers_used)
            meta["provider_strategy"] = self._provider_strategy
        return text, meta

    worker_cls.__init__ = provider_init
    worker_cls.model_for = provider_model_for
    worker_cls.ollama_chat = provider_chat
    worker_cls.process_agent = provider_process_agent
    worker_cls._provider_gateway_v1_installed = True
