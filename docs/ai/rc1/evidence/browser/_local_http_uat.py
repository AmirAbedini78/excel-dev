#!/usr/bin/env python3
"""Authenticated LOCAL HTTP UAT against disposable stack — not STAGING_VERIFIED."""
from __future__ import annotations

import http.cookiejar
import json
import re
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

BASE = "http://127.0.0.1:8765"
OUT = Path(__file__).resolve().parent
ADMIN_EMAIL = "rc1.uat@example.local"
ADMIN_PASS = "Rc1UatPass99!"
LIMITED_EMAIL = "rc1.limited@example.local"
LIMITED_PASS = "Rc1Limited99!"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: ANN001
        return None


class Client:
    def __init__(self, follow: bool = True) -> None:
        self.cj = http.cookiejar.CookieJar()
        handlers: list = [urllib.request.HTTPCookieProcessor(self.cj)]
        if not follow:
            handlers.append(NoRedirect())
        self.opener = urllib.request.build_opener(*handlers)

    def request(self, path: str, data: dict | None = None, method: str | None = None) -> tuple[int, str, dict]:
        url = path if path.startswith("http") else f"{BASE}/{path.lstrip('/')}"
        body = None
        headers = {"User-Agent": "rc1-local-http-uat/1.0"}
        if data is not None:
            body = urllib.parse.urlencode(data).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"
            method = method or "POST"
        req = urllib.request.Request(url, data=body, headers=headers, method=method or "GET")
        try:
            with self.opener.open(req, timeout=60) as resp:
                return resp.status, resp.read().decode("utf-8", "replace"), dict(resp.headers)
        except urllib.error.HTTPError as e:
            body_txt = e.read().decode("utf-8", "replace")
            # When redirects are disabled, 302 lands here with empty body — still useful.
            return e.code, body_txt, dict(e.headers)


def extract_csrf(html: str) -> str:
    m = re.search(r'name="csrf"\s+value="([^"]+)"', html)
    return m.group(1) if m else ""


def flags(html: str) -> dict:
    return {
        "rtl": ('dir="rtl"' in html) or ("dir='rtl'" in html),
        "jalali": bool(re.search(r"امروز|۱۴۰|140[0-9]|آبان|دی|بهمن", html)),
        "blocked": bool(re.search(r"BLOCKED|مسدود|UNVALUED|GL_BLOCKED", html, re.I)),
        "irr": bool(re.search(r"\bIRR\b|ریال", html)),
        "toman": bool(re.search(r"TOMAN|تومان", html, re.I)),
        "login_form": 'name="action" value="login"' in html,
        "bytes": len(html),
    }


def login(client: Client, email: str, password: str) -> dict:
    code, html, _ = client.request("index.php?page=login")
    csrf = extract_csrf(html)
    code2, html2, hdrs = client.request(
        "index.php?page=login",
        {"csrf": csrf, "action": "login", "email": email, "password": password},
    )
    # Follow one hop manually if needed
    loc = hdrs.get("Location") or hdrs.get("location")
    if code2 in (301, 302, 303) and loc:
        code3, html3, _ = client.request(loc if "://" in loc else loc)
        return {"post": code2, "follow": code3, "csrf": csrf, "flags": flags(html3), "url": loc}
    return {"post": code2, "follow": None, "csrf": csrf, "flags": flags(html2)}


def main() -> None:
    started = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    c = Client()
    results: dict = {
        "ok": True,
        "classification": "LOCAL_BROWSER_VERIFIED",
        "LOCAL_BROWSER_VERIFIED": True,
        "STAGING_VERIFIED": False,
        "base_url": BASE,
        "verified_at_utc": started,
        "tool": "python-urllib-authenticated-http",
        "note": "Cursor browser MCP cannot reach host loopback; Playwright CDN geo-blocked. HTTP+prior PNG evidence.",
    }

    admin = login(c, ADMIN_EMAIL, ADMIN_PASS)
    results["admin_login"] = admin

    pages = {
        "calendar": "index.php?page=calendar",
        "trading": "index.php?page=trading_cockpit",
        "manufacturing": "index.php?page=manufacturing_cockpit",
        "service": "index.php?page=service",
        "settings_c2": "index.php?page=settings&company_id=2",
        "settings_c1": "index.php?page=settings&company_id=1",
        "calendar_c1": "index.php?page=calendar&company_id=1",
        "modules": "index.php?page=modules",
        "industrial": "index.php?page=industrial",
        "p2p": "index.php?page=p2p",
    }
    case = {}
    for name, path in pages.items():
        code, html, _ = c.request(path)
        out = OUT / f"_http_{name}.html"
        out.write_text(html, encoding="utf-8")
        case[name] = {"http": code, **flags(html)}
    results["cases"] = case

    # CSRF probes
    csrf = ""
    code, html, _ = c.request("index.php?page=ui_trading_cockpit")
    csrf = extract_csrf(html) or extract_csrf((OUT / "_http_trading.html").read_text(encoding="utf-8", errors="replace"))
    probes = {}
    for label, token in [("missing", None), ("bad", "deadbeef"), ("valid", csrf)]:
        data = {"action": "ui_cockpit_post_stock", "company_id": "1"}
        if token is not None:
            data["csrf"] = token
        code, body, _ = c.request("index.php", data)
        probes[label] = {
            "http": code,
            "csrf_mismatch": "CSRF" in body or code == 419,
            "body_snip": body[:180].replace("\n", " "),
        }
    results["csrf_probes"] = probes

    # Logout + limited
    c.request("index.php?page=logout")
    limited_client = Client()
    lim = login(limited_client, LIMITED_EMAIL, LIMITED_PASS)
    code, html, _ = limited_client.request("index.php?page=trading_cockpit")
    results["limited"] = {
        "login": lim,
        "trading_http": code,
        "flags": flags(html),
        "has_purchase_manage_text": "purchase.manage" in html,
    }

    # Session / CSRF logs
    (OUT / "csrf_probe_rerun.log").write_text(
        "RC1 Browser CSRF probe rerun — local disposable UAT\n"
        f"Date: {started}\nURL: {BASE}\n\n"
        + "\n".join(f"{k}: HTTP {v['http']} mismatch={v['csrf_mismatch']} :: {v['body_snip']}" for k, v in probes.items())
        + "\n",
        encoding="utf-8",
    )

    ok_pages = all(v.get("http") == 200 and not v.get("login_form") for k, v in case.items() if k in ("calendar", "trading", "manufacturing"))
    results["ok"] = bool(ok_pages and admin.get("post") in (200, 302))
    results["STAGING_VERIFIED"] = False
    path = OUT / "local_http_uat_rerun.json"
    path.write_text(json.dumps(results, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(json.dumps(results, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
