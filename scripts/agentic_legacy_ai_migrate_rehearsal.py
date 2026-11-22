#!/usr/bin/env python3
"""Real anonymized legacy dump → disposable MySQL → cumulative migrate incl. AiSchema 1.2.0.

Never touches production. Never commits the dump. Emits redacted JSON evidence only.
"""
from __future__ import annotations

import hashlib
import json
import os
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ANON_DEFAULT = Path(r"c:\Users\h\Downloads\erpsmart-rc1-sanitize\zzflgmfd_excel2.ANON.schema_data.sql")
EXPECTED_ANON_SHA256 = "F3B92C0088A874A739FCD64BF25FA714010078C3EB95DFDF81ADC92335841DE2"
EVIDENCE = ROOT / "docs" / "ai" / "agentic-next" / "evidence" / "agentic_legacy_ai_migrate_rehearsal.json"
CACHE_EVIDENCE = ROOT / "storage" / "cache" / "agentic_prod_release" / "agentic_legacy_ai_migrate_rehearsal.json"

BASELINE = "erpsmart_agentic_legacy_ai_baseline"
MIGRATE = "erpsmart_agentic_legacy_ai_migrate"
RECOVERY = "erpsmart_agentic_legacy_ai_recovery"
ROLLBACK = "erpsmart_agentic_legacy_ai_rollback"

FORBIDDEN = ("prod", "production", "zzflgmfd", "cpanel", "live")

ERP_COUNT_TABLES = [
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
]

AI_COUNT_TABLES = [
    "ai_jobs",
    "ai_conversations",
    "ai_action_proposals",
    "ai_suggestions",
    "ai_worker_tokens",
    "ai_worker_nodes",
    "ai_feedback",
    "ai_semantic_routes",
    "ai_rag_sources",
]


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest().upper()


def env(k: str, default: str | None = None) -> str:
    v = os.environ.get(k, default)
    if not v:
        raise SystemExit(f"missing env {k}")
    return v


def mysql_cli(sql: str) -> None:
    container = os.environ.get("RC1_MYSQL_CONTAINER", "erpsmart-p2p-mysql-test")
    root_pass = os.environ.get("RC1_MYSQL_ROOT_PASS", "p2p_test_root")
    cmd = ["docker", "exec", "-i", container, "mysql", "-uroot", f"-p{root_pass}", "-e", sql]
    r = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace")
    if r.returncode != 0:
        raise RuntimeError(f"mysql_cli failed: {r.stderr[-800:]}")


def connect(db: str | None = None):
    import pymysql

    return pymysql.connect(
        host="127.0.0.1",
        port=int(env("O2C_DB_PORT", "3307")),
        user=env("O2C_DB_USER", "p2p"),
        password=env("O2C_DB_PASS", "p2p_test"),
        database=db,
        charset="utf8mb4",
        connect_timeout=15,
    )


def assert_disposable_name(db: str) -> None:
    low = db.lower()
    if any(x in low for x in FORBIDDEN):
        raise SystemExit(f"REFUSED unsafe db name {db}")
    if not low.startswith("erpsmart_agentic_legacy_ai_"):
        raise SystemExit(f"REFUSED db not on agentic AI allowlist {db}")


def drop_create(db: str) -> None:
    assert_disposable_name(db)
    mysql_cli(
        f"DROP DATABASE IF EXISTS `{db}`; "
        f"CREATE DATABASE `{db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
        f"GRANT ALL PRIVILEGES ON `{db}`.* TO 'p2p'@'%'; "
        f"FLUSH PRIVILEGES;"
    )


def restore_dump(db: str, dump: Path) -> None:
    assert_disposable_name(db)
    container = os.environ.get("RC1_MYSQL_CONTAINER", "erpsmart-p2p-mysql-test")
    root_pass = os.environ.get("RC1_MYSQL_ROOT_PASS", "p2p_test_root")
    text = dump.read_text(encoding="utf-8")
    text = text.replace("`zzflgmfd_excel2`", f"`{db}`")
    text = text.replace("`erpsmart_rc1_legacy_data_anon`", f"`{db}`")
    tmp = ROOT / "storage" / "cache" / "agentic_prod_release" / f"_tmp_{db}.sql"
    tmp.parent.mkdir(parents=True, exist_ok=True)
    tmp.write_text(f"USE `{db}`;\n" + text, encoding="utf-8")
    try:
        with tmp.open("rb") as fh:
            r = subprocess.run(
                [
                    "docker",
                    "exec",
                    "-i",
                    container,
                    "mysql",
                    "-uroot",
                    f"-p{root_pass}",
                    "--default-character-set=utf8mb4",
                    db,
                ],
                stdin=fh,
                capture_output=True,
            )
        if r.returncode != 0:
            raise RuntimeError(r.stderr[-800:].decode("utf-8", "replace"))
    finally:
        tmp.unlink(missing_ok=True)


def clone_db(src: str, dst: str) -> None:
    drop_create(dst)
    container = os.environ.get("RC1_MYSQL_CONTAINER", "erpsmart-p2p-mysql-test")
    root_pass = os.environ.get("RC1_MYSQL_ROOT_PASS", "p2p_test_root")
    dump = subprocess.run(
        [
            "docker",
            "exec",
            container,
            "mysqldump",
            "-uroot",
            f"-p{root_pass}",
            "--single-transaction",
            "--routines=false",
            "--triggers=false",
            src,
        ],
        capture_output=True,
    )
    if dump.returncode != 0:
        raise RuntimeError(dump.stderr[-500:].decode("utf-8", "replace"))
    load = subprocess.run(
        [
            "docker",
            "exec",
            "-i",
            container,
            "mysql",
            "-uroot",
            f"-p{root_pass}",
            "--default-character-set=utf8mb4",
            dst,
        ],
        input=dump.stdout,
        capture_output=True,
    )
    if load.returncode != 0:
        raise RuntimeError(load.stderr[-500:].decode("utf-8", "replace"))


def table_counts(db: str, tables: list[str]) -> dict[str, int]:
    conn = connect(db)
    out: dict[str, int] = {}
    try:
        with conn.cursor() as cur:
            for t in tables:
                cur.execute(
                    "SELECT COUNT(*) FROM information_schema.tables "
                    "WHERE table_schema=%s AND table_name=%s",
                    (db, t),
                )
                if int(cur.fetchone()[0]) == 0:
                    out[t] = -1  # absent
                    continue
                cur.execute(f"SELECT COUNT(*) FROM `{t}`")
                out[t] = int(cur.fetchone()[0])
    finally:
        conn.close()
    return out


def table_total(db: str) -> int:
    conn = connect(db)
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=%s AND table_type='BASE TABLE'",
                (db,),
            )
            return int(cur.fetchone()[0])
    finally:
        conn.close()


def column_exists(db: str, table: str, column: str) -> bool:
    conn = connect(db)
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT COUNT(*) FROM information_schema.columns "
                "WHERE table_schema=%s AND table_name=%s AND column_name=%s",
                (db, table, column),
            )
            return int(cur.fetchone()[0]) == 1
    finally:
        conn.close()


def setting_get(db: str, key: str) -> str | None:
    """Read ai_schema_version across legacy (`key`/`value`) and current schemas."""
    conn = connect(db)
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT COUNT(*) FROM information_schema.tables "
                "WHERE table_schema=%s AND table_name='settings'",
                (db,),
            )
            if int(cur.fetchone()[0]) == 0:
                return None
            cur.execute(
                "SELECT column_name FROM information_schema.columns "
                "WHERE table_schema=%s AND table_name='settings'",
                (db,),
            )
            cols = {str(r[0]).lower() for r in cur.fetchall()}
            if "setting_key" in cols and "setting_value" in cols:
                if "workspace_id" in cols:
                    cur.execute(
                        "SELECT setting_value FROM settings "
                        "WHERE setting_key=%s AND workspace_id=0 LIMIT 1",
                        (key,),
                    )
                else:
                    cur.execute(
                        "SELECT setting_value FROM settings WHERE setting_key=%s LIMIT 1",
                        (key,),
                    )
            elif "key" in cols and "value" in cols:
                cur.execute("SELECT value FROM settings WHERE `key`=%s LIMIT 1", (key,))
            else:
                return None
            row = cur.fetchone()
            return None if row is None else str(row[0])
    finally:
        conn.close()


def list_ai_tables(db: str) -> list[str]:
    conn = connect(db)
    try:
        with conn.cursor() as cur:
            # Use literal escape; avoid pymysql % formatting on LIKE patterns.
            cur.execute(
                "SELECT table_name FROM information_schema.tables "
                "WHERE table_schema=%s AND table_name LIKE %s "
                "ORDER BY table_name",
                (db, "ai\\_%"),
            )
            return [str(r[0]) for r in cur.fetchall()]
    finally:
        conn.close()


def permission_keys(db: str) -> list[str]:
    conn = connect(db)
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT COUNT(*) FROM information_schema.tables "
                "WHERE table_schema=%s AND table_name='workspace_permissions'",
                (db,),
            )
            if int(cur.fetchone()[0]) == 0:
                return []
            cur.execute(
                "SELECT permission_key FROM workspace_permissions "
                "WHERE permission_key LIKE 'ai.%' ORDER BY permission_key"
            )
            return [str(r[0]) for r in cur.fetchall()]
    finally:
        conn.close()


def run_migrate(db: str) -> dict:
    # Extend allowlist via RC1_ALLOW_ANY_MYSQL for disposable agentic DBs; PHP script
    # still refuses prod-like names. We use a thin PHP wrapper that only allows our DBs.
    php_host = os.environ.get("O2C_DB_HOST_PHP") or "host.docker.internal"
    mount = str(ROOT).replace("\\", "/")
    cmd = [
        "docker",
        "run",
        "--rm",
        "--add-host=host.docker.internal:host-gateway",
        "-e",
        f"O2C_DB_HOST={php_host}",
        "-e",
        f"O2C_DB_PORT={env('O2C_DB_PORT', '3307')}",
        "-e",
        f"O2C_DB_USER={env('O2C_DB_USER', 'p2p')}",
        "-e",
        f"O2C_DB_PASS={env('O2C_DB_PASS', 'p2p_test')}",
        "-e",
        f"O2C_DB_NAME={db}",
        "-e",
        "RC1_ALLOW_ANY_MYSQL=1",
        "-e",
        "AGENTIC_AI_MIGRATE_ALLOW=1",
        "-v",
        f"{mount}:/app",
        "-w",
        "/app",
        "erpsmart-o2c-php:8.2",
        "php",
        "scripts/agentic_legacy_ai_migrate_runner.php",
    ]
    r = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=900)
    lines = (r.stdout or "").strip().splitlines()
    payload = {}
    if lines:
        try:
            payload = json.loads(lines[-1])
        except json.JSONDecodeError:
            payload = {"ok": False, "raw": lines[-1][:500]}
    if r.returncode != 0:
        payload["ok"] = False
        payload["stderr"] = (r.stderr or "")[-800:]
        payload["returncode"] = r.returncode
    return payload


def reconcile(pre: dict[str, int], post: dict[str, int]) -> dict:
    mismatches = []
    for k, v in pre.items():
        if v < 0:
            continue
        pv = post.get(k, -999)
        if pv != v:
            mismatches.append({"table": k, "before": v, "after": pv})
    return {
        "ok": mismatches == [],
        "mismatch_count": len(mismatches),
        "mismatches": mismatches,
        "counts_pre": pre,
        "counts_post": {k: post.get(k) for k in pre},
    }


def mysql_identity() -> dict:
    container = os.environ.get("RC1_MYSQL_CONTAINER", "erpsmart-p2p-mysql-test")
    insp = subprocess.run(
        ["docker", "inspect", "-f", "{{.Id}} {{.State.Status}} {{.HostConfig.NetworkMode}}", container],
        capture_output=True,
        text=True,
    )
    root_pass = os.environ.get("RC1_MYSQL_ROOT_PASS", "p2p_test_root")
    q = subprocess.run(
        [
            "docker",
            "exec",
            container,
            "mysql",
            "-uroot",
            f"-p{root_pass}",
            "-N",
            "-e",
            "SELECT @@server_uuid, @@version;",
        ],
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )
    uuid, ver = "", ""
    if q.returncode == 0 and q.stdout.strip():
        parts = q.stdout.strip().split()
        uuid = parts[0] if parts else ""
        ver = parts[1] if len(parts) > 1 else ""
    return {
        "container_name": container,
        "inspect": (insp.stdout or "").strip(),
        "server_uuid": uuid,
        "mysql_version": ver,
        "host_port": int(env("O2C_DB_PORT", "3307")),
    }


def main() -> int:
    dump = Path(os.environ.get("AGENTIC_ANON_DUMP", str(ANON_DEFAULT)))
    if not dump.is_file():
        print(json.dumps({"ok": False, "error": "anon_dump_missing", "path": str(dump)}))
        return 2
    digest = sha256_file(dump)
    if digest != EXPECTED_ANON_SHA256:
        print(
            json.dumps(
                {
                    "ok": False,
                    "error": "anon_sha_mismatch",
                    "got": digest,
                    "expected": EXPECTED_ANON_SHA256,
                }
            )
        )
        return 2

    os.environ.setdefault("O2C_DB_PORT", "3307")
    os.environ.setdefault("O2C_DB_USER", "p2p")
    os.environ.setdefault("O2C_DB_PASS", "p2p_test")

    identity = mysql_identity()
    t0 = time.time()

    drop_create(BASELINE)
    restore_dump(BASELINE, dump)
    pre_tables = table_total(BASELINE)
    pre_erp = table_counts(BASELINE, ERP_COUNT_TABLES)
    pre_ai = table_counts(BASELINE, AI_COUNT_TABLES)
    pre_ai_tables = list_ai_tables(BASELINE)
    pre_version = setting_get(BASELINE, "ai_schema_version")

    clone_db(BASELINE, MIGRATE)
    m1 = run_migrate(MIGRATE)
    m2 = run_migrate(MIGRATE)
    post_tables = table_total(MIGRATE)
    post_erp = table_counts(MIGRATE, ERP_COUNT_TABLES)
    post_ai = table_counts(MIGRATE, AI_COUNT_TABLES)
    post_ai_tables = list_ai_tables(MIGRATE)
    post_version = setting_get(MIGRATE, "ai_schema_version")
    has_rag_docs = "ai_rag_documents" in post_ai_tables
    has_rag_acl = "ai_rag_document_acl" in post_ai_tables
    has_idem = column_exists(MIGRATE, "ai_action_proposals", "idempotency_key")
    has_dedupe = column_exists(MIGRATE, "ai_suggestions", "dedupe_key")
    perms = permission_keys(MIGRATE)

    erp_recon = reconcile(pre_erp, post_erp)
    ai_recon = reconcile(pre_ai, post_ai)

    # Recovery clone: restore baseline dump again and migrate once.
    clone_db(BASELINE, RECOVERY)
    m_rec = run_migrate(RECOVERY)

    # Rollback restore: clone pre-migrate baseline into rollback DB (dump restore path).
    clone_db(BASELINE, ROLLBACK)
    rollback_tables = table_total(ROLLBACK)
    rollback_jobs = table_counts(ROLLBACK, ["ai_jobs"]).get("ai_jobs", -1)

    unexpected_ai_deletes = [
        t for t in pre_ai_tables if t not in post_ai_tables
    ]

    ok = (
        bool(m1.get("ok"))
        and bool(m2.get("ok"))
        and bool(m_rec.get("ok"))
        and erp_recon["ok"]
        and ai_recon["ok"]
        and post_version == "1.2.0"
        and has_rag_docs
        and has_rag_acl
        and has_idem
        and has_dedupe
        and unexpected_ai_deletes == []
        and "ai.use" in perms
        and "ai.rag.retrieve" in perms
        and rollback_tables == pre_tables
        and rollback_jobs == pre_ai.get("ai_jobs", -1)
    )

    evidence = {
        "ok": ok,
        "drill": "agentic_legacy_anonymized_ai_migrate_x2_recon_recovery",
        "verified_at_utc": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "production": "NOT_TOUCHED",
        "prod_migrate": "NOT_RUN",
        "provenance_id": "prior-cpanel-anon-20261121",
        "anon_dump_path_offline": str(dump),
        "anon_dump_sha256": digest,
        "anon_dump_bytes": dump.stat().st_size,
        "git_commit_dump": False,
        "disposable_mysql": identity,
        "databases": {
            "baseline_untouched": {"name": BASELINE, "tables": pre_tables, "migrated": False},
            "migrate_clone": {
                "name": MIGRATE,
                "tables_before": pre_tables,
                "tables_after": post_tables,
                "migrate_pass1": m1,
                "migrate_pass2": m2,
            },
            "recovery_clone": {"name": RECOVERY, "migrate": m_rec, "tables": table_total(RECOVERY)},
            "rollback_restore": {
                "name": ROLLBACK,
                "tables": rollback_tables,
                "ai_jobs": rollback_jobs,
                "status": "PASS" if rollback_tables == pre_tables else "FAIL",
            },
        },
        "ai_schema": {
            "version_before": pre_version,
            "version_after": post_version,
            "tables_before": pre_ai_tables,
            "tables_after": post_ai_tables,
            "has_rag_documents": has_rag_docs,
            "has_rag_acl": has_rag_acl,
            "has_idempotency_key": has_idem,
            "has_dedupe_key": has_dedupe,
            "permissions": perms,
            "agent_task_schema": "erpsmart.agent_task.v1",
            "unexpected_ai_table_deletes": unexpected_ai_deletes,
        },
        "reconciliation": {
            "erp": erp_recon,
            "ai": ai_recon,
        },
        "elapsed_sec": round(time.time() - t0, 2),
    }

    EVIDENCE.parent.mkdir(parents=True, exist_ok=True)
    EVIDENCE.write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
    CACHE_EVIDENCE.parent.mkdir(parents=True, exist_ok=True)
    CACHE_EVIDENCE.write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"ok": ok, "evidence": str(EVIDENCE), "ai_schema_version": post_version}, indent=2))
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
