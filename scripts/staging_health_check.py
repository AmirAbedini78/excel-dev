#!/usr/bin/env python3
"""HTTPS + release-identity health probe restricted to staging host excel2.bcsrp.ir only.

Login page HTTP 200 alone is NOT success. When --require-release is set (post-deploy),
the probe also fetches /ERPSMART_STAGING_RELEASE.json over HTTPS and verifies git_sha
(+ optional file checksum map). SSH remote verify remains authoritative in apply;
this check catches CDN/docroot mismatches.
"""
from __future__ import annotations

import argparse
import json
import ssl
import time
import urllib.error
import urllib.request
from pathlib import Path
from urllib.parse import urlparse

ALLOWED_HOSTS = {"excel2.bcsrp.ir"}
RELEASE_PATH = "/ERPSMART_STAGING_RELEASE.json"


def host_allowed(host: str) -> bool:
    h = (host or "").strip().lower().rstrip(".")
    return h in ALLOWED_HOSTS


def _get(url: str, timeout: float = 20.0) -> dict:
    parsed = urlparse(url)
    if parsed.scheme != "https":
        return {"ok": False, "error": "https_required", "url": url}
    if not host_allowed(parsed.hostname or ""):
        return {
            "ok": False,
            "error": "host_not_staging_allowlist",
            "host": parsed.hostname,
            "allowed": sorted(ALLOWED_HOSTS),
            "url": url,
        }
    ctx = ssl.create_default_context()
    req = urllib.request.Request(url, method="GET", headers={"User-Agent": "ErpSmart-StagingHealth/1.1"})
    started = time.time()
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as resp:
            body = resp.read(2_000_000)
            return {
                "ok": 200 <= resp.status < 400,
                "status": resp.status,
                "final_url": resp.geturl(),
                "elapsed_ms": int((time.time() - started) * 1000),
                "tls": True,
                "body": body,
                "body_prefix_len": min(len(body), 4096),
                "host": parsed.hostname,
            }
    except urllib.error.HTTPError as e:
        accepted = e.code in (401, 403, 302, 303, 307, 308)
        return {
            "ok": accepted,
            "status": e.code,
            "elapsed_ms": int((time.time() - started) * 1000),
            "tls": True,
            "host": parsed.hostname,
            "note": "http_error_accepted_as_up" if accepted else "http_error",
            "body": e.read() if hasattr(e, "read") else b"",
        }
    except Exception as e:  # noqa: BLE001
        return {
            "ok": False,
            "error": type(e).__name__,
            "message": str(e)[:200],
            "host": parsed.hostname,
            "elapsed_ms": int((time.time() - started) * 1000),
        }


def probe(url: str, timeout: float = 20.0) -> dict:
    result = _get(url, timeout=timeout)
    # Strip raw body from default probe output.
    body = result.pop("body", None)
    if body is not None:
        result["body_prefix_len"] = min(len(body), 4096)
    return result


def probe_release(base_url: str, expected_sha: str | None, timeout: float = 20.0) -> dict:
    base = base_url.rstrip("/") + "/"
    parsed = urlparse(base)
    release_url = f"{parsed.scheme}://{parsed.netloc}{RELEASE_PATH}"
    raw = _get(release_url, timeout=timeout)
    body = raw.pop("body", b"") or b""
    out = {
        "url": release_url,
        "http_ok": bool(raw.get("ok")),
        "status": raw.get("status"),
        "tls": raw.get("tls"),
        "host": raw.get("host"),
        "elapsed_ms": raw.get("elapsed_ms"),
    }
    if raw.get("error"):
        out["error"] = raw["error"]
        out["ok"] = False
        return out
    if not out["http_ok"]:
        out["ok"] = False
        out["error"] = "release_meta_unreachable"
        out["note"] = "login_page_alone_is_not_success"
        return out
    try:
        meta = json.loads(body.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as e:
        out["ok"] = False
        out["error"] = "release_meta_invalid_json"
        out["detail"] = type(e).__name__
        return out
    got_sha = str(meta.get("git_sha") or "")
    out["git_sha"] = got_sha
    out["target_host"] = meta.get("target_host")
    out["file_count"] = meta.get("file_count")
    sha_ok = True
    if expected_sha:
        sha_ok = got_sha == expected_sha
        out["expected_git_sha"] = expected_sha
        out["git_sha_match"] = sha_ok
    host_ok = str(meta.get("target_host") or "") == "excel2.bcsrp.ir"
    out["target_host_ok"] = host_ok
    out["ok"] = bool(sha_ok and host_ok and got_sha)
    if not out["ok"] and not out.get("error"):
        out["error"] = "release_identity_mismatch"
    return out


def full_health(url: str, expected_sha: str | None = None, require_release: bool = False) -> dict:
    page = probe(url)
    result = {
        "page": page,
        "page_ok": bool(page.get("ok")),
        "require_release": require_release,
        "note": "login_page_alone_is_not_success" if require_release else "page_probe_only",
    }
    if require_release:
        release = probe_release(url, expected_sha)
        result["release"] = {k: v for k, v in release.items() if k != "body"}
        result["ok"] = bool(page.get("ok") and release.get("ok"))
        if not release.get("ok"):
            result["error"] = release.get("error") or "release_check_failed"
    else:
        result["ok"] = bool(page.get("ok"))
    return result


def selftest() -> dict:
    checks = {
        "staging_allowed": host_allowed("excel2.bcsrp.ir"),
        "prod_refused": not host_allowed("excel.bcsrp.ir"),
        "http_scheme_refused": probe("http://excel2.bcsrp.ir/").get("error") == "https_required",
        "prod_url_refused": probe("https://excel.bcsrp.ir/").get("error") == "host_not_staging_allowlist",
        "release_prod_refused": probe_release("https://excel.bcsrp.ir/", "abc").get("error")
        == "host_not_staging_allowlist",
    }
    return {"ok": all(checks.values()), "checks": checks}


def main() -> int:
    ap = argparse.ArgumentParser(description="Staging-only HTTPS + release health check")
    ap.add_argument("--url", default="https://excel2.bcsrp.ir/")
    ap.add_argument("--selftest", action="store_true")
    ap.add_argument("--require-release", action="store_true", help="Require ERPSMART_STAGING_RELEASE.json identity")
    ap.add_argument("--expected-sha", default=None)
    ap.add_argument("--manifest-out", type=Path, default=None)
    args = ap.parse_args()

    if args.selftest:
        result = selftest()
    else:
        result = full_health(args.url, expected_sha=args.expected_sha, require_release=args.require_release)

    print(json.dumps(result, indent=2))
    if args.manifest_out:
        args.manifest_out.parent.mkdir(parents=True, exist_ok=True)
        args.manifest_out.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
    return 0 if result.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
