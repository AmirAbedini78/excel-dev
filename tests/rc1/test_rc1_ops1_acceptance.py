"""RC1 OPS-1 acceptance — migration/security/operability."""
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


@unittest.skipUnless(rc1_required(), "RC1 acceptance opt-in; run scripts/rc1_acceptance_gate.py")
class Rc1Ops1Acceptance(unittest.TestCase):
    def test_ops1_sandbox_and_provenance_gate(self):
        try:
            sandbox = verify_sandbox(required=True)
        except Rc1DependencyMissing as exc:
            raise AssertionError(f"RC1_REQUIRED dependency missing for OPS-1: {exc}") from exc

        provenance = os.environ.get("RC1_PROD_SCHEMA_PROVENANCE", "").strip()
        probe: dict = {"repo_only": True}
        if docker_image_ok():
            mount = str(ROOT).replace("\\", "/")
            cmd = [
                "docker",
                "run",
                "--rm",
                "-e",
                f"RC1_PROD_SCHEMA_PROVENANCE={provenance}",
                "-v",
                f"{mount}:/app",
                "-w",
                "/app",
                PHP_IMAGE,
                "php",
                "tests/harness/rc1_capability_probe.php",
                "ops1_schema",
            ]
            completed = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
            lines = (completed.stdout or "").strip().splitlines()
            if lines:
                probe = json.loads(lines[-1])

        evidence = {
            "contract": "OPS-1",
            "sandbox": sandbox,
            "disposable_migrate_idempotency": "COVERED_BY_TR1_TR2_RESET",
            "production_schema_provenance": provenance or None,
            "probe": probe,
            "cpanel_prod_deploy": "NOT_RUN",
            "browser_walkthrough": "PENDING_T6",
            "security_suite": "PENDING_PARTIAL",
        }

        if not provenance:
            evidence["status"] = "BLOCKED"
            evidence["reason"] = (
                "No verified prior deployed schema provenance. "
                "Repo SCHEMA_VERSION is not production proof. "
                "Set RC1_PROD_SCHEMA_PROVENANCE when staging dump is available."
            )
            write_json("ops1_acceptance.json", evidence)
            raise Rc1Blocked("OPS-1 BLOCKED: missing production/staging schema provenance")

        evidence["status"] = "PASS"
        write_json("ops1_acceptance.json", evidence)


if __name__ == "__main__":
    os.environ["RC1_REQUIRED"] = "1"
    unittest.main()
