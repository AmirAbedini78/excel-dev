#!/usr/bin/env python3
"""List remote model IDs for Cursor Cloud and x.ai (Grok).

Docs:
  Cursor: https://cursor.com/docs/cloud-agent/api/endpoints (GET /v1/models)
  Grok:   https://docs.x.ai/overview (OpenAI-compatible listing when available)
"""
from __future__ import annotations

import argparse
import base64
import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path


def _engine_dir() -> Path:
    return Path(__file__).resolve().parent


def _load_env_file(path: Path) -> None:
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key and key not in os.environ:
            os.environ[key] = value


def _normalize_api_key(value: str, var_name: str) -> str:
    key = (value or "").strip()
    if not key:
        return ""
    placeholders = {"...", "…", "your_key_here", "your-key", "REPLACE_WITH_YOUR_CURSOR_API_KEY",
                      "REPLACE_WITH_YOUR_XAI_API_KEY"}
    if key in placeholders or key.startswith("REPLACE_"):
        print(
            f"{var_name} looks like a placeholder, not a real key. "
            f"Set it in engine/.env.gemini or pass the full key in the shell env.",
            file=sys.stderr,
        )
        return ""
    return key


def _cursor_basic_header(key: str) -> str:
    token = base64.b64encode(f"{key}:".encode("utf-8")).decode("ascii")
    return "Basic " + token


def _http_json(url: str, headers: dict[str, str], method: str = "GET") -> dict | list:
    req = urllib.request.Request(url, headers=headers, method=method)
    with urllib.request.urlopen(req, timeout=60) as resp:
        return json.loads(resp.read().decode("utf-8"))


def _cursor_request(path: str, api_key: str, base: str = "https://api.cursor.com") -> dict | list:
    url = base.rstrip("/") + path
    auth_modes = [
        ("Basic", _cursor_basic_header(api_key)),
        ("Bearer", "Bearer " + api_key),
    ]
    last_body = ""
    for _name, auth in auth_modes:
        try:
            return _http_json(url, {"Accept": "application/json", "Authorization": auth})
        except urllib.error.HTTPError as exc:
            last_body = exc.read().decode("utf-8", errors="replace")[:500]
            if exc.code in {401, 403}:
                continue
            raise
    hint = (
        "Check: key from Cursor Dashboard → Integrations → API Keys "
        "(not an expired/revoked key). Team *Admin* keys may not work for Cloud Agents."
    )
    raise urllib.error.HTTPError(
        url, 401, f"Cursor auth failed ({last_body or 'unauthorized'}). {hint}", None, None
    )


def fetch_cursor_models(api_key: str, base: str = "https://api.cursor.com") -> list[str]:
    data = _cursor_request("/v1/models", api_key, base)
    items: list = []
    if isinstance(data, dict):
        if isinstance(data.get("items"), list):
            items = data["items"]
        elif isinstance(data.get("models"), list):
            items = data["models"]
    elif isinstance(data, list):
        items = data
    out: list[str] = []
    for m in items:
        if isinstance(m, dict):
            mid = str(m.get("id") or "").strip()
            if mid:
                out.append(mid)
            for alias in m.get("aliases") or []:
                s = str(alias).strip()
                if s and s not in out:
                    out.append(s)
        elif m:
            out.append(str(m))
    return out


def verify_cursor_key(api_key: str) -> None:
    data = _cursor_request("/v1/me", api_key)
    if isinstance(data, dict):
        name = data.get("apiKeyName") or data.get("userEmail") or "ok"
        print(f"Cursor API key OK: {name}", file=sys.stderr)


def fetch_xai_models(api_key: str, base: str = "https://api.x.ai/v1") -> list[str]:
    url = base.rstrip("/") + "/models"
    req = urllib.request.Request(
        url,
        headers={
            "Accept": "application/json",
            "Authorization": "Bearer " + api_key,
        },
        method="GET",
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        data = json.loads(resp.read().decode("utf-8"))
    rows = data.get("data") if isinstance(data, dict) else data
    if not isinstance(rows, list):
        return []
    return [str(m.get("id") or "") for m in rows if isinstance(m, dict) and m.get("id")]


def main() -> int:
    parser = argparse.ArgumentParser(description="List Cursor / x.ai model IDs for worker config.")
    parser.add_argument("--cursor", action="store_true", help="Fetch Cursor GET /v1/models")
    parser.add_argument("--xai", action="store_true", help="Fetch x.ai GET /v1/models")
    parser.add_argument("--json", action="store_true", help="Print JSON array")
    parser.add_argument(
        "--env-file",
        default="",
        help="Dotenv file (default: engine/.env.gemini if present)",
    )
    args = parser.parse_args()
    if not args.cursor and not args.xai:
        args.cursor = args.xai = True

    if args.env_file:
        _load_env_file(Path(args.env_file))
    else:
        engine = _engine_dir()
        for name in (".env.gemini", ".env.cursor", ".env.xai"):
            _load_env_file(engine / name)

    out: dict[str, list[str]] = {}
    if args.cursor:
        key = _normalize_api_key(os.environ.get("CURSOR_API_KEY", ""), "CURSOR_API_KEY")
        if not key:
            return 1
        try:
            verify_cursor_key(key)
            out["cursor"] = fetch_cursor_models(key)
        except urllib.error.HTTPError as exc:
            print(f"Cursor HTTP {exc.code}: {exc.reason}", file=sys.stderr)
            return 1
        except urllib.error.URLError as exc:
            print(f"Cursor network error: {exc.reason}", file=sys.stderr)
            return 1
    if args.xai:
        key = _normalize_api_key(os.environ.get("XAI_API_KEY", ""), "XAI_API_KEY")
        if not key:
            return 1
        try:
            out["xai"] = fetch_xai_models(key)
        except urllib.error.HTTPError as exc:
            body = exc.read().decode("utf-8", errors="replace")[:300]
            print(f"x.ai HTTP {exc.code}: {body or exc.reason}", file=sys.stderr)
            return 1

    if args.json:
        print(json.dumps(out, ensure_ascii=False, indent=2))
        return 0
    for provider, models in out.items():
        print(f"## {provider}")
        for m in models:
            print(m)
        print()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
