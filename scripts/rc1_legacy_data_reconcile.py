#!/usr/bin/env python3
"""Pre/post migration data reconciliation on disposable MySQL (anonymized dump).

Compares untouched baseline vs migrated clone for counts and financial aggregates.
Never prints PII/secrets — numeric aggregates and table names only.
"""
from __future__ import annotations

import argparse
import json
import os
import sys
from decimal import Decimal
from typing import Any

import pymysql


def connect(db: str):
    return pymysql.connect(
        host=os.environ["O2C_DB_HOST"],
        port=int(os.environ["O2C_DB_PORT"]),
        user=os.environ["O2C_DB_USER"],
        password=os.environ["O2C_DB_PASS"],
        database=db,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        connect_timeout=10,
    )


def q1(cur, sql: str) -> Any:
    cur.execute(sql)
    row = cur.fetchone()
    if not row:
        return None
    return next(iter(row.values()))


def table_exists(cur, db: str, table: str) -> bool:
    cur.execute(
        "SELECT COUNT(*) AS c FROM information_schema.tables "
        "WHERE table_schema=%s AND table_name=%s",
        (db, table),
    )
    return int(cur.fetchone()["c"]) == 1


def safe_count(cur, db: str, table: str) -> int | None:
    if not table_exists(cur, db, table):
        return None
    return int(q1(cur, f"SELECT COUNT(*) AS c FROM `{table}`"))


def safe_sum(cur, db: str, table: str, col: str) -> str | None:
    if not table_exists(cur, db, table):
        return None
    # verify column
    cur.execute(
        "SELECT COUNT(*) AS c FROM information_schema.columns "
        "WHERE table_schema=%s AND table_name=%s AND column_name=%s",
        (db, table, col),
    )
    if int(cur.fetchone()["c"]) == 0:
        return None
    v = q1(cur, f"SELECT COALESCE(SUM(`{col}`),0) AS s FROM `{table}`")
    if isinstance(v, Decimal):
        return format(v, "f")
    return str(v)


def snapshot(db: str) -> dict[str, Any]:
    conn = connect(db)
    try:
        with conn.cursor() as cur:
            tables = int(
                q1(
                    cur,
                    "SELECT COUNT(*) AS c FROM information_schema.tables "
                    f"WHERE table_schema={conn.escape(db)}",
                )
            )
            snap: dict[str, Any] = {
                "database": db,
                "table_count": tables,
                "counts": {},
                "sums": {},
                "integrity": {},
            }
            for t in [
                "users",
                "companies",
                "workspaces",
                "acc_fiscal_years",
                "acc_vouchers",
                "acc_voucher_lines",
                "acc_parties",
                "acc_cash_accounts",
                "acc_cash_transactions",
                "acc_checks",
                "acc_sales_docs",
                "acc_sales_lines",
                "acc_purchase_docs",
                "acc_purchase_lines",
                "acc_stock_movements",
                "acc_inventory_docs",
                "acc_inventory_lines",
                "acc_items",
                "acc_warehouses",
                "acc_accounts",
                "bank_reconciliations",
            ]:
                c = safe_count(cur, db, t)
                if c is not None:
                    snap["counts"][t] = c

            # Financial aggregates — signed amounts preserved
            for table, col in [
                ("acc_voucher_lines", "debit"),
                ("acc_voucher_lines", "credit"),
                ("acc_cash_transactions", "amount"),
                ("acc_stock_movements", "qty"),
                ("acc_sales_docs", "tax_total"),
                ("acc_purchase_docs", "tax_total"),
                ("acc_sales_lines", "tax_amount"),
                ("acc_payroll_lines", "tax_amount"),
            ]:
                s = safe_sum(cur, db, table, col)
                if s is not None:
                    snap["sums"][f"{table}.{col}"] = s

            # Balance equality on voucher lines
            if table_exists(cur, db, "acc_voucher_lines"):
                d = safe_sum(cur, db, "acc_voucher_lines", "debit")
                c = safe_sum(cur, db, "acc_voucher_lines", "credit")
                snap["integrity"]["voucher_lines_debit"] = d
                snap["integrity"]["voucher_lines_credit"] = c
                snap["integrity"]["voucher_lines_balanced"] = d == c

            # IRR/Toman heuristic: no silent ÷10 — compare raw sums only (caller compares pre/post)
            snap["integrity"]["currency_note"] = (
                "Raw decimal sums compared pre/post; no ÷10 Toman reinterpretation applied"
            )
            return snap
    finally:
        conn.close()


def compare(pre: dict[str, Any], post: dict[str, Any]) -> dict[str, Any]:
    mismatches: list[str] = []
    for k, v in pre.get("counts", {}).items():
        pv = post.get("counts", {}).get(k)
        if pv is None:
            mismatches.append(f"count_missing_post:{k}")
        elif pv != v:
            # Additive RC1 tables OK; legacy business tables must match
            mismatches.append(f"count_mismatch:{k}:pre={v}:post={pv}")
    for k, v in pre.get("sums", {}).items():
        pv = post.get("sums", {}).get(k)
        if pv is None:
            mismatches.append(f"sum_missing_post:{k}")
        elif pv != v:
            mismatches.append(f"sum_mismatch:{k}:pre={v}:post={pv}")

    pre_bal = pre.get("integrity", {}).get("voucher_lines_balanced")
    post_bal = post.get("integrity", {}).get("voucher_lines_balanced")
    if pre_bal is False:
        mismatches.append("pre_voucher_unbalanced")
    if post_bal is False:
        mismatches.append("post_voucher_unbalanced")

    # Table count may grow after migrate — require non-decreasing
    if post.get("table_count", 0) < pre.get("table_count", 0):
        mismatches.append("table_count_decreased")

    meaningful = (
        pre.get("counts", {}).get("acc_vouchers", 0) > 0
        or pre.get("counts", {}).get("acc_stock_movements", 0) > 0
        or pre.get("counts", {}).get("companies", 0) > 0
    )
    return {
        "ok": len(mismatches) == 0 and meaningful,
        "meaningful_data": meaningful,
        "mismatches": mismatches,
        "pre_table_count": pre.get("table_count"),
        "post_table_count": post.get("table_count"),
        "note": (
            None
            if meaningful
            else "INSUFFICIENT_DATA_FOR_MEANINGFUL_RECONCILIATION"
        ),
    }


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--baseline-db", required=True)
    ap.add_argument("--migrated-db", required=True)
    ap.add_argument("--out", required=True)
    args = ap.parse_args()
    for key in ("O2C_DB_HOST", "O2C_DB_PORT", "O2C_DB_USER", "O2C_DB_PASS"):
        if not os.environ.get(key):
            print(f"missing env {key}", file=sys.stderr)
            return 2
    for name in (args.baseline_db, args.migrated_db):
        low = name.lower()
        if any(x in low for x in ("prod", "production", "zzflgmfd", "cpanel", "live")):
            print(f"REFUSED prod-like db {name}", file=sys.stderr)
            return 3
    pre = snapshot(args.baseline_db)
    post = snapshot(args.migrated_db)
    cmp = compare(pre, post)
    payload = {
        "drill": "legacy_anonymized_data_reconciliation",
        "baseline": pre,
        "migrated": post,
        "compare": cmp,
        "host": os.environ["O2C_DB_HOST"],
        "port": int(os.environ["O2C_DB_PORT"]),
    }
    out = args.out
    # Allow writing evidence into repo docs; never write dumps
    with open(out, "w", encoding="utf-8") as f:
        json.dump(payload, f, indent=2)
        f.write("\n")
    print(
        json.dumps(
            {
                "ok": cmp["ok"],
                "meaningful_data": cmp["meaningful_data"],
                "mismatch_count": len(cmp["mismatches"]),
                "mismatches": cmp["mismatches"][:20],
                "pre_tables": pre["table_count"],
                "post_tables": post["table_count"],
                "counts_pre": pre["counts"],
                "sums_pre": pre["sums"],
            },
            indent=2,
        )
    )
    if not cmp["meaningful_data"]:
        return 4
    return 0 if cmp["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
