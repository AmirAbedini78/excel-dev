#!/usr/bin/env python3
"""HTTPS health probe restricted to staging host excel2.bcsrp.ir only."""
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


def host_allowed(host: str) -> bool:
    h = (host or "").strip().lower().rstrip(".")
    return h in ALLOWED_HOSTS


def probe(url: str, timeout: float = 20.0) -> dict:
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
    req = urllib.request.Request(url, method="GET", headers={"User-Agent": "ErpSmart-StagingHealth/1.0"})
    started = time.time()
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as resp:
            body = resp.read(4096)
            return {
                "ok": 200 <= resp.status < 400,
                "status": resp.status,
                "final_url": resp.geturl(),
                "elapsed_ms": int((time.time() - started) * 1000),
                "tls": True,
                "body_prefix_len": len(body),
                "host": parsed.hostname,
            }
    except urllib.error.HTTPError as e:
        # Login walls still prove TLS + HTTP stack are up.
        accepted = e.code in (401, 403, 302, 303, 307, 308)
        return {
            "ok": accepted,
            "status": e.code,
            "elapsed_ms": int((time.time() - started) * 1000),
            "tls": True,
            "host": parsed.hostname,
            "note": "http_error_accepted_as_up" if accepted else "http_error",
        }
    except Exception as e:  # noqa: BLE001
        return {
            "ok": False,
            "error": type(e).__name__,
            "message": str(e)[:200],
            "host": parsed.hostname,
            "elapsed_ms": int((time.time() - started) * 1000),
        }


def selftest() -> dict:
    checks = {
        "staging_allowed": host_allowed("excel2.bcsrp.ir"),
        "prod_refused": not host_allowed("excel.bcsrp.ir"),
        "http_scheme_refused": probe("http://excel2.bcsrp.ir/").get("error") == "https_required",
        "prod_url_refused": probe("https://excel.bcsrp.ir/").get("error") == "host_not_staging_allowlist",
    }
    return {"ok": all(checks.values()), "checks": checks}


def main() -> int:
    ap = argparse.ArgumentParser(description="Staging-only HTTPS health check")
    ap.add_argument("--url", default="https://excel2.bcsrp.ir/")
    ap.add_argument("--selftest", action="store_true")
    ap.add_argument("--manifest-out", type=Path, default=None)
    args = ap.parse_args()

    if args.selftest:
        result = selftest()
    else:
        result = probe(args.url)

    print(json.dumps(result, indent=2))
    if args.manifest_out:
        args.manifest_out.parent.mkdir(parents=True, exist_ok=True)
        args.manifest_out.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
    return 0 if result.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
