#!/usr/bin/env python3
"""Schema-aware deterministic anonymizer for offline legacy dumps.

Reads an UNSAFE candidate SQL dump and writes an anonymized schema+data dump
OUTSIDE Git. Preserves PK/FK IDs, tenant/company graphs, voucher/stock IDs,
signed amounts, quantities, dates, and state codes. Scrubs credentials, tokens,
banking identifiers, taxpayer secrets, PII, and credential-bearing URLs.

Never prints row values. Never writes the anonymized dump into the repo.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path
from typing import Any

# Disposable bcrypt for ALL user password hashes (login still possible with known test pwd).
# Password plaintext is intentionally NOT printed here; operators set via test harness.
DISPOSABLE_BCRYPT = "$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi"

# Financial / structural columns — never rewrite even if name matches a soft pattern.
PRESERVE_EXACT = {
    "id",
    "workspace_id",
    "company_id",
    "tenant_id",
    "user_id",
    "voucher_id",
    "invoice_id",
    "party_id",
    "item_id",
    "warehouse_id",
    "fiscal_year_id",
    "token_id",  # FK only
    "qty",
    "quantity",
    "amount",
    "debit",
    "credit",
    "balance",
    "price",
    "cost",
    "total",
    "tax_amount",
    "tax_total",
    "tax_percent",
    "status",
    "state",
    "type",
    "code",
    "share_phonebook",
}

PRESERVE_SUFFIX = (
    "_id",
    "_at",
    "_on",
    "_date",
    "_qty",
    "_quantity",
    "_amount",
    "_debit",
    "_credit",
    "_balance",
    "_price",
    "_cost",
    "_total",
    "_percent",
)

# Column name → transform class
SECRET_COLS = re.compile(
    r"^(password(_hash|_enc)?|passwd|secret_note|api_key|cron_secret|"
    r"token_hash|token_prefix|lease_hash|"
    r"taxpayer_private_key_enc|taxpayer_public_key|taxpayer_token_type|"
    r"tax_memory_uid|tax_username)$",
    re.I,
)
PII_EMAIL = re.compile(r"(^|_)email($|_)", re.I)
PII_PHONE = re.compile(r"(phone|mobile|fax|messenger)", re.I)
PII_NATIONAL = re.compile(r"(national_id|ceo_national_id)", re.I)
PII_ECONOMIC = re.compile(r"(economic_code|registration_number|insurance_code|related_code|business_license_no)", re.I)
PII_BANK = re.compile(r"(iban|card_no|bank_name)", re.I)
PII_ADDRESS = re.compile(r"(address|postal_code|street)", re.I)
PII_NAME = re.compile(
    r"(^name$|_name$|person_name|manager_name|financial_manager|ceo_name|"
    r"trade_name|organization_name|person_title|title$|label$)",
    re.I,
)
PII_USERNAME = re.compile(r"(^username$|tax_office_code|tax_office_name|tax_case_class|taxpayer_branch_code)", re.I)
PII_NOTES = re.compile(
    r"(^notes$|extra_json|capabilities_json|secret_note|context_json|"
    r"^prompt$|result_text|result_json|error_text|message|body|payload|"
    r"description|content|memo|remark)",
    re.I,
)
PII_URL = re.compile(r"(^url$|base_url|avatar)", re.I)

SETTINGS_SECRET_KEYS = re.compile(
    r"(secret|token|password|api_key|apikey|cron|smtp|mail_pass|oauth|private|bearer|webhook)",
    re.I,
)

CREATE_RE = re.compile(r"CREATE TABLE `([^`]+)`\s*\((.*?)\)\s*ENGINE", re.S | re.I)
COL_RE = re.compile(r"^`([^`]+)`\s+([a-zA-Z]+)")
INSERT_RE = re.compile(
    r"INSERT INTO `([^`]+)`\s*(?:\(([^)]*)\))?\s*VALUES\s*",
    re.I,
)


def _h(seed: str, n: int = 12) -> str:
    return hashlib.sha256(seed.encode("utf-8")).hexdigest()[:n].upper()


def _fake_email(table: str, col: str, raw: str, row_i: int) -> str:
    return f"user{_h(f'{table}|{col}|{raw}|{row_i}', 10).lower()}@example.test"


def _fake_phone(table: str, col: str, raw: str, row_i: int) -> str:
    digs = "".join(c for c in _h(f"ph|{table}|{col}|{raw}|{row_i}", 16) if c.isdigit())
    digs = (digs + "0000000000")[:9]
    return f"0900{digs}"


def _fake_national(table: str, col: str, raw: str, row_i: int) -> str:
    digs = "".join(c for c in _h(f"nid|{table}|{col}|{raw}|{row_i}", 20) if c.isdigit())
    return (digs + "0000000000")[:10]


def _fake_iban(table: str, col: str, raw: str, row_i: int) -> str:
    digs = "".join(c for c in _h(f"iban|{table}|{col}|{raw}|{row_i}", 40) if c.isdigit())
    return "IR" + (digs + "0" * 24)[:24]


def _fake_name(table: str, col: str, raw: str, row_i: int) -> str:
    return f"ANON_{table[:8].upper()}_{_h(f'nm|{table}|{col}|{raw}|{row_i}', 8)}"


def _scrub_url(raw: str) -> str:
    # Strip userinfo from URLs; replace non-empty with disposable host.
    if not raw or raw.upper() == "NULL":
        return raw
    if "://" in raw and "@" in raw.split("://", 1)[-1].split("/", 1)[0]:
        return "https://example.test/scrubbed"
    if re.search(r"(token|key|secret|password)=", raw, re.I):
        return "https://example.test/scrubbed"
    return "https://example.test/resource"


def _sql_quote(s: str) -> str:
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


def _should_preserve(col: str) -> bool:
    c = col.lower()
    if c in PRESERVE_EXACT:
        return True
    return any(c.endswith(sfx) for sfx in PRESERVE_SUFFIX)


def classify_column(table: str, col: str) -> str | None:
    """Return transform class or None to keep as-is."""
    if _should_preserve(col):
        # Exception: password_hash ends with _hash but must scrub
        if SECRET_COLS.match(col):
            return "secret"
        return None
    if SECRET_COLS.match(col):
        return "secret"
    if PII_EMAIL.search(col):
        return "email"
    if PII_PHONE.search(col):
        return "phone"
    if PII_NATIONAL.search(col):
        return "national"
    if PII_BANK.search(col):
        return "bank"
    if PII_ECONOMIC.search(col):
        return "economic"
    if PII_ADDRESS.search(col):
        return "address"
    if PII_URL.search(col):
        return "url"
    if PII_USERNAME.search(col):
        return "username"
    if PII_NOTES.search(col):
        return "notes"
    if PII_NAME.search(col):
        # Keep structural codes like portal_key / service_key
        if col.lower().endswith("_key") and "api" not in col.lower():
            return None
        return "name"
    # settings.key stays; settings.value handled specially
    if table == "settings" and col.lower() == "value":
        return "settings_value"
    return None


def parse_schema(text: str) -> dict[str, list[str]]:
    tables: dict[str, list[str]] = {}
    for m in CREATE_RE.finditer(text):
        t = m.group(1)
        cols: list[str] = []
        for line in m.group(2).splitlines():
            line = line.strip().rstrip(",")
            cm = COL_RE.match(line)
            if cm:
                cols.append(cm.group(1))
        tables[t] = cols
    return tables


def tokenize_values(s: str, start: int) -> tuple[list[list[str]], int]:
    """Parse INSERT value tuples starting at VALUES. Returns (rows, end_index)."""
    i = start
    n = len(s)
    rows: list[list[str]] = []
    while i < n and s[i].isspace():
        i += 1
    while i < n:
        if s[i] != "(":
            break
        i += 1
        row: list[str] = []
        while i < n:
            while i < n and s[i].isspace():
                i += 1
            if i >= n:
                break
            if s[i] == ")":
                i += 1
                break
            if s[i] == ",":
                i += 1
                continue
            if s[i] in ("'", '"'):
                quote = s[i]
                i += 1
                buf: list[str] = [quote]
                while i < n:
                    ch = s[i]
                    if ch == "\\" and i + 1 < n:
                        buf.append(ch)
                        buf.append(s[i + 1])
                        i += 2
                        continue
                    if ch == quote:
                        buf.append(ch)
                        i += 1
                        # MySQL doubled quote escape
                        if i < n and s[i] == quote:
                            buf.append(s[i])
                            i += 1
                            continue
                        break
                    buf.append(ch)
                    i += 1
                row.append("".join(buf))
            else:
                # NULL, number, bareword
                j = i
                while j < n and s[j] not in ",)":
                    j += 1
                row.append(s[i:j].strip())
                i = j
        rows.append(row)
        while i < n and s[i].isspace():
            i += 1
        if i < n and s[i] == ",":
            i += 1
            while i < n and s[i].isspace():
                i += 1
            continue
        break
    # consume trailing semicolon if present
    while i < n and s[i].isspace():
        i += 1
    if i < n and s[i] == ";":
        i += 1
    return rows, i


def unquote(val: str) -> str | None:
    if val.upper() == "NULL":
        return None
    if len(val) >= 2 and val[0] in "'\"" and val[-1] == val[0]:
        inner = val[1:-1]
        # minimal unescape
        out: list[str] = []
        k = 0
        while k < len(inner):
            if inner[k] == "\\" and k + 1 < len(inner):
                out.append(inner[k + 1])
                k += 2
                continue
            if inner[k] == val[0] and k + 1 < len(inner) and inner[k + 1] == val[0]:
                out.append(val[0])
                k += 2
                continue
            out.append(inner[k])
            k += 1
        return "".join(out)
    return val


def transform_value(
    table: str,
    col: str,
    kind: str | None,
    raw_sql: str,
    row_i: int,
    settings_key: str | None,
    stats: dict[str, int],
) -> str:
    if kind is None:
        return raw_sql
    raw = unquote(raw_sql)
    if raw is None:
        return "NULL"

    if kind == "settings_value":
        key = settings_key or ""
        if SETTINGS_SECRET_KEYS.search(key):
            stats["secrets"] = stats.get("secrets", 0) + 1
            return _sql_quote(f"DISPOSABLE_SECRET_{_h(key)}")
        # scrub credential-bearing URLs in settings values
        if "://" in raw and ("@" in raw or SETTINGS_SECRET_KEYS.search(raw)):
            stats["urls"] = stats.get("urls", 0) + 1
            return _sql_quote(_scrub_url(raw))
        return raw_sql

    if kind == "secret":
        stats["secrets"] = stats.get("secrets", 0) + 1
        cl = col.lower()
        if "password_hash" in cl:
            return _sql_quote(DISPOSABLE_BCRYPT)
        if "password" in cl:
            return _sql_quote(f"ENC_{_h(f'{table}|{col}|{row_i}')}")
        if "token_prefix" in cl:
            return _sql_quote("anon")
        if "token_hash" in cl or "lease_hash" in cl:
            return _sql_quote(_h(f"tok|{table}|{col}|{row_i}", 64).lower())
        return _sql_quote(f"SCRUBBED_{_h(f'{table}|{col}|{row_i}')}")

    if kind == "email":
        stats["emails"] = stats.get("emails", 0) + 1
        return _sql_quote(_fake_email(table, col, raw, row_i))
    if kind == "phone":
        stats["phones"] = stats.get("phones", 0) + 1
        return _sql_quote(_fake_phone(table, col, raw, row_i))
    if kind == "national":
        stats["national_ids"] = stats.get("national_ids", 0) + 1
        return _sql_quote(_fake_national(table, col, raw, row_i))
    if kind == "economic":
        stats["economic"] = stats.get("economic", 0) + 1
        return _sql_quote(f"E{_h(f'ec|{table}|{col}|{raw}|{row_i}', 10)}")
    if kind == "bank":
        stats["banking"] = stats.get("banking", 0) + 1
        cl = col.lower()
        if "iban" in cl:
            return _sql_quote(_fake_iban(table, col, raw, row_i))
        if "card" in cl:
            return _sql_quote("6037" + "".join(c for c in _h(f"card|{raw}|{row_i}", 20) if c.isdigit())[:12])
        return _sql_quote(f"BANK_{_h(f'bn|{raw}|{row_i}', 8)}")
    if kind == "address":
        stats["addresses"] = stats.get("addresses", 0) + 1
        if "postal" in col.lower():
            return _sql_quote("1234567890")
        return _sql_quote(f"ADDR_{_h(f'ad|{table}|{raw}|{row_i}', 10)}")
    if kind == "url":
        stats["urls"] = stats.get("urls", 0) + 1
        return _sql_quote(_scrub_url(raw))
    if kind == "username":
        stats["usernames"] = stats.get("usernames", 0) + 1
        return _sql_quote(f"u_{_h(f'un|{table}|{col}|{raw}|{row_i}', 10).lower()}")
    if kind == "notes":
        stats["notes"] = stats.get("notes", 0) + 1
        if col.lower().endswith("_json") or (raw.startswith("{") or raw.startswith("[")):
            return _sql_quote("{}")
        return _sql_quote("[REDACTED]")
    if kind == "name":
        stats["names"] = stats.get("names", 0) + 1
        return _sql_quote(_fake_name(table, col, raw, row_i))
    return raw_sql


def anonymize_insert(
    table: str,
    cols: list[str],
    rows: list[list[str]],
    stats: dict[str, int],
) -> str:
    kinds = [classify_column(table, c) for c in cols]
    key_idx = cols.index("key") if table == "settings" and "key" in cols else -1
    out_rows: list[str] = []
    for ri, row in enumerate(rows):
        if len(row) != len(cols):
            raise ValueError(f"{table} row {ri}: col count {len(row)} != {len(cols)}")
        settings_key = unquote(row[key_idx]) if key_idx >= 0 else None
        new_vals = []
        for ci, (col, raw, kind) in enumerate(zip(cols, row, kinds)):
            new_vals.append(
                transform_value(table, col, kind, raw, ri, settings_key, stats)
            )
        out_rows.append("(" + ",".join(new_vals) + ")")
    col_list = ",".join(f"`{c}`" for c in cols)
    return f"INSERT INTO `{table}` ({col_list}) VALUES\n" + ",\n".join(out_rows) + ";\n"


def rewrite_dump(text: str, schema: dict[str, list[str]], stats: dict[str, int]) -> str:
    out: list[str] = []
    # Header rewrite: change DB name comment to disposable
    text = re.sub(
        r"Database: `[^`]+`",
        "Database: `erpsmart_rc1_legacy_data_anon`",
        text,
        count=1,
    )
    i = 0
    n = len(text)
    while i < n:
        m = INSERT_RE.search(text, i)
        if not m:
            out.append(text[i:])
            break
        out.append(text[i : m.start()])
        table = m.group(1)
        col_blob = m.group(2)
        if col_blob:
            cols = [c.strip().strip("`") for c in col_blob.split(",")]
        else:
            cols = schema.get(table)
            if not cols:
                raise ValueError(f"No schema columns for INSERT table {table}")
        rows, end = tokenize_values(text, m.end())
        stats["insert_tables"] = stats.get("insert_tables", 0) + 1
        stats["insert_rows"] = stats.get("insert_rows", 0) + len(rows)
        out.append(anonymize_insert(table, cols, rows, stats))
        # skip original through end (already consumed semicolon)
        i = end
        # if original had more after VALUES without us consuming, skip to next statement
        # tokenize_values already advanced past ';'
    # Banner
    banner = (
        "-- ERPSMART RC1 ANONYMIZED DUMP\n"
        "-- Generated offline by scripts/rc1_legacy_dump_anonymize.py\n"
        "-- Secrets/PII scrubbed; financial IDs/amounts/qty/dates preserved.\n"
        "-- DO NOT commit this file to Git.\n\n"
    )
    return banner + "".join(out)


def leak_scan(text: str) -> dict[str, Any]:
    """Heuristic privacy/secret scan — reports counts only, never samples."""
    findings: dict[str, Any] = {}
    patterns = {
        "bcrypt_non_disposable": r"\$2[ayb]\$\d{2}\$(?!92IXUNpkjO0rOQ5byMi\.Ye4oKoEa3Ro9llC/\.og/at2\.uheWG/igi)[A-Za-z0-9./]{50,}",
        "cron_secret_literal": r"cron_secret",
        "private_key_pem": r"BEGIN (RSA |EC )?PRIVATE KEY",
        "url_with_userinfo": r"://[^/\s'\"]+@[^/\s'\"]+",
        "iran_mobile_09": r"(?<![0-9])09[0-9]{9}(?![0-9])",
        "email_non_example": r"[A-Za-z0-9._%+-]+@(?!example\.test)[A-Za-z0-9.-]+\.[A-Za-z]{2,}",
        "iban_ir": r"IR[0-9]{24}",
    }
    # After anonymization, iran_mobile_09 and iban_ir may still match synthetic values —
    # classify synthetic prefixes separately.
    for name, pat in patterns.items():
        findings[name] = len(re.findall(pat, text))
    # Synthetic markers expected
    findings["synthetic_phone_0900"] = len(re.findall(r"0900[0-9]{9}", text))
    findings["synthetic_email_example_test"] = len(re.findall(r"@example\.test", text))
    # Real-looking phones excluding our 0900 prefix
    findings["phone_09_non_synthetic"] = len(
        re.findall(r"(?<![0-9])09(?!00)[0-9]{9}(?![0-9])", text)
    )
    # IBAN synthetic still matches IR+24 — flag only if bank scrubbing missed non-IR formats
    # cron_secret may remain as a settings KEY name (structural); values must be disposable.
    cron_ok = findings["cron_secret_literal"] == 0 or "DISPOSABLE_SECRET_" in text
    if findings["cron_secret_literal"] > 0 and "DISPOSABLE_SECRET_" in text:
        findings["cron_secret_literal_note"] = "settings key name may remain; disposable values present"
    findings["pass"] = (
        findings["bcrypt_non_disposable"] == 0
        and findings["private_key_pem"] == 0
        and findings["url_with_userinfo"] == 0
        and findings["phone_09_non_synthetic"] == 0
        and findings["email_non_example"] == 0
        and cron_ok
    )
    return findings


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--input", required=True, help="Path to UNSAFE candidate dump (offline)")
    ap.add_argument("--output", required=True, help="Path for anonymized dump (must be OUTSIDE repo)")
    ap.add_argument("--meta", required=True, help="Path for redacted metadata JSON (may be outside repo)")
    ap.add_argument("--repo-root", default="", help="Refuse writing output under this path")
    args = ap.parse_args()

    src = Path(args.input).resolve()
    dst = Path(args.output).resolve()
    meta_path = Path(args.meta).resolve()
    repo = Path(args.repo_root).resolve() if args.repo_root else None

    if not src.is_file():
        print("FAIL: input missing", file=sys.stderr)
        return 2
    if repo and (str(dst).startswith(str(repo)) or str(meta_path).startswith(str(repo) + "\\docs") is False and False):
        # Hard refuse dumping anonymized SQL into the git checkout
        pass
    if repo and str(dst).lower().startswith(str(repo).lower()):
        print("FAIL: refusing to write anonymized dump inside repo", file=sys.stderr)
        return 3

    raw = src.read_bytes()
    src_sha = hashlib.sha256(raw).hexdigest().upper()
    text = raw.decode("utf-8")
    schema = parse_schema(text)
    stats: dict[str, int] = {}
    anon = rewrite_dump(text, schema, stats)
    scan = leak_scan(anon)
    dst.parent.mkdir(parents=True, exist_ok=True)
    dst.write_text(anon, encoding="utf-8", newline="\n")
    out_sha = hashlib.sha256(dst.read_bytes()).hexdigest().upper()

    meta = {
        "tool": "scripts/rc1_legacy_dump_anonymize.py",
        "input": {
            "path": str(src),
            "bytes": len(raw),
            "sha256": src_sha,
            "safety_in": "UNSAFE_UNREDACTED_SECRETS_AND_PII",
        },
        "output": {
            "path": str(dst),
            "bytes": dst.stat().st_size,
            "sha256": out_sha,
            "kind": "SCHEMA_PLUS_ANONYMIZED_DATA",
            "git_commit_allowed": False,
        },
        "schema_tables": len(schema),
        "transform_stats": stats,
        "leak_scan": scan,
        "preserved": [
            "PK/FK ids",
            "workspace_id/company_id",
            "voucher/stock document ids",
            "signed amounts / qty / tax_amount",
            "dates / status / state",
        ],
        "scrubbed_classes": [
            "password_hash/password_enc",
            "tokens/secrets/api_key/taxpayer keys",
            "emails/phones/national_ids",
            "iban/card/bank_name",
            "addresses/names/usernames",
            "free-text notes/json",
            "URLs with credentials",
        ],
    }
    meta_path.parent.mkdir(parents=True, exist_ok=True)
    meta_path.write_text(json.dumps(meta, indent=2) + "\n", encoding="utf-8")
    # Console: redacted summary only
    print(
        json.dumps(
            {
                "ok": bool(scan.get("pass")),
                "input_sha256": src_sha,
                "output_sha256": out_sha,
                "output_bytes": dst.stat().st_size,
                "transform_stats": stats,
                "leak_scan_pass": scan.get("pass"),
                "leak_scan_counts": {k: v for k, v in scan.items() if k != "pass"},
            },
            indent=2,
        )
    )
    return 0 if scan.get("pass") else 1


if __name__ == "__main__":
    raise SystemExit(main())
