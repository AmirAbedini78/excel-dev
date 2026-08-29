#!/usr/bin/env python3
"""Thread-local persistent HTTP(S) transport for ERPSMART control-plane calls.

Keeps one connection per thread so the main worker tool loop and heartbeat
thread never share a socket. Falls back to reconnect/retry on closed or broken
connections. Uses only Python stdlib and preserves TLS certificate validation.
"""
from __future__ import annotations

import http.client
import json
import socket
import ssl
import sys
import threading
import time
import urllib.parse
import uuid
from typing import Any


def _connection_key(parsed: urllib.parse.SplitResult) -> tuple[str, str, int]:
    scheme = parsed.scheme.lower()
    host = parsed.hostname or ""
    if scheme not in {"http", "https"} or not host:
        raise RuntimeError("server_url must be absolute http(s)")
    port = parsed.port or (443 if scheme == "https" else 80)
    return scheme, host, port


def _make_connection(parsed: urllib.parse.SplitResult, timeout: int):
    scheme, host, port = _connection_key(parsed)
    if scheme == "https":
        return http.client.HTTPSConnection(
            host,
            port,
            timeout=timeout,
            context=ssl.create_default_context(),
        )
    return http.client.HTTPConnection(host, port, timeout=timeout)


def _action_path(parsed: urllib.parse.SplitResult, action: str) -> str:
    query = urllib.parse.parse_qsl(parsed.query, keep_blank_values=True)
    query.append(("action", action))
    path = parsed.path or "/"
    encoded = urllib.parse.urlencode(query)
    return path + (("?" + encoded) if encoded else "")


def install_api_transport(api_cls: type) -> None:
    if getattr(api_cls, "_keepalive_transport_v1_installed", False):
        return

    original_init = api_cls.__init__

    def keepalive_init(self: Any, cfg: dict[str, Any]) -> None:
        original_init(self, cfg)
        self._keepalive_parsed = urllib.parse.urlsplit(self.url)
        _connection_key(self._keepalive_parsed)
        self._keepalive_local = threading.local()

    def reset_connection(self: Any) -> None:
        local = self._keepalive_local
        conn = getattr(local, "connection", None)
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass
        local.connection = None
        local.connection_key = None

    def get_connection(self: Any, timeout: int):
        parsed = self._keepalive_parsed
        key = _connection_key(parsed)
        local = self._keepalive_local
        conn = getattr(local, "connection", None)
        reused = conn is not None and getattr(local, "connection_key", None) == key

        if not reused:
            reset_connection(self)
            conn = _make_connection(parsed, timeout)
            local.connection = conn
            local.connection_key = key
        else:
            conn.timeout = timeout
            if getattr(conn, "sock", None) is not None:
                conn.sock.settimeout(timeout)
        return conn, reused

    def keepalive_post(self: Any, action: str, payload: dict[str, Any], timeout: int | None = None) -> dict[str, Any]:
        data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        request_timeout = int(timeout or self.timeout)
        last_error: Exception | None = None
        request_id = uuid.uuid4().hex
        path = _action_path(self._keepalive_parsed, action)

        for attempt in range(1, self.retry_attempts + 1):
            started = time.monotonic()
            reused = False
            try:
                conn, reused = get_connection(self, request_timeout)
                conn.request(
                    "POST",
                    path,
                    body=data,
                    headers={
                        "Content-Type": "application/json",
                        "Accept": "application/json",
                        "Content-Length": str(len(data)),
                        "X-AI-Worker-Token": self.token,
                        "X-AI-Request-ID": request_id,
                        "User-Agent": "AccountingAIWorker/1.2",
                        "Connection": "keep-alive",
                    },
                )
                response = conn.getresponse()
                raw = response.read()
                status = int(response.status)
                content_type = str(response.getheader("Content-Type") or "")
                will_close = bool(response.will_close)
                if will_close:
                    reset_connection(self)

                elapsed = time.monotonic() - started
                print(
                    f"[api timing] action={action} elapsed={elapsed:.3f}s "
                    f"attempt={attempt} reused={str(reused).lower()} status={status}",
                    flush=True,
                )

                if status >= 400:
                    preview = self._safe_preview(raw)
                    last_error = RuntimeError(
                        f"Server HTTP {status} action={action} request_id={request_id}: {preview}"
                    )
                    if status not in self.TRANSIENT_HTTP or attempt >= self.retry_attempts:
                        raise last_error
                    reset_connection(self)
                elif not raw.strip():
                    raise RuntimeError(
                        f"Server empty response action={action} status={status} "
                        f"content_type={content_type or 'unknown'}"
                    )
                else:
                    try:
                        out = json.loads(raw.decode("utf-8"))
                    except json.JSONDecodeError as exc:
                        raise RuntimeError(
                            f"Server non-JSON response action={action} status={status} "
                            f"content_type={content_type or 'unknown'} body={self._safe_preview(raw)}"
                        ) from exc

                    if not isinstance(out, dict):
                        raise RuntimeError(f"Server JSON root is not object action={action}")
                    if not out.get("ok"):
                        raise RuntimeError(str(out.get("error", "server_error")))
                    return out

            except (
                http.client.HTTPException,
                ConnectionError,
                TimeoutError,
                socket.timeout,
                OSError,
            ) as exc:
                last_error = exc
                reset_connection(self)
                if attempt >= self.retry_attempts:
                    raise RuntimeError(
                        f"Server network error action={action} after {attempt} attempts: {exc}"
                    ) from exc
            except RuntimeError as exc:
                last_error = exc
                transient_text = str(exc).lower()
                transient = (
                    "empty response" in transient_text
                    or "non-json response" in transient_text
                    or "server http 408" in transient_text
                    or "server http 425" in transient_text
                    or "server http 429" in transient_text
                    or "server http 500" in transient_text
                    or "server http 502" in transient_text
                    or "server http 503" in transient_text
                    or "server http 504" in transient_text
                )
                if not transient or attempt >= self.retry_attempts:
                    raise
                reset_connection(self)

            delay = min(12.0, self.retry_base_seconds * (2 ** (attempt - 1)))
            print(
                f"[api retry] action={action} attempt={attempt}/{self.retry_attempts} "
                f"delay={delay:.1f}s reason={type(last_error).__name__}",
                file=sys.stderr,
                flush=True,
            )
            time.sleep(delay)

        raise RuntimeError(f"Server request failed action={action}: {last_error}")

    api_cls.__init__ = keepalive_init
    api_cls.post = keepalive_post
    api_cls._keepalive_reset_connection = reset_connection
    api_cls._keepalive_transport_v1_installed = True
    api_cls._keepalive_transport_v1_original_post = getattr(api_cls, "post", None)


def _self_test() -> None:
    from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

    class CountingServer(ThreadingHTTPServer):
        daemon_threads = True
        def __init__(self, addr, handler):
            super().__init__(addr, handler)
            self.accepted = 0
        def get_request(self):
            sock, addr = super().get_request()
            self.accepted += 1
            return sock, addr

    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"
        def do_POST(self):
            length = int(self.headers.get("Content-Length") or 0)
            self.rfile.read(length)
            raw = b'{"ok":true,"result":{"pass":true}}'
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(raw)))
            self.send_header("Connection", "keep-alive")
            self.end_headers()
            self.wfile.write(raw)
        def log_message(self, fmt, *args):
            return

    class DummyApi:
        TRANSIENT_HTTP = {408, 425, 429, 500, 502, 503, 504}
        def __init__(self, cfg):
            self.url = cfg["server_url"]
            self.token = cfg["worker_token"]
            self.timeout = 5
            self.retry_attempts = 2
            self.retry_base_seconds = 0.01
        @staticmethod
        def _safe_preview(raw: bytes, limit: int = 500) -> str:
            return raw.decode("utf-8", "replace")[:limit]
        def post(self, action, payload, timeout=None):
            raise AssertionError("transport not installed")

    server = CountingServer(("127.0.0.1", 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        install_api_transport(DummyApi)
        api = DummyApi({
            "server_url": f"http://127.0.0.1:{server.server_port}/ai_api.php?scope=test",
            "worker_token": "test",
        })
        one = api.post("tool", {"n": 1})
        two = api.post("tool", {"n": 2})
        assert one["result"]["pass"] is True
        assert two["result"]["pass"] is True
        assert server.accepted == 1, f"expected one TCP connection, got {server.accepted}"
        print("API_KEEPALIVE_SELF_TEST: PASS")
    finally:
        server.shutdown()
        server.server_close()


if __name__ == "__main__":
    if "--self-test" in sys.argv:
        _self_test()