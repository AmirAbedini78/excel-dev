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

import base64
import hashlib
import json
import os
import socket
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any
from urllib.parse import urlsplit


VALID_STRATEGIES = {"local_only", "local_first", "cloud_first", "cloud_only"}


class ProviderGatewayError(RuntimeError):
    pass


def _cloud_cfg(cfg: dict[str, Any]) -> dict[str, Any]:
    raw = cfg.get("cloud_provider")
    cloud = dict(raw) if isinstance(raw, dict) else {}
    key = str(cloud.get("api_key") or "")
    if key.startswith("env:"):
        var = key[4:]
        if not var or not var.replace("_", "").isalnum():
            raise ProviderGatewayError("cloud_provider_env_name_invalid")
        cloud["api_key"] = os.environ.get(var, "")
    return cloud


def _cursor_agent_cfg(cfg: dict[str, Any]) -> dict[str, Any]:
    raw = cfg.get("cursor_cloud_agent")
    block = dict(raw) if isinstance(raw, dict) else {}
    key = str(block.get("api_key") or "")
    if key.startswith("env:"):
        var = key[4:]
        if not var or not var.replace("_", "").isalnum():
            raise ProviderGatewayError("cursor_agent_env_name_invalid")
        block["api_key"] = os.environ.get(var, "")
    return block


def _xai_cfg(cfg: dict[str, Any]) -> dict[str, Any]:
    raw = cfg.get("xai_provider")
    block = dict(raw) if isinstance(raw, dict) else {}
    key = str(block.get("api_key") or "")
    if key.startswith("env:"):
        var = key[4:]
        if not var or not var.replace("_", "").isalnum():
            raise ProviderGatewayError("xai_provider_env_name_invalid")
        block["api_key"] = os.environ.get(var, "")
    return block


def _cloud_enabled(cfg: dict[str, Any]) -> bool:
    cloud = _cloud_cfg(cfg)
    return bool(cloud.get("enabled"))


def _cursor_agent_enabled(cfg: dict[str, Any]) -> bool:
    c = _cursor_agent_cfg(cfg)
    return bool(c.get("enabled")) and bool(str(c.get("api_key") or "").strip())


def _xai_enabled(cfg: dict[str, Any]) -> bool:
    x = _xai_cfg(cfg)
    return bool(x.get("enabled")) and bool(str(x.get("api_key") or "").strip())


def validate_provider_config(cfg: dict[str, Any]) -> None:
    strategy = str(cfg.get("provider_strategy") or "local_first").strip().lower()
    if strategy not in VALID_STRATEGIES:
        raise ProviderGatewayError(f"provider_strategy_invalid:{strategy}")

    if strategy in {"cloud_first", "cloud_only"} and not (
        _cloud_enabled(cfg) or _cursor_agent_enabled(cfg) or _xai_enabled(cfg)
    ):
        raise ProviderGatewayError("cloud_provider_required_by_strategy")

    if not _cloud_enabled(cfg) and not _cursor_agent_enabled(cfg) and not _xai_enabled(cfg):
        return

    if _cloud_enabled(cfg):
        cloud = _cloud_cfg(cfg)
        base_url = str(cloud.get("base_url") or "").strip()
        api_key = str(cloud.get("api_key") or "").strip()
        if api_key.startswith("env:") or api_key.startswith("REPLACE_"):
            raise ProviderGatewayError("cloud_provider_missing_api_key_env")
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

    if _cursor_agent_enabled(cfg):
        cc = _cursor_agent_cfg(cfg)
        ckey = str(cc.get("api_key") or "").strip()
        if ckey.startswith("env:") or ckey.startswith("REPLACE_"):
            raise ProviderGatewayError("cursor_agent_missing_api_key_env")
        if not ckey:
            raise ProviderGatewayError("cursor_agent_missing_api_key_env")
        default_model = str(cc.get("default_model") or "composer-2").strip()
        if not default_model:
            raise ProviderGatewayError("cursor_agent_default_model_required")

    if _xai_enabled(cfg):
        xai = _xai_cfg(cfg)
        base_url = str(xai.get("base_url") or "").strip()
        api_key = str(xai.get("api_key") or "").strip()
        if api_key.startswith("env:") or api_key.startswith("REPLACE_"):
            raise ProviderGatewayError("xai_provider_missing_api_key_env")
        chat_model = str(xai.get("chat_model") or "").strip()
        if not base_url or not api_key or not chat_model:
            raise ProviderGatewayError("xai_provider_missing_base_url_api_key_or_chat_model")
        parsed = urlsplit(base_url)
        host = (parsed.hostname or "").lower()
        if parsed.scheme not in {"http", "https"} or not host:
            raise ProviderGatewayError("xai_provider_base_url_invalid")
        if parsed.scheme != "https" and host not in {"localhost", "127.0.0.1", "::1"}:
            raise ProviderGatewayError("xai_provider_remote_requires_https")


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

    if provider == "xai":
        xai = _xai_cfg(cfg)
        chat = str(xai.get("chat_model") or "").strip()
        return {
            "fast": str(xai.get("fast_model") or chat).strip(),
            "agent": str(xai.get("agent_model") or chat).strip(),
            "analysis": str(xai.get("analysis_model") or chat).strip(),
            "fallback": chat,
        }

    chat = str(cfg.get("chat_model") or "").strip()
    return {
        "fast": str(cfg.get("fast_model") or "qwen3.5:0.8b").strip(),
        "agent": str(cfg.get("agent_model") or "qwen3.5:0.8b").strip(),
        "analysis": str(cfg.get("analysis_model") or "gemma3:4b").strip(),
        "fallback": chat,
    }


def _cursor_model_ids(cfg: dict[str, Any]) -> set[str]:
    cc = _cursor_agent_cfg(cfg)
    if not bool(cc.get("enabled")) or not str(cc.get("api_key") or "").strip():
        return set()
    out: set[str] = set()
    for x in cc.get("allowed_models") or []:
        s = str(x).strip()
        if s:
            out.add(s)
    dm = str(cc.get("default_model") or "").strip()
    if dm:
        out.add(dm)
    return out


def _xai_model_ids(cfg: dict[str, Any]) -> set[str]:
    xai = _xai_cfg(cfg)
    if not bool(xai.get("enabled")) or not str(xai.get("api_key") or "").strip():
        return set()
    out: set[str] = set()
    for x in xai.get("allowed_models") or []:
        s = str(x).strip()
        if s:
            out.add(s)
    for role_model in _model_map(cfg, "xai").values():
        s = str(role_model or "").strip()
        if s:
            out.add(s)
    return out


def _infer_role(cfg: dict[str, Any], model_name: str, hint: str | None) -> str:
    # A previous model_for() call is not allowed to relabel an explicit model.
    if hint in {"fast", "agent", "analysis", "fallback"} and any(
        _model_map(cfg, provider).get(hint) == model_name for provider in ("local", "cloud")
    ):
        return str(hint)
    if hint in {"fast", "agent", "analysis", "fallback"} and model_name in _cursor_model_ids(cfg):
        return str(hint)
    if hint in {"fast", "agent", "analysis", "fallback"} and model_name in _xai_model_ids(cfg):
        return str(hint)
    for provider in ("local", "cloud", "xai"):
        for role, model in _model_map(cfg, provider).items():
            if model and model_name == model:
                return role
    if model_name in _cursor_model_ids(cfg):
        return str(hint) if hint in {"fast", "agent", "analysis", "fallback"} else "agent"
    if model_name in _xai_model_ids(cfg):
        return str(hint) if hint in {"fast", "agent", "analysis", "fallback"} else "agent"
    return "fallback"


def _provider_order(worker: Any, role: str, requested_model: str) -> list[str]:
    strategy = str(worker._provider_strategy)
    local_model = worker._local_role_models.get(role, "")
    cloud_model = worker._cloud_role_models.get(role, "")
    cursor_ids = _cursor_model_ids(worker.cfg)
    is_cursor_model = bool(requested_model and requested_model in cursor_ids)
    cursor_ok = bool(getattr(worker, "_cursor_available", False))
    xai_ids = _xai_model_ids(worker.cfg)
    is_xai_model = bool(requested_model and requested_model in xai_ids)
    xai_ok = bool(getattr(worker, "_xai_available", False))

    if cursor_ok and is_cursor_model:
        return ["cursor"]
    if xai_ok and is_xai_model:
        return ["xai"]

    if strategy == "cloud_only":
        first = ["cloud"]
    elif strategy == "local_only":
        first = ["local"]
    elif cloud_model and requested_model == cloud_model and requested_model != local_model:
        first = ["cloud", "local"]
    elif local_model and requested_model == local_model:
        first = ["local", "cloud"]
    elif strategy == "cloud_first":
        first = ["cloud", "local"]
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


def _last_user_text_from_messages(messages: list[dict[str, Any]]) -> str:
    for raw in reversed(messages):
        if not isinstance(raw, dict):
            continue
        if str(raw.get("role") or "") != "user":
            continue
        content = raw.get("content")
        if isinstance(content, str) and content.strip():
            return content.strip()
    return ""


def _cursor_basic_auth_header(api_key: str) -> str:
    token = base64.b64encode(f"{api_key}:".encode("utf-8")).decode("ascii")
    return "Basic " + token


def _cursor_agent_chat(
    worker: Any,
    job: dict[str, Any],
    round_no: int,
    messages: list[dict[str, Any]],
    selected_model: str | None,
) -> dict[str, Any]:
    cc = getattr(worker, "_cursor_cfg", {}) or _cursor_agent_cfg(worker.cfg)
    if not isinstance(cc, dict) or not str(cc.get("api_key") or "").strip():
        raise ProviderGatewayError("cursor_agent_not_configured")

    api_key = str(cc.get("api_key") or "").strip()
    base = str(cc.get("api_base") or "https://api.cursor.com").rstrip("/")
    model_id = str(selected_model or cc.get("default_model") or "composer-2").strip()
    if not model_id:
        raise ProviderGatewayError("cursor_agent_model_missing")

    prompt_text = _last_user_text_from_messages(messages)
    if not prompt_text:
        raise ProviderGatewayError("cursor_agent_empty_prompt")

    timeout = max(30, min(900, int(cc.get("poll_timeout_seconds", 300))))
    interval = max(1.0, float(cc.get("poll_interval_seconds", 2.0)))

    env_type = str(cc.get("env_type") or "cloud").strip()
    body: dict[str, Any] = {
        "prompt": {"text": prompt_text},
        "model": {"id": model_id},
    }
    if env_type == "pool":
        pool_name = str(cc.get("pool_name") or "default").strip() or "default"
        body["env"] = {"type": "pool", "name": pool_name}
    else:
        body["env"] = {"type": "cloud"}

    repos_raw = cc.get("repos")
    if isinstance(repos_raw, list) and repos_raw:
        repos: list[dict[str, Any]] = []
        for item in repos_raw:
            if not isinstance(item, dict):
                continue
            url = str(item.get("url") or "").strip()
            if not url:
                continue
            entry: dict[str, Any] = {"url": url}
            ref = str(item.get("startingRef") or item.get("starting_ref") or "").strip()
            if ref:
                entry["startingRef"] = ref
            pr_url = str(item.get("prUrl") or item.get("pr_url") or "").strip()
            if pr_url:
                entry["prUrl"] = pr_url
            repos.append(entry)
        if repos:
            body["repos"] = repos

    url_create = base + "/v1/agents"
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "Authorization": _cursor_basic_auth_header(api_key),
        "User-Agent": "ERPSMART-ProviderGateway/10.0",
    }

    started = time.monotonic()
    try:
        req = urllib.request.Request(
            url_create,
            data=json.dumps(body, ensure_ascii=False).encode("utf-8"),
            method="POST",
            headers=headers,
        )
        with urllib.request.urlopen(req, timeout=120) as resp:
            created = json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        raise ProviderGatewayError(f"cursor_agent_http_{exc.code}") from exc
    except (urllib.error.URLError, TimeoutError, socket.timeout, OSError) as exc:
        raise ProviderGatewayError(f"cursor_agent_network_error:{type(exc).__name__}") from exc
    except json.JSONDecodeError as exc:
        raise ProviderGatewayError("cursor_agent_invalid_json") from exc

    if not isinstance(created, dict):
        raise ProviderGatewayError("cursor_agent_non_object_response")

    run = created.get("run")
    agent = created.get("agent")
    if not isinstance(run, dict) or not isinstance(agent, dict):
        raise ProviderGatewayError("cursor_agent_missing_run_or_agent")

    agent_id = str(agent.get("id") or "").strip()
    run_id = str(run.get("id") or "").strip()
    if not agent_id or not run_id:
        raise ProviderGatewayError("cursor_agent_missing_ids")

    url_run = f"{base}/v1/agents/{urllib.parse.quote(agent_id, safe='')}/runs/{urllib.parse.quote(run_id, safe='')}"
    deadline = time.monotonic() + timeout
    terminal = {"FINISHED", "ERROR", "CANCELLED", "EXPIRED"}
    last_state: dict[str, Any] | None = None
    while time.monotonic() < deadline:
        try:
            poll_headers = {"Accept": "application/json", "Authorization": headers["Authorization"], "User-Agent": headers["User-Agent"]}
            req_poll = urllib.request.Request(url_run, headers=poll_headers, method="GET")
            with urllib.request.urlopen(req_poll, timeout=60) as resp:
                last_state = json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as exc:
            raise ProviderGatewayError(f"cursor_agent_http_{exc.code}") from exc
        except (urllib.error.URLError, TimeoutError, socket.timeout, OSError):
            time.sleep(interval)
            continue
        except json.JSONDecodeError:
            raise ProviderGatewayError("cursor_agent_invalid_json")

        if isinstance(last_state, dict):
            status = str(last_state.get("status") or "")
            if status in terminal:
                break
        time.sleep(interval)

    if not isinstance(last_state, dict):
        raise ProviderGatewayError("cursor_agent_no_final_state")

    status = str(last_state.get("status") or "")
    result_text = str(last_state.get("result") or "")
    if status == "ERROR" or status == "CANCELLED" or status == "EXPIRED":
        raise ProviderGatewayError(f"cursor_agent_run_{status.lower()}")
    if status != "FINISHED":
        raise ProviderGatewayError("cursor_agent_run_timeout")

    elapsed = round(time.monotonic() - started, 2)
    metrics = {
        "provider": "cursor_cloud_agent",
        "model": model_id,
        "round": round_no + 1,
        "elapsed_seconds": elapsed,
        "first_chunk_seconds": elapsed,
        "prompt_eval_count": None,
        "eval_count": None,
    }
    worker.trace(job, "llm_done", "Cursor Cloud Agent response received", metrics)
    return {
        "message": {"role": "assistant", "content": result_text or "(پاسخ خالی از Cursor Agent)"},
        "_metrics": metrics,
        "_provider": "cursor_cloud_agent",
    }


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


def _openai_compatible_chat(
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
    cloud: dict[str, Any],
    role_models: dict[str, str],
    selected_model: str | None = None,
) -> dict[str, Any]:
    model_name = str(selected_model or role_models.get(role) or role_models.get("fallback") or "").strip()
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
    selected_model: str | None = None,
) -> dict[str, Any]:
    return _openai_compatible_chat(
        worker,
        job,
        round_no,
        messages,
        tools,
        role,
        num_predict,
        temperature,
        timeout_seconds,
        response_format,
        worker._cloud_cfg,
        worker._cloud_role_models,
        selected_model=selected_model,
    )


def _xai_chat(
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
    selected_model: str | None = None,
) -> dict[str, Any]:
    return _openai_compatible_chat(
        worker,
        job,
        round_no,
        messages,
        tools,
        role,
        num_predict,
        temperature,
        timeout_seconds,
        response_format,
        worker._xai_cfg,
        worker._xai_role_models,
        selected_model=selected_model,
    )


def install_provider_gateway(worker_cls: type) -> None:
    if getattr(worker_cls, "_provider_gateway_v1_installed", False):
        return

    original_init = worker_cls.__init__
    original_model_for = worker_cls.model_for
    original_ollama_chat = worker_cls.ollama_chat
    original_process_agent = worker_cls.process_agent

    def provider_init(self: Any, cfg: dict[str, Any]) -> None:
        # Resolve environment credential before worker starts; validation must fail closed
        # if the variable is unset, not silently accept an env: placeholder.
        effective = dict(cfg)
        effective["cloud_provider"] = _cloud_cfg(cfg)
        effective["cursor_cloud_agent"] = _cursor_agent_cfg(cfg)
        effective["xai_provider"] = _xai_cfg(cfg)
        validate_provider_config(effective)
        original_init(self, effective)
        cfg = effective
        self._provider_strategy = str(cfg.get("provider_strategy") or "local_first").strip().lower()
        self._cloud_cfg = _cloud_cfg(cfg)
        self._cursor_cfg = _cursor_agent_cfg(cfg)
        self._xai_cfg = _xai_cfg(cfg)
        self._cursor_available = _cursor_agent_enabled(cfg) and self._provider_strategy != "local_only"
        self._xai_available = _xai_enabled(cfg) and self._provider_strategy != "local_only"
        self._local_role_models = _model_map(cfg, "local")
        self._cloud_role_models = _model_map(cfg, "cloud")
        self._xai_role_models = _model_map(cfg, "xai")
        self._local_models = list(self.models)
        self._local_available = bool(self._local_models) and self._provider_strategy != "cloud_only"
        self._cloud_available = _cloud_enabled(cfg) and self._provider_strategy != "local_only"
        self._provider_role_hint: str | None = None
        self._last_model_provider = "none"
        self._providers_used: list[str] = []

        cloud_models = [m for m in self._cloud_role_models.values() if m] if self._cloud_available else []
        cursor_models: list[str] = []
        if self._cursor_available:
            cc = self._cursor_cfg
            cursor_models = [str(x).strip() for x in (cc.get("allowed_models") or []) if str(x).strip()]
            dm = str(cc.get("default_model") or "").strip()
            if dm and dm not in cursor_models:
                cursor_models.insert(0, dm)
            elif not cursor_models and dm:
                cursor_models = [dm]
        xai_models: list[str] = []
        if self._xai_available:
            xai_models = [str(x).strip() for x in (self._xai_cfg.get("allowed_models") or []) if str(x).strip()]
            for rm in self._xai_role_models.values():
                s = str(rm or "").strip()
                if s and s not in xai_models:
                    xai_models.append(s)
        self.models = list(dict.fromkeys([*self._local_models, *cloud_models, *cursor_models, *xai_models]))
        self.base_payload["models"] = self.models
        meta = dict(self.base_payload.get("metadata") or {})
        cloud_name = str(self._cloud_cfg.get("name") or "openai_compatible").strip() or "openai_compatible"
        xai_name = str(self._xai_cfg.get("name") or "grok").strip() or "grok"
        meta.update({
            "provider": "gateway",
            "provider_strategy": self._provider_strategy,
            "providers_available": [
                p for p, enabled in (
                    ("ollama", self._local_available),
                    (cloud_name, self._cloud_available),
                    (xai_name, self._xai_available),
                    ("cursor_cloud_agent", self._cursor_available),
                )
                if enabled
            ],
        })
        self.base_payload["metadata"] = meta

        if self._provider_strategy == "cloud_only" and not (
            self._cloud_available or self._cursor_available or self._xai_available
        ):
            raise ProviderGatewayError("cloud_only_provider_unavailable")
        # A temporarily unavailable inference server must not prevent the worker
        # from serving deterministic ERP reads. Model-dependent routes block below.

    def provider_model_for(self: Any, role: str) -> str:
        role = role if role in {"fast", "agent", "analysis", "fallback"} else "fallback"
        runtime = getattr(self, "local_inference", None)
        if runtime is not None and self._provider_strategy != "cloud_only":
            self._local_models = runtime.models()
            self._local_available = bool(self._local_models)
            cloud_models = list(self._cloud_role_models.values()) if self._cloud_available else []
            cursor_models = []
            if self._cursor_available:
                cc = self._cursor_cfg
                cursor_models = [str(x).strip() for x in (cc.get("allowed_models") or []) if str(x).strip()]
                dm = str(cc.get("default_model") or "").strip()
                if dm and dm not in cursor_models:
                    cursor_models.insert(0, dm)
            xai_models = []
            if self._xai_available:
                xai_models = [str(x).strip() for x in (self._xai_cfg.get("allowed_models") or []) if str(x).strip()]
            self.models = list(dict.fromkeys([*self._local_models, *cloud_models, *cursor_models, *xai_models]))
            self.base_payload["models"] = self.models
        self._provider_role_hint = role
        strategy = self._provider_strategy
        local_model = str(self._local_role_models.get(role) or "").strip()
        local_fallback = str(self._local_role_models.get("fallback") or "").strip()
        cloud_model = str(self._cloud_role_models.get(role) or "").strip()
        xai_model = str(self._xai_role_models.get(role) or "").strip()
        cursor_default = ""
        if self._cursor_available:
            cursor_default = str(self._cursor_cfg.get("default_model") or "").strip()

        if strategy in {"cloud_only", "cloud_first"}:
            if self._cloud_available and cloud_model:
                return cloud_model
            if self._xai_available and xai_model:
                return xai_model
            if self._cursor_available and cursor_default:
                return cursor_default
        if self._local_available:
            if local_model in self._local_models:
                return local_model
            if local_fallback in self._local_models:
                return local_fallback
            if strategy == "local_only":
                raise RuntimeError(f"required_model_not_installed:{local_model}")
        if self._cloud_available and cloud_model:
            return cloud_model
        if self._xai_available and xai_model:
            return xai_model
        if self._cursor_available and cursor_default:
            return cursor_default
        # Preserve the old failure semantics when no usable provider exists.
        failure = ProviderGatewayError("no_model_available_for_role:" + role)
        failure.code = "unavailable"
        raise failure

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
        selection = (job.get("context") or {}).get("engine_selection") if isinstance(job.get("context"), dict) else None
        selected_model = None
        if isinstance(selection, dict):
            selected_provider = str(selection.get("provider") or "")
            selected_model = str(selection.get("model") or "").strip()
            if selected_provider == "gemini":
                allowed = self._cloud_cfg.get("allowed_models") or [x for x in self._cloud_role_models.values() if x]
                if (not self._cloud_available or str(self._cloud_cfg.get("name") or "") != "gemini"
                        or selected_model not in allowed):
                    raise ProviderGatewayError("gemini_model_not_configured_on_worker")
                providers = ["cloud"]
            elif selected_provider == "cursor":
                cursor_ids = _cursor_model_ids(self.cfg)
                allowed_c = list(cursor_ids) if cursor_ids else []
                if (not self._cursor_available or not selected_model
                        or (allowed_c and selected_model not in allowed_c)):
                    raise ProviderGatewayError("cursor_model_not_configured_on_worker")
                providers = ["cursor"]
            elif selected_provider == "grok":
                allowed_g = list(_xai_model_ids(self.cfg)) or list(self._xai_role_models.values())
                if (not self._xai_available or str(self._xai_cfg.get("name") or "grok") != "grok"
                        or not selected_model
                        or (allowed_g and selected_model not in allowed_g)):
                    raise ProviderGatewayError("grok_model_not_configured_on_worker")
                providers = ["xai"]
            elif selected_provider == "ollama":
                if not self._local_available or selected_model not in self._local_models:
                    raise ProviderGatewayError("ollama_model_not_installed_on_worker")
                providers = ["local"]
            else:
                raise ProviderGatewayError("job_provider_not_allowed")
        if not providers:
            raise ProviderGatewayError(f"no_provider_available_for_role:{role}")

        errors: list[str] = []
        for index, provider in enumerate(providers):
            try:
                if provider == "local":
                    local_model = str(selected_model or (requested_model if requested_model in self._local_models else str(self._local_role_models.get(role) or requested_model))).strip()
                    result = original_ollama_chat(
                        self, job, round_no, messages, tools, fast=fast, model=local_model,
                        num_ctx=num_ctx, num_predict=num_predict, temperature=temperature,
                        timeout_seconds=timeout_seconds, response_format=response_format,
                        think_override=think_override,
                    )
                    actual_provider = "ollama"
                elif provider == "cursor":
                    sm = str(selected_model or requested_model).strip()
                    result = _cursor_agent_chat(
                        self, job, round_no, messages, sm,
                    )
                    actual_provider = str(result.get("_provider") or "cursor_cloud_agent")
                elif provider == "xai":
                    result = _xai_chat(
                        self, job, round_no, messages, tools, role,
                        num_predict, temperature, timeout_seconds, response_format,
                        selected_model=str(selected_model) if selected_model else None,
                    )
                    actual_provider = str(result.get("_provider") or self._xai_cfg.get("name") or "grok")
                else:
                    result = _cloud_chat(
                        self, job, round_no, messages, tools, role,
                        num_predict, temperature, timeout_seconds, response_format,
                        **({"selected_model": selected_model} if isinstance(selection, dict) else {}),
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
                # Preserve a bounded failure code across the provider boundary.
                failure = ProviderGatewayError("model_provider_chain_failed:" + ",".join(errors))
                failure.code = str(getattr(exc, "code", "provider_error"))
                raise failure from exc

        raise ProviderGatewayError("model_provider_chain_exhausted")

    def provider_process_agent(self: Any, job: dict[str, Any], tools_desc: list[dict[str, Any]]) -> tuple[str, dict[str, Any]]:
        self._last_model_provider = "none"
        self._providers_used = []
        try:
            text, meta = original_process_agent(self, job, tools_desc)
        except Exception as exc:
            code = str(getattr(exc, "code", ""))
            capacity_codes = {"memory_admission", "memory_pressure", "deadline", "busy", "endpoint_cooling", "context_budget",
                              "response_budget", "incomplete_stream", "truncated", "empty_response",
                              "invalid_stream", "network", "missing_model", "unavailable"}
            proposal_names = {str(x.get("name")) for x in tools_desc if x.get("mode") == "proposal"}
            wrote = any(x.get("name") in proposal_names and x.get("status") == "succeeded"
                        for x in getattr(self, "_commercial_tool_events", []))
            if code not in capacity_codes or wrote:
                raise
            text = ("برای این درخواست پاسخ معتبر مدل آماده نشد؛ ظرفیت یا زمان اجرای مدل کافی نبود. "
                    "یک خوانش مشخص مانند «ماندهٔ @مشتری» را درخواست کن یا از موتور آمادهٔ دیگری استفاده کن. "
                    "این پاسخ عملیاتی ایجاد نکرده است.")
            meta = {"provider": "deterministic", "model": "none", "mode": "model_capacity_blocked",
                    "tools_used": [], "block_reason": code}
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
