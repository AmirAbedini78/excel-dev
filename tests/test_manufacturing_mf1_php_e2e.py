"""MF-1 manufacturing E2E against disposable MySQL (Docker PHP + erpsmart-p2p-mysql-test)."""
from __future__ import annotations

import json
import os
import pathlib
import subprocess
import threading
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
HOST = os.environ.get("O2C_DB_HOST", "host.docker.internal")
PORT = os.environ.get("O2C_DB_PORT", "3307")
USER = os.environ.get("O2C_DB_USER", "p2p")
PASSWORD = os.environ.get("O2C_DB_PASS", "p2p_test")
DATABASE = os.environ.get("O2C_DB_NAME", "erpsmart_mf1_test")


def _docker_ok() -> bool:
    try:
        r = subprocess.run(
            ["docker", "image", "inspect", "erpsmart-o2c-php:8.2"],
            capture_output=True,
            text=True,
            timeout=20,
        )
        return r.returncode == 0
    except Exception:
        return False


def _php(scenario: str, args: dict | None = None, timeout: int = 180) -> dict:
    if not _docker_ok():
        raise unittest.SkipTest("erpsmart-o2c-php:8.2 unavailable")
    cmd = [
        "docker",
        "run",
        "--rm",
        "--add-host=host.docker.internal:host-gateway",
        "-e",
        f"O2C_DB_HOST={HOST}",
        "-e",
        f"O2C_DB_PORT={PORT}",
        "-e",
        f"O2C_DB_USER={USER}",
        "-e",
        f"O2C_DB_PASS={PASSWORD}",
        "-e",
        f"O2C_DB_NAME={DATABASE}",
        "-v",
        f"{ROOT}:/app",
        "-w",
        "/app",
        "erpsmart-o2c-php:8.2",
        "php",
        "tests/harness/mf_e2e_runner.php",
        scenario,
        json.dumps(args or {}, ensure_ascii=False),
    ]
    completed = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    out = (completed.stdout or "").strip().splitlines()
    if not out:
        raise AssertionError(f"no_stdout rc={completed.returncode} stderr={completed.stderr}")
    try:
        return json.loads(out[-1])
    except json.JSONDecodeError as exc:
        raise AssertionError(f"bad_json={out[-1]!r} stderr={completed.stderr}") from exc


def _mysql_container_ok() -> bool:
    try:
        r = subprocess.run(
            ["docker", "inspect", "-f", "{{.Id}} {{.Name}}", "erpsmart-p2p-mysql-test"],
            capture_output=True,
            text=True,
            timeout=20,
        )
        return r.returncode == 0 and "erpsmart-p2p-mysql-test" in (r.stdout or "")
    except Exception:
        return False


class ManufacturingMf1PhpE2e(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not _docker_ok():
            raise unittest.SkipTest("erpsmart-o2c-php:8.2 unavailable")
        if not _mysql_container_ok():
            raise unittest.SkipTest(
                "disposable MySQL container erpsmart-p2p-mysql-test unavailable "
                "(CI commercial MVP gate is source-only; use rc1_acceptance_gate for PHP+MySQL)"
            )

    def test_00_mysql_identity(self):
        r = subprocess.run(
            ["docker", "inspect", "-f", "{{.Id}} {{.Name}}", "erpsmart-p2p-mysql-test"],
            capture_output=True,
            text=True,
            timeout=20,
        )
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertIn("erpsmart-p2p-mysql-test", r.stdout)
        self.assertTrue(r.stdout.strip().startswith("02eb215a87f6") or "02eb215a87f6" in r.stdout.replace("-", ""))

    def test_01_lifecycle_happy_path(self):
        res = _php("lifecycle_happy")
        self.assertTrue(res.get("ok"), res)
        self.assertTrue(res.get("DATABASE_TESTED"))
        self.assertTrue(res.get("issue_replay"))
        self.assertTrue(res.get("fg_replay"))
        self.assertTrue(res.get("fg_reject_blocked"))
        self.assertEqual(res.get("received_fg"), "2.0000")
        self.assertEqual(res.get("accepted_qc"), "2.0000")
        self.assertEqual(res.get("rejected_qc"), "1.0000")
        self.assertEqual(res.get("scrap_qty"), "0.2500")
        self.assertTrue(res.get("status_closed"))
        self.assertEqual(res.get("issue_movements"), 2)  # two components, one partial issue batch
        self.assertEqual(res.get("fg_movements"), 2)  # two partial FG receipts (replay does not add)
        self.assertEqual(res.get("gl_posting"), "BLOCKED")
        self.assertIn(res.get("cost_class"), ("PROVISIONAL", "UNVALUED", "BLOCKED"))
        # RAW-A started 10, issued 2 → 8
        self.assertEqual(res.get("raw_a_on_hand"), "8.0000")
        self.assertEqual(res.get("fg_on_hand"), "2.0000")

    def test_02_insufficient_stock_rejected(self):
        res = _php("insufficient_stock")
        self.assertTrue(res.get("ok"), res)
        self.assertTrue(res.get("rejected"))

    def test_03_injected_rollback(self):
        res = _php("injected_rollback")
        self.assertTrue(res.get("ok"), res)
        self.assertTrue(res.get("injected_failed"))
        self.assertTrue(res.get("issues_unchanged"))
        self.assertTrue(res.get("moves_unchanged"))
        self.assertTrue(res.get("on_hand_unchanged"))

    def test_04_maker_checker_and_isolation(self):
        reset = _php("reset")
        self.assertTrue(reset.get("ok"))
        _php("seed_stock", {"item_id": 2, "qty": "20.0000", "unit_cost": "100.00"})
        _php("seed_stock", {"item_id": 3, "qty": "20.0000", "unit_cost": "50.00"})
        same = _php("approve_bom_same_user")
        self.assertTrue(same.get("rejected"))
        bom = _php("create_bom_approved")
        self.assertTrue(bom.get("ok"))
        bad = _php("malformed_qty", {"bom_id": bom["bom_id"]})
        self.assertTrue(bad.get("rejected"))
        order = _php("create_order", {"bom_id": bom["bom_id"], "planned_qty": "2.0000", "user_id": 1})
        oid = int(order["order"]["id"])
        denied = _php("approve_order", {"order_id": oid, "user_id": 1})
        self.assertFalse(denied.get("ok"), denied)
        self.assertIn("maker_checker", (denied.get("error") or "").lower())
        ok = _php("approve_order", {"order_id": oid, "user_id": 2})
        self.assertTrue(ok.get("ok"), ok)
        iso = _php("company_isolation", {"order_id": oid})
        self.assertTrue(iso.get("hidden"))

    def test_05_duplicate_component_and_closed_period(self):
        reset = _php("reset")
        self.assertTrue(reset.get("ok"))
        _php("seed_stock", {"item_id": 2, "qty": "10.0000", "unit_cost": "100.00"})
        _php("seed_stock", {"item_id": 3, "qty": "10.0000", "unit_cost": "50.00"})
        bom = _php("create_bom_approved")["bom_id"]
        oid = int(_php("create_order", {"bom_id": bom, "planned_qty": "2.0000"})["order"]["id"])
        _php("approve_order", {"order_id": oid, "user_id": 2})
        _php("release_order", {"order_id": oid, "idempotency_key": "a" * 32})
        dup = _php("duplicate_component_issue", {"order_id": oid})
        self.assertTrue(dup.get("rejected"), dup)
        closed = _php("close_period_issue", {"order_id": oid})
        self.assertTrue(closed.get("rejected"), closed)
        self.assertIn("period", (closed.get("error") or "").lower())

    def test_06_concurrent_issue_two_orders(self):
        prep = _php("concurrent_issue_prepare")
        self.assertTrue(prep.get("ok"), prep)
        o1, o2 = int(prep["order1"]), int(prep["order2"])
        lines1, lines2 = prep["lines1"], prep["lines2"]
        results: list[dict] = []

        def run(oid: int, lines: list, key: str):
            results.append(
                _php(
                    "concurrent_issue_one",
                    {"order_id": oid, "lines": lines, "idempotency_key": key},
                    timeout=180,
                )
            )

        t1 = threading.Thread(target=run, args=(o1, lines1, "b" * 32))
        t2 = threading.Thread(target=run, args=(o2, lines2, "c" * 32))
        t1.start()
        t2.start()
        t1.join(timeout=200)
        t2.join(timeout=200)
        self.assertEqual(len(results), 2, results)
        oks = [r for r in results if r.get("ok")]
        fails = [r for r in results if not r.get("ok")]
        self.assertEqual(len(oks), 1, results)
        self.assertEqual(len(fails), 1, results)
        self.assertIn("insufficient", (fails[0].get("error") or "").lower())

    def test_07_mrp_and_adapter_contract(self):
        reset = _php("reset")
        self.assertTrue(reset.get("ok"))
        _php("seed_stock", {"item_id": 2, "qty": "1.0000"})
        _php("seed_stock", {"item_id": 3, "qty": "1.0000"})
        bom = _php("create_bom_approved")["bom_id"]
        mrp = _php("mrp", {"bom_id": bom, "planned_qty": "5.0000"})
        self.assertTrue(mrp.get("ok"), mrp)
        self.assertFalse(mrp["mrp"].get("auto_po"))
        self.assertGreaterEqual(mrp["mrp"].get("recommendation_count", 0), 1)
        mode = _php("adapter_mode")
        self.assertTrue(mode.get("no_parallel_gl"))
        self.assertIn(mode.get("mode"), ("temporary_canonical_ledger", "inventory_domain"))


if __name__ == "__main__":
    unittest.main()
