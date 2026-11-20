"""RC1 MF-1 acceptance — BLOCKED until T1/T2/T5 manufacturing stock+cost APIs exist.

Never fabricates PASS. Probe reports missing methods; gate treats BLOCKED as nonzero.
"""
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
        # Source-level probe without PHP image
        from rc1_common import source_method_exists

        missing = []
        for method in ("reserveForProduction", "issueMaterial", "receiveFinishedGoods", "reverseMovement"):
            if not source_method_exists("app/Core/InventoryDomain.php", method):
                missing.append(f"InventoryDomain::{method}")
        for method in (
            "createProductionOrder",
            "issueMaterials",
            "recordScrap",
            "recordQcDisposition",
            "receiveFinishedGoods",
            "verifiedCostRollup",
        ):
            if not source_method_exists("app/Core/ProductionDomain.php", method):
                missing.append(f"ProductionDomain::{method}")
        cost = list((ROOT / "app" / "Core").glob("CostingAccounting*.php"))
        if not cost:
            missing.append("CostingAccounting* (T5 valuation resolver)")
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


@unittest.skipUnless(rc1_required(), "RC1 acceptance opt-in; run scripts/rc1_acceptance_gate.py")
class Rc1Mf1Acceptance(unittest.TestCase):
    def test_mf1_blocked_until_stock_and_cost_apis(self):
        try:
            sandbox = verify_sandbox(required=True)
        except Rc1DependencyMissing as exc:
            raise AssertionError(f"RC1_REQUIRED dependency missing for MF-1 probe: {exc}") from exc

        probe = _probe()
        write_json("mf1_probe.json", {"sandbox": sandbox, "probe": probe})
        if probe.get("status") == "CAPABLE" and not probe.get("missing"):
            self.fail("MF-1 kernel present but full E2E scenario not yet wired — extend harness before PASS")
        evidence = {
            "contract": "MF-1",
            "status": "BLOCKED",
            "owner": "T1/T2/T5",
            "missing": probe.get("missing", []),
            "pilot_present": probe.get("pilot_present"),
            "defect": "RC1-P0-001 / RC1-P0-002",
            "reason": "No production issue/FG stock API; no valuation resolver; BOM/plan pilot only.",
        }
        write_json("mf1_acceptance.json", evidence)
        raise Rc1Blocked(
            "MF-1 BLOCKED: missing "
            + ", ".join(probe.get("missing", [])[:6])
            + ("..." if len(probe.get("missing", [])) > 6 else "")
        )


if __name__ == "__main__":
    os.environ["RC1_REQUIRED"] = "1"
    unittest.main()
