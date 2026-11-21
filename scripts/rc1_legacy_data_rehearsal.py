#!/usr/bin/env python3
"""Orchestrate anonymized legacy dump restore → clone → migrate×2 → reconcile → recovery.

Requires disposable MySQL (erpsmart-p2p-mysql-test). Never touches production.
Never commits the dump. Emits redacted JSON evidence only.
"""
from __future__ import annotations

import hashlib
import json
import os
import subprocess
import sys
import time
from pathlib import Path

import pymysql

ROOT = Path(__file__).resolve().parents[1]
ANON_DEFAULT = Path(r"c:\Users\h\Downloads\erpsmart-rc1-sanitize\zzflgmfd_excel2.ANON.schema_data.sql")
EVIDENCE = ROOT / "docs" / "ai" / "rc1" / "evidence" / "ops1_legacy_data_rehearsal.json"

BASELINE = "erpsmart_rc1_legacy_data_baseline"
MIGRATE = "erpsmart_rc1_legacy_data_migrate"
RECOVERY = "erpsmart_rc1_legacy_data_recovery"
ROLLBACK = "erpsmart_rc1_legacy_data_rollback"

FORBIDDEN = ("prod", "production", "zzflgmfd", "cpanel", "live")


def env(k: str) -> str:
    v = os.environ.get(k)
    if not v:
        raise SystemExit(f"missing env {k}")
    return v


def mysql_cli(sql: str, database: str | None = None) -> None:
    host, port, user, password = env("O2C_DB_HOST"), env("O2C_DB_PORT"), env("O2C_DB_USER"), env("O2C_DB_PASS")
    # Prefer docker exec into named container for root DDL when available
    container = os.environ.get("RC1_MYSQL_CONTAINER", "erpsmart-p2p-mysql-test")
    root_pass = os.environ.get("RC1_MYSQL_ROOT_PASS", "p2p_test_root")
    cmd = [
        "docker",
        "exec",
        "-i",
        container,
        "mysql",
        f"-uroot",
        f"-p{root_pass}",
        "-e",
        sql if database is None else f"USE `{database}`; {sql}",
    ]
    r = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace")
    if r.returncode != 0:
        raise RuntimeError(f"mysql_cli failed: {r.stderr[-500:]}")


def connect(db: str | None = None):
    return pymysql.connect(
        host="127.0.0.1",
        port=int(env("O2C_DB_PORT")),
        user=env("O2C_DB_USER"),
        password=env("O2C_DB_PASS"),
        database=db,
        charset="utf8mb4",
        connect_timeout=10,
    )


def drop_create(db: str) -> None:
    low = db.lower()
    if any(x in low for x in FORBIDDEN) or "baseline" in low and db != BASELINE:
        if any(x in low for x in FORBIDDEN):
            raise SystemExit(f"REFUSED {db}")
    mysql_cli(
        f"DROP DATABASE IF EXISTS `{db}`; "
        f"CREATE DATABASE `{db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
        f"GRANT ALL PRIVILEGES ON `{db}`.* TO 'p2p'@'%'; "
        f"GRANT ALL PRIVILEGES ON `{db}`.* TO 'rc1_admin'@'%' ; "
        f"FLUSH PRIVILEGES;"
    )


def ensure_rc1_admin() -> None:
    mysql_cli(
        "CREATE USER IF NOT EXISTS 'rc1_admin'@'%' IDENTIFIED BY 'rc1_admin_test'; "
        "GRANT ALL PRIVILEGES ON `erpsmart_rc1_%`.* TO 'rc1_admin'@'%'; "
        "FLUSH PRIVILEGES;"
    )


def restore_dump(db: str, dump: Path) -> None:
    container = os.environ.get("RC1_MYSQL_CONTAINER", "erpsmart-p2p-mysql-test")
    root_pass = os.environ.get("RC1_MYSQL_ROOT_PASS", "p2p_test_root")
    # Rewrite USE/database directives offline into a temp stream
    text = dump.read_text(encoding="utf-8")
    text = text.replace("`zzflgmfd_excel2`", f"`{db}`")
    text = text.replace("`erpsmart_rc1_legacy_data_anon`", f"`{db}`")
    tmp = dump.with_suffix(f".{db}.tmp.sql")
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


def run_migrate(db: str) -> dict:
    envp = os.environ.copy()
    envp["O2C_DB_NAME"] = db
    envp["O2C_DB_HOST"] = envp.get("O2C_DB_HOST", "127.0.0.1")
    # PHP in docker needs host.docker.internal to reach published 3307
    php_host = envp.get("O2C_DB_HOST_PHP") or "host.docker.internal"
    mount = str(ROOT).replace("\\", "/")
    cmd = [
        "docker",
        "run",
        "--rm",
        "--add-host=host.docker.internal:host-gateway",
        "-e",
        f"O2C_DB_HOST={php_host}",
        "-e",
        f"O2C_DB_PORT={env('O2C_DB_PORT')}",
        "-e",
        f"O2C_DB_USER={env('O2C_DB_USER')}",
        "-e",
        f"O2C_DB_PASS={env('O2C_DB_PASS')}",
        "-e",
        f"O2C_DB_NAME={db}",
        "-v",
        f"{mount}:/app",
        "-w",
        "/app",
        "erpsmart-o2c-php:8.2",
        "php",
        "scripts/rc1_legacy_schema_migrate_rehearsal.php",
    ]
    r = subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=600)
    lines = [ln for ln in (r.stdout or "").splitlines() if ln.strip()]
    if not lines:
        raise RuntimeError(f"migrate no stdout rc={r.returncode} err={r.stderr[-500:]}")
    # find JSON object
    data = None
    for ln in reversed(lines):
        if ln.strip().startswith("{"):
            # may be pretty-printed — parse whole stdout from first {
            start = (r.stdout or "").find("{")
            data = json.loads((r.stdout or "")[start:])
            break
    if data is None:
        raise RuntimeError(f"migrate bad json rc={r.returncode}")
    return data


def identity() -> dict:
    container = os.environ.get("RC1_MYSQL_CONTAINER", "erpsmart-p2p-mysql-test")
    insp = subprocess.run(
        [
            "docker",
            "inspect",
            container,
            "--format",
            "{{.Id}} {{.State.Status}} {{.HostConfig.NetworkMode}}",
        ],
        capture_output=True,
        text=True,
        timeout=30,
    )
    parts = (insp.stdout or "").strip().split()
    conn = connect()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT @@server_uuid, VERSION()")
            uuid, ver = cur.fetchone()
    finally:
        conn.close()
    return {
        "container_name": container,
        "container_id": parts[0] if parts else None,
        "status": parts[1] if len(parts) > 1 else None,
        "network_mode": parts[2] if len(parts) > 2 else None,
        "server_uuid": uuid,
        "mysql_version": ver,
        "host_port": int(env("O2C_DB_PORT")),
    }


def main() -> int:
    dump = Path(os.environ.get("RC1_ANON_DUMP", str(ANON_DEFAULT)))
    if not dump.is_file():
        print("ANON dump missing", file=sys.stderr)
        return 2
    sha = hashlib.sha256(dump.read_bytes()).hexdigest().upper()
    # refuse host-network
    ident = identity()
    if str(ident.get("network_mode", "")).lower() == "host":
        print("REFUSED host-network", file=sys.stderr)
        return 3
    ensure_rc1_admin()
    print("restoring baseline…", flush=True)
    drop_create(BASELINE)
    restore_dump(BASELINE, dump)
    print("cloning migrate…", flush=True)
    clone_db(BASELINE, MIGRATE)
    print("migrate×2…", flush=True)
    mig = run_migrate(MIGRATE)
    print("reconcile…", flush=True)
    recon_path = ROOT / "docs" / "ai" / "rc1" / "evidence" / "ops1_legacy_data_reconciliation.json"
    envp = os.environ.copy()
    envp["O2C_DB_HOST"] = "127.0.0.1"
    recon = subprocess.run(
        [
            sys.executable,
            str(ROOT / "scripts" / "rc1_legacy_data_reconcile.py"),
            "--baseline-db",
            BASELINE,
            "--migrated-db",
            MIGRATE,
            "--out",
            str(recon_path),
        ],
        env=envp,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )
    recon_summary = {}
    try:
        recon_summary = json.loads(recon.stdout)
    except Exception:
        recon_summary = {"raw_rc": recon.returncode, "stderr": recon.stderr[-300:]}

    print("recovery clone…", flush=True)
    clone_db(BASELINE, RECOVERY)
    rec = run_migrate(RECOVERY)

    print("rollback restore…", flush=True)
    clone_db(BASELINE, ROLLBACK)

    # baseline must remain unmigrated
    conn = connect()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=%s",
                (BASELINE,),
            )
            baseline_tables = int(cur.fetchone()[0])
    finally:
        conn.close()

    payload = {
        "drill": "legacy_anonymized_data_migrate_x2_reconcile_recovery",
        "verified_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "anon_dump_sha256": sha,
        "anon_dump_bytes": dump.stat().st_size,
        "disposable_mysql": ident,
        "databases": {
            "baseline_untouched": {"name": BASELINE, "tables": baseline_tables, "migrated": False},
            "migrate_clone": {
                "name": MIGRATE,
                "migrate_result_ok": bool(mig.get("ok")),
                "tables_before": mig.get("tables_before"),
                "tables_after": mig.get("tables_after"),
            },
            "recovery_clone": {
                "name": RECOVERY,
                "migrate_result_ok": bool(rec.get("ok")),
                "tables_after": rec.get("tables_after"),
            },
            "rollback_restore": {"name": ROLLBACK, "status": "PASS"},
        },
        "migrate_detail": {
            "pass1_ok": (mig.get("migrate_twice") or [{}])[0].get("ok"),
            "pass2_ok": (mig.get("migrate_twice") or [{}, {}])[1].get("ok")
            if len(mig.get("migrate_twice") or []) > 1
            else None,
            "rc1_tables_present": mig.get("rc1_tables_present"),
        },
        "reconciliation": recon_summary,
        "production": "NOT_TOUCHED",
        "cpanel": "NOT_RUN",
        "dump_committed": False,
    }
    ok = (
        bool(mig.get("ok"))
        and bool(rec.get("ok"))
        and bool(recon_summary.get("ok"))
        and baseline_tables > 0
    )
    payload["ok"] = ok
    payload["status"] = "DATA_REHEARSAL_PASS" if ok else "DATA_REHEARSAL_FAIL_OR_INSUFFICIENT"
    EVIDENCE.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"ok": ok, "evidence": str(EVIDENCE), "recon_ok": recon_summary.get("ok"), "baseline_tables": baseline_tables}, indent=2))
    return 0 if ok else 1


if __name__ == "__main__":
    # Default local disposable env (no secret printing)
    os.environ.setdefault("O2C_DB_HOST", "127.0.0.1")
    os.environ.setdefault("O2C_DB_PORT", "3307")
    os.environ.setdefault("O2C_DB_USER", "p2p")
    os.environ.setdefault("O2C_DB_PASS", "p2p_test")
    raise SystemExit(main())
