"""RC1 MF-1 acceptance — capability probe + real PHP+MySQL manufacturing E2E when present."""
from __future__ import annotations

import json
import os
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "tests" / "harness"))

from rc1_common import (  # noqa: E402
    PHP_IMAGE,
    Rc1Blocked,
    Rc1DependencyMissing,
    docker_image_ok,
    rc1_required,
    verify_sandbox,
    write_json,
)


def _probe() -> dict:
    if not docker_image_ok():
        from rc1_common import source_method_exists

        missing = []
        for method in ("reserveForProduction", "issueMaterial", "receiveFinishedGoods", "reverseMovement"):
            if not source_method_exists("app/Core/InventoryDomain.php", method):
                missing.append(f"InventoryDomain::{method}")
        for method in (
            "createOrder",
            "issueMaterials",
            "recordScrap",
            "recordQc",
            "receiveFinishedGoods",
            "mrpRecommendations",
        ):
            if not source_method_exists("app/Core/ManufacturingExecutionDomain.php", method):
                missing.append(f"ManufacturingExecutionDomain::{method}")
        if not (ROOT / "app/Core/CostingAccountingDomain.php").is_file():
            missing.append("CostingAccountingDomain")
        return {
            "ok": True,
            "contract": "MF-1",
            "status": "BLOCKED" if missing else "CAPABLE",
            "missing": missing,
            "probe": "source",
        }

    mount = str(ROOT).replace("\\", "/")
    cmd = [
        "docker",
        "run",
        "--rm",
        "-v",
        f"{mount}:/app",
        "-w",
        "/app",
        PHP_IMAGE,
        "php",
        "tests/harness/rc1_capability_probe.php",
        "mf1",
    ]
    completed = subprocess.run(cmd, capture_output=True, text=True, timeout=60)
    lines = (completed.stdout or "").strip().splitlines()
    if not lines:
        raise AssertionError(f"mf1_probe_empty stderr={completed.stderr}")
    return json.loads(lines[-1])


def _php_mf(scenario: str, args: dict | None = None) -> dict:
    host = os.environ.get("O2C_DB_HOST", "host.docker.internal")
    port = os.environ.get("O2C_DB_PORT", "3307")
    user = os.environ.get("O2C_DB_USER", "p2p")
    password = os.environ.get("O2C_DB_PASS", "p2p_test")
    database = os.environ.get("O2C_DB_NAME", "erpsmart_rc1_test")
    mount = str(ROOT).replace("\\", "/")
    cmd = [
        "docker",
        "run",
        "--rm",
        "--add-host=host.docker.internal:host-gateway",
        "-e",
        f"O2C_DB_HOST={host}",
        "-e",
        f"O2C_DB_PORT={port}",
        "-e",
        f"O2C_DB_USER={user}",
        "-e",
        f"O2C_DB_PASS={password}",
        "-e",
        f"O2C_DB_NAME={database}",
        "-v",
        f"{mount}:/app",
        "-w",
        "/app",
        PHP_IMAGE,
        "php",
        "tests/harness/mf_e2e_runner.php",
        scenario,
        json.dumps(args or {}, ensure_ascii=False),
    ]
    completed = subprocess.run(cmd, capture_output=True, text=True, timeout=180)
    lines = (completed.stdout or "").strip().splitlines()
    if not lines:
        raise AssertionError(f"mf_e2e empty rc={completed.returncode} stderr={completed.stderr}")
    return json.loads(lines[-1])


@unittest.skipUnless(rc1_required(), "RC1 acceptance opt-in; run scripts/rc1_acceptance_gate.py")
class Rc1Mf1Acceptance(unittest.TestCase):
    def test_mf1_capability_and_php_e2e(self):
        try:
            sandbox = verify_sandbox(required=True)
        except Rc1DependencyMissing as exc:
            raise AssertionError(f"RC1_REQUIRED dependency missing for MF-1: {exc}") from exc

        probe = _probe()
        write_json("mf1_probe.json", {"sandbox": sandbox, "probe": probe})
        if probe.get("status") != "CAPABLE" or probe.get("missing"):
            write_json(
                "mf1_acceptance.json",
                {
                    "contract": "MF-1",
                    "status": "BLOCKED",
                    "owner": "T1/T2/T5",
                    "missing": probe.get("missing", []),
                },
            )
            raise Rc1Blocked("MF-1 BLOCKED: missing " + ", ".join(probe.get("missing", [])[:8]))

        if not (ROOT / "tests/harness/mf_e2e_runner.php").is_file():
            raise Rc1Blocked("MF-1 CAPABLE but mf_e2e_runner.php missing")

        e2e = _php_mf("lifecycle_happy", {})
        write_json("mf1_e2e.json", e2e)
        self.assertTrue(e2e.get("ok"), e2e)
        write_json(
            "mf1_acceptance.json",
            {
                "contract": "MF-1",
                "status": "PASS",
                "sandbox": sandbox,
                "probe": probe,
                "e2e": e2e,
                "gl_valuation": "BLOCKED",
            },
        )


if __name__ == "__main__":
    os.environ["RC1_REQUIRED"] = "1"
    unittest.main()
