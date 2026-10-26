"""Bounded Ollama transport, admission and endpoint failover (stdlib only).

This is a capacity guard, not a guarantee that any model fits any hardware.
Only explicitly configured endpoints receive prompts. No ERP result is cached.
"""
from __future__ import annotations

import contextlib
import hashlib
import http.client
import json
import math
import os
import socket
import threading
import time
from pathlib import Path
from urllib.parse import urlsplit


VERSION = "local-inference-v1.1"
MAX_WIRE_BYTES = 2 * 1024 * 1024


class InferenceError(RuntimeError):
    def __init__(self, code: str, diagnostics: dict | None = None):
        self.code = code
        self.diagnostics = diagnostics or {}
        super().__init__("local_inference:" + code)


def failure_details(exc: Exception) -> dict:
    """Allowlisted facts only: never exception messages, provider bodies or prompts."""
    result = {"exception_types": []}
    seen = set()
    while exc is not None and id(exc) not in seen and len(seen) < 5:
        seen.add(id(exc))
        result["exception_types"].append(type(exc).__name__)
        if isinstance(exc, InferenceError):
            for key in ("phase", "elapsed_seconds", "first_chunk_seconds", "first_content_seconds",
                        "content_chars", "thinking_chars", "chunk_count", "response_bytes", "http_status",
                        "endpoint_id", "retry_after_seconds", "required_mb", "available_mb", "resident_mb"):
                if key in exc.diagnostics:
                    result[key] = exc.diagnostics[key]
        exc = exc.__cause__
    return result


def token_upper_bound(messages: list, tools: list | None = None) -> int:
    """Conservative byte-based guard, not a model-specific token measurement.

    UTF-8 byte count avoids the English chars/4 assumption for Persian. Include
    message framing and tools, then reserve separate template/output space.
    """
    total = 0
    for message in messages:
        total += len(str(message.get("content") or "").encode("utf-8")) + 16
        if message.get("tool_calls"):
            total += len(json.dumps(message["tool_calls"], ensure_ascii=False).encode("utf-8"))
    if tools:
        total += len(json.dumps(tools, ensure_ascii=False, separators=(",", ":")).encode("utf-8"))
    return total


def _settings(cfg: dict) -> dict:
    value = cfg.get("local_runtime")
    return value if isinstance(value, dict) else {}


def _bounded(value, default: int, low: int, high: int) -> int:
    try:
        return max(low, min(high, int(value)))
    except (TypeError, ValueError, OverflowError):
        return default


def endpoint_specs(cfg: dict) -> list[dict]:
    primary = {"url": str(cfg.get("ollama_url") or "").rstrip("/")}
    if _settings(cfg).get("memory_budget_mb") is not None:
        primary["memory_budget_mb"] = _settings(cfg)["memory_budget_mb"]
    extra = cfg.get("ollama_fallback_endpoints") or []
    if not isinstance(extra, list):
        raise InferenceError("endpoint_config_invalid")
    out = []
    for item in [primary, *extra[:3]]:
        if not isinstance(item, dict):
            raise InferenceError("endpoint_config_invalid")
        url = str(item.get("url") or "").rstrip("/")
        p = urlsplit(url)
        if p.scheme not in {"http", "https"} or not p.hostname or p.username or p.password or p.query or p.fragment or p.path not in {"", "/"}:
            raise InferenceError("endpoint_url_invalid")
        if any(x["url"] == url for x in out):
            continue
        out.append({**item, "url": url, "id": hashlib.sha256(url.encode()).hexdigest()[:12]})
    return out


def _connection(url: str, timeout: float):
    p = urlsplit(url)
    cls = http.client.HTTPSConnection if p.scheme == "https" else http.client.HTTPConnection
    return cls(p.hostname, p.port, timeout=max(.05, timeout))


def _json_get(url: str, path: str, timeout: float = 2.0) -> dict:
    con = _connection(url, timeout)
    response = None
    transport_socket = [None]
    expired = threading.Event()

    def cancel():
        expired.set()
        sock = transport_socket[0] or con.sock
        if sock is not None:
            try:
                sock.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass
        # The timer only interrupts I/O. Closing HTTPResponse from this thread
        # races with http.client's chunk parser; the caller owns all cleanup.

    timer = threading.Timer(max(.05, timeout), cancel)
    timer.daemon = True
    timer.start()
    try:
        con.request("GET", path, headers={"Accept": "application/json"})
        transport_socket[0] = con.sock
        response = con.getresponse()
        raw = response.read(MAX_WIRE_BYTES + 1)
        if expired.is_set():
            raise InferenceError("deadline")
        if response.status != 200 or len(raw) > MAX_WIRE_BYTES:
            raise InferenceError("inventory_unavailable")
        data = json.loads(raw.decode("utf-8"))
        if not isinstance(data, dict):
            raise InferenceError("inventory_invalid")
        return data
    except (OSError, http.client.HTTPException, ValueError) as exc:
        raise InferenceError("deadline" if expired.is_set() else "inventory_unavailable") from exc
    finally:
        timer.cancel()
        if response is not None:
            response.close()
        con.close()


def _host_available_mb() -> float | None:
    try:
        fields = dict(line.split(":", 1) for line in Path("/proc/meminfo").read_text().splitlines())
        return int(fields["MemAvailable"].strip().split()[0]) / 1024
    except (OSError, KeyError, ValueError):
        return None


def admission_required_mb(model_size: int, context: int) -> float:
    # An intentionally conservative CPU planning estimate. KV usage is architecture
    # dependent; observed Ollama OOM errors still open the circuit.
    return model_size / (1024 * 1024) * 1.20 + 256 + context * .25


@contextlib.contextmanager
def endpoint_lock(directory: Path, key: str, deadline: float):
    """Cross-process serialization for workers sharing their data directory."""
    directory.mkdir(parents=True, exist_ok=True)
    path = directory / ("ollama-" + key + ".lock")
    with path.open("a+b") as handle:
        handle.seek(0, 2)
        if handle.tell() == 0:
            handle.write(b"0")
            handle.flush()
        acquired = False
        while not acquired:
            try:
                if os.name == "nt":
                    import msvcrt
                    handle.seek(0)
                    msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
                else:
                    import fcntl
                    fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                acquired = True
            except (BlockingIOError, OSError):
                if time.monotonic() >= deadline:
                    raise InferenceError("busy")
                time.sleep(min(.05, max(.001, deadline - time.monotonic())))
        try:
            yield
        finally:
            if os.name == "nt":
                import msvcrt
                handle.seek(0)
                msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)
            else:
                import fcntl
                fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def _classify(status: int, raw: bytes) -> str:
    # Never return/log raw provider bodies: they can include prompts or secrets.
    low = raw.decode("utf-8", "replace").lower()
    if any(x in low for x in ("out of memory", "requires more system memory", "unable to allocate", "insufficient memory", "cuda out", "failed to allocate")):
        return "memory_pressure"
    if status == 404:
        return "missing_model"
    if status in {429, 503}:
        return "busy"
    return "provider_error"


def stream_chat(url: str, body: dict, deadline: float, *, max_bytes: int = 65536, progress=None) -> dict:
    """Enforce wall time even while a server continuously streams partial bytes."""
    remaining = deadline - time.monotonic()
    if remaining <= 0:
        raise InferenceError("deadline")
    con = _connection(url, remaining)
    expired = threading.Event()
    transport_socket = [None]

    def cancel():
        expired.set()
        sock = transport_socket[0] or con.sock
        if sock is not None:
            try:
                sock.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass
        # Only interrupt I/O here; the reader owns response/connection cleanup.

    timer = threading.Timer(remaining, cancel)
    timer.daemon = True
    timer.start()
    started = time.monotonic()
    content, calls = [], []
    total = thinking = chunks = 0
    final = {}
    response = None
    first = None
    first_content = None
    phase = "request"
    last_trace = 0.0
    try:
        con.request("POST", "/api/chat", body=json.dumps(body, ensure_ascii=False, separators=(",", ":")).encode("utf-8"),
                    headers={"Content-Type": "application/json", "Accept": "application/x-ndjson"})
        transport_socket[0] = con.sock
        phase = "headers"
        response = con.getresponse()
        if response.status != 200:
            raise InferenceError(_classify(response.status, response.read(4096)))
        phase = "stream"
        while True:
            if expired.is_set() or time.monotonic() >= deadline:
                raise InferenceError("deadline")
            raw = response.readline(min(max_bytes - total + 1, 65537))
            if not raw:
                break
            total += len(raw)
            if total > max_bytes:
                raise InferenceError("response_budget")
            if not raw.strip():
                continue
            chunks += 1
            elapsed = time.monotonic() - started
            if first is None:
                first = elapsed
            try:
                chunk = json.loads(raw)
            except (ValueError, UnicodeDecodeError) as exc:
                raise InferenceError("invalid_stream") from exc
            if not isinstance(chunk, dict):
                raise InferenceError("invalid_stream")
            if chunk.get("error"):
                raise InferenceError(_classify(500, str(chunk["error"]).encode()))
            msg = chunk.get("message") or {}
            if not isinstance(msg, dict):
                raise InferenceError("invalid_stream")
            thinking += len(str(msg.get("thinking") or ""))
            if msg.get("content"):
                if first_content is None:
                    first_content = elapsed
                content.append(str(msg["content"]))
            if msg.get("tool_calls"):
                if not isinstance(msg["tool_calls"], list):
                    raise InferenceError("invalid_stream")
                calls.extend(msg["tool_calls"])
            if progress and elapsed - last_trace >= 5:
                progress({"elapsed_seconds": round(elapsed, 2), "first_chunk_seconds": first,
                          "thinking_chars": thinking, "content_chars": sum(map(len, content))})
                last_trace = elapsed
            if chunk.get("done") is True:
                final = chunk
                break
        if expired.is_set():
            raise InferenceError("deadline")
        if final.get("done") is not True:
            raise InferenceError("incomplete_stream")
        if final.get("done_reason") == "length":
            raise InferenceError("truncated")
        message = {"role": "assistant", "content": "".join(content)}
        if calls:
            message["tool_calls"] = calls
        if not message["content"].strip() and not calls:
            raise InferenceError("empty_response")
        metrics = {key: final.get(key) for key in (
            "load_duration", "prompt_eval_count", "prompt_eval_duration", "eval_count",
            "eval_duration", "total_duration", "done_reason")}
        metrics.update(model=str(final.get("model") or body["model"]),
                       requested_model=body["model"], elapsed_seconds=round(time.monotonic() - started, 3),
                       first_chunk_seconds=first, first_content_seconds=first_content,
                       thinking_chars=thinking, content_chars=sum(map(len, content)),
                       chunk_count=chunks, response_bytes=total)
        return {"message": message, "_metrics": metrics}
    except (InferenceError, OSError, http.client.HTTPException, ValueError) as exc:
        failure = exc if isinstance(exc, InferenceError) else InferenceError(
            "deadline" if expired.is_set() or time.monotonic() >= deadline else "network")
        failure.diagnostics.update(phase=phase, elapsed_seconds=round(time.monotonic() - started, 3),
            first_chunk_seconds=first, first_content_seconds=first_content,
            content_chars=sum(map(len, content)), thinking_chars=thinking, chunk_count=chunks,
            response_bytes=total, http_status=response.status if response is not None else None)
        if failure is exc:
            raise
        raise failure from exc
    finally:
        timer.cancel()
        if response is not None:
            response.close()
        con.close()


class LocalInference:
    def __init__(self, cfg: dict):
        self.cfg = cfg
        self.options = _settings(cfg)
        self.endpoints = endpoint_specs(cfg)
        self.directory = Path(str(self.options.get("state_dir") or Path(__file__).resolve().parent / "data"))
        self.inventory: dict[str, list[dict]] = {}
        self.inventory_at = 0.0
        self.failures: dict[tuple[str, str], tuple[float, str]] = {}
        self.endpoint_cooldowns: dict[str, float] = {}

    def _cooldown_path(self, ep: dict) -> Path:
        return self.directory / ("ollama-" + ep["id"] + ".cooldown.json")

    def _check_cooldown(self, ep: dict):
        until = self.endpoint_cooldowns.get(ep["id"], 0)
        try:
            path = self._cooldown_path(ep)
            if path.stat().st_size <= 1024:
                until = max(until, float(json.loads(path.read_text())["until_epoch"]))
        except (OSError, KeyError, ValueError, TypeError):
            pass
        remaining = until - time.time()
        if 0 < remaining <= 120:
            raise InferenceError("endpoint_cooling", {"endpoint_id": ep["id"],
                "retry_after_seconds": math.ceil(remaining * 100) / 100, "phase": "admission"})

    def _cool_endpoint(self, ep: dict):
        # Called under the endpoint lock. A client timeout does not prove the
        # daemon has stopped loading/generating: do not immediately load a peer
        # model on that daemon, including from a newly started Worker process.
        until = time.time() + _bounded(self.options.get("endpoint_cooldown_seconds"), 30, 5, 120)
        self.endpoint_cooldowns[ep["id"]] = until
        path = self._cooldown_path(ep)
        temp = path.with_suffix(".tmp")
        try:
            temp.write_text(json.dumps({"until_epoch": until}), encoding="utf-8")
            os.replace(temp, path)
        except OSError:
            pass  # The in-process guard still applies if persistence is unavailable.

    def ready_in_seconds(self) -> float:
        """Earliest configured endpoint readiness; no probes or sleeping."""
        waits = []
        for ep in self.endpoints:
            try:
                self._check_cooldown(ep)
                return 0.0
            except InferenceError as exc:
                waits.append(float(exc.diagnostics.get("retry_after_seconds") or 0))
        return min(waits) if waits else 0.0

    def models(self, refresh: bool = False) -> list[str]:
        if refresh or not self.inventory or time.monotonic() - self.inventory_at > 60:
            for ep in self.endpoints:
                try:
                    rows = _json_get(ep["url"], "/api/tags").get("models") or []
                    previous = {x["name"]: x.get("digest") for x in self.inventory.get(ep["id"], [])}
                    self.inventory[ep["id"]] = [x for x in rows if isinstance(x, dict) and x.get("name")]
                    for row in self.inventory[ep["id"]]:
                        if previous.get(row["name"]) != row.get("digest"):
                            self.failures.pop((ep["id"], row["name"]), None)
                except Exception:
                    self.inventory[ep["id"]] = []
            self.inventory_at = time.monotonic()
        return list(dict.fromkeys(str(x["name"]) for rows in self.inventory.values() for x in rows))

    def fingerprint(self) -> str:
        public = {"version": VERSION, "endpoints": self.endpoints, "options": self.options,
                  "models": {ep: [(x.get("name"), x.get("digest"), x.get("size")) for x in rows]
                             for ep, rows in self.inventory.items()}}
        return hashlib.sha256(json.dumps(public, sort_keys=True, default=str).encode()).hexdigest()

    def model_size(self, model: str) -> int:
        sizes = [int(x.get("size") or 0) for rows in self.inventory.values() for x in rows if x.get("name") == model]
        return min(sizes) if sizes else 0

    def _admit(self, ep: dict, model: str, ctx: int, deadline: float | None = None):
        rows = self.inventory.get(ep["id"], [])
        row = next((x for x in rows if x.get("name") == model), None)
        if rows and row is None:
            raise InferenceError("missing_model")
        size = int((row or {}).get("size") or 0)
        if not size:
            return  # Unknown is not evidence of capacity; transport still has guards.
        required = admission_required_mb(size, ctx)
        configured = float(ep.get("memory_budget_mb") or 0)
        if configured > 0 and required > configured:
            raise InferenceError("memory_admission", {"required_mb": round(required, 1),
                "available_mb": configured, "phase": "declared_capacity"})
        host = urlsplit(ep["url"]).hostname
        # Worker cgroup limits do not describe the sibling Ollama container.
        # Remote endpoints must use their own declared budget, never worker RAM.
        available = _host_available_mb() if host in {"localhost", "127.0.0.1", "::1", "ollama", "host.docker.internal"} else None
        if available is not None:
            resident = 0.0
            try:
                timeout = min(1, max(.05, deadline - time.monotonic())) if deadline is not None else 1
                for loaded in _json_get(ep["url"], "/api/ps", timeout=timeout).get("models") or []:
                    if loaded.get("name") == model:
                        resident = max(0, int(loaded.get("size") or 0) - int(loaded.get("size_vram") or 0)) / (1024 * 1024)
                        if int(loaded.get("size_vram") or 0) >= size:
                            return
            except Exception:
                pass
            reserve = _bounded(self.options.get("reserve_memory_mb"), 384, 128, 8192)
            if required > max(0, available - reserve) + resident:
                raise InferenceError("memory_admission", {"required_mb": round(required, 1),
                    "available_mb": round(max(0, available - reserve), 1),
                    "resident_mb": round(resident, 1), "phase": "host_capacity"})

    def chat(self, job: dict, round_no: int, messages: list, tools: list, *, trace,
             fast=False, model=None, num_ctx=None, num_predict=None, temperature=None,
             timeout_seconds=None, response_format=None, think_override=None) -> dict:
        model = str(model or self.cfg.get("chat_model") or "")
        ctx_max = _bounded(self.options.get("max_context"), 2048, 512, 32768)
        default_ctx = self.cfg.get("fast_num_ctx" if fast else "num_ctx", 1280 if fast else 2048)
        ctx = _bounded(num_ctx if num_ctx is not None else default_ctx, min(ctx_max, 1280 if fast else 2048), 512, ctx_max)
        default_predict = self.cfg.get("fast_num_predict" if fast else "num_predict", 160 if fast else 192)
        predict = _bounded(num_predict if num_predict is not None else default_predict, 160 if fast else 192, 16, min(4096, ctx - 256))
        estimated = token_upper_bound(messages, tools)
        required_context = estimated + predict + 192
        if required_context > ctx_max:
            raise InferenceError("context_budget")
        ctx = max(ctx, min(ctx_max, int(math.ceil(required_context / 128) * 128)))
        seconds = float(timeout_seconds if timeout_seconds is not None else self.cfg.get("ollama_timeout_seconds", 60))
        seconds = max(.05, min(seconds, float(self.options.get("max_call_seconds", 60))))
        deadline = time.monotonic() + seconds
        body = {
            "model": model, "messages": messages, "stream": True,
            "keep_alive": str(self.options.get("keep_alive", "5m")),
            "options": {"num_ctx": ctx, "num_predict": predict,
                        "temperature": float(temperature if temperature is not None else self.cfg.get("temperature", .2)),
                        "num_batch": _bounded(self.options.get("num_batch"), 32, 1, 512),
                        "num_thread": _bounded(self.options.get("num_thread"), min(4, os.cpu_count() or 1), 1, 64)},
        }
        if model.lower().startswith("qwen"):
            body["think"] = bool(self.cfg.get("think", False) if think_override is None else think_override)
        if tools:
            body["tools"] = tools
        if response_format is not None:
            body["format"] = response_format
        last = InferenceError("unavailable")
        for ep in self.endpoints:
            key = (ep["id"], model)
            failure = self.failures.get(key)
            if failure and failure[0] > time.monotonic():
                last = InferenceError(failure[1])
                continue
            if time.monotonic() >= deadline:
                break
            try:
                with endpoint_lock(self.directory, ep["id"], min(deadline, time.monotonic() + 1)):
                    self._check_cooldown(ep)
                    remaining_endpoints = len(self.endpoints) - self.endpoints.index(ep)
                    # Leave a share of the same deadline for an explicitly configured peer.
                    attempt_deadline = time.monotonic() + (deadline - time.monotonic()) / remaining_endpoints
                    self._admit(ep, model, ctx, deadline=attempt_deadline)
                    trace(job, "llm_request", "Bounded local model request", {
                        "model": model, "endpoint_id": ep["id"], "num_ctx": ctx,
                        "num_predict": predict, "prompt_token_upper_bound": estimated, "round": round_no + 1})
                    try:
                        result = stream_chat(ep["url"], body, attempt_deadline,
                                             max_bytes=_bounded(self.options.get("max_response_bytes"), 65536, 1024, MAX_WIRE_BYTES),
                                             progress=lambda data: trace(job, "llm_stream", "Model is processing", {"model": model, **data}))
                    except InferenceError as exc:
                        if exc.code == "deadline":
                            self._cool_endpoint(ep)
                        raise
                    result["_metrics"]["endpoint_id"] = ep["id"]
                    result["_metrics"]["round"] = round_no + 1
                    self.failures.pop(key, None)
                    trace(job, "llm_done", "Model response received", result["_metrics"])
                    return result
            except InferenceError as exc:
                exc.diagnostics.setdefault("endpoint_id", ep["id"])
                last = exc
                ttl = 300 if exc.code in {"memory_admission", "memory_pressure", "missing_model"} else 30
                if exc.code != "endpoint_cooling":
                    self.failures[key] = (time.monotonic() + ttl, exc.code)
                trace(job, "provider_fallback", "Local capacity/transport unavailable", {
                    "model": model, "endpoint_id": ep["id"], "reason": exc.code})
        raise last
