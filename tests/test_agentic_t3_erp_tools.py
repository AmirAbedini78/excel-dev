"""T3 ERP tools: contractDescriptors + MF UNVALUED/GL_BLOCKED MySQL harness."""
from __future__ import annotations

import json
import os
import pathlib
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
PORT = os.environ.get("O2C_DB_PORT", "3307")
USER = os.environ.get("O2C_DB_USER", "p2p")
PASSWORD = os.environ.get("O2C_DB_PASS", "p2p_test")


def _db_name() -> str:
    # Reuse disposable MF-1 schema the p2p sandbox user can access (CREATE DATABASE may be denied).
    return os.environ.get("O2C_DB_NAME", "erpsmart_mf1_test")


def _runner_db_host() -> str:
    return (os.environ.get("O2C_DB_HOST") or "127.0.0.1").strip()


def _php_db_host() -> str:
    explicit = (os.environ.get("O2C_DB_HOST_PHP") or "").strip()
    if explicit:
        return explicit
    host = _runner_db_host()
    if host in {"127.0.0.1", "localhost", "::1"}:
        return "host.docker.internal"
    return host or "host.docker.internal"


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


def _mysql_ok() -> bool:
    if os.environ.get("RC1_ALLOW_ANY_MYSQL", "").strip() in {"1", "true", "TRUE", "yes"}:
        return True
    try:
        r = subprocess.run(
            ["docker", "inspect", "-f", "{{.Name}}", "erpsmart-p2p-mysql-test"],
            capture_output=True,
            text=True,
            timeout=20,
        )
        return r.returncode == 0 and "erpsmart-p2p-mysql-test" in (r.stdout or "")
    except Exception:
        return False


def _php(script: str, *argv: str, timeout: int = 180) -> subprocess.CompletedProcess[str]:
    if not _docker_ok():
        raise unittest.SkipTest("erpsmart-o2c-php:8.2 unavailable")
    php_host = _php_db_host()
    cmd = [
        "docker",
        "run",
        "--rm",
        "--add-host=host.docker.internal:host-gateway",
        "-e",
        f"O2C_DB_HOST={php_host}",
        "-e",
        f"O2C_DB_PORT={PORT}",
        "-e",
        f"O2C_DB_USER={USER}",
        "-e",
        f"O2C_DB_PASS={PASSWORD}",
        "-e",
        f"O2C_DB_NAME={_db_name()}",
        "-v",
        f"{ROOT}:/app",
        "-w",
        "/app",
        "erpsmart-o2c-php:8.2",
        "php",
        script,
        *argv,
    ]
    return subprocess.run(
        cmd, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=timeout
    )


def _runner(scenario: str, args: dict | None = None, timeout: int = 180) -> dict:
    completed = _php(
        "tests/harness/agentic_t3_mf_tools_runner.php",
        scenario,
        json.dumps(args or {}, ensure_ascii=False),
        timeout=timeout,
    )
    out = (completed.stdout or "").strip().splitlines()
    if not out:
        raise AssertionError(f"no_stdout rc={completed.returncode} stderr={completed.stderr}")
    try:
        payload = json.loads(out[-1])
    except json.JSONDecodeError as exc:
        raise AssertionError(f"bad_json={out[-1]!r} stderr={completed.stderr}") from exc
    if completed.returncode != 0 and not payload.get("ok"):
        raise AssertionError(f"runner_failed rc={completed.returncode} payload={payload} stderr={completed.stderr}")
    return payload


class AgenticT3ErpToolsTest(unittest.TestCase):
    def test_source_contract_and_docs(self):
        reg = (ROOT / "app/Core/AiToolRegistry.php").read_text(encoding="utf-8")
        self.assertIn("function contractDescriptors", reg)
        self.assertIn("manufacturing_bom_read", reg)
        self.assertIn("manufacturing_order_read", reg)
        self.assertIn("manufacturing_material_readiness", reg)
        self.assertIn("GL_BLOCKED", reg)
        self.assertIn("UNVALUED", reg)
        self.assertIn("assertDescriptorPermissions", reg)
        self.assertNotIn("attemptWipFgGlPosting", reg)  # tools must not auto-post
        for rel in (
            "docs/ai/agentic-next/03-CAPABILITY-COVERAGE-MATRIX.md",
            "docs/ai/agentic-next/05-ERP-TOOLS-AND-PERMISSIONS.md",
        ):
            self.assertTrue((ROOT / rel).is_file(), rel)
            text = (ROOT / rel).read_text(encoding="utf-8")
            self.assertIn("GL_BLOCKED", text)
            self.assertIn("UNVALUED", text)

    def test_php_contract_descriptors_no_db(self):
        completed = _php("tests/php_agentic_t3_contract_descriptors_test.php")
        self.assertEqual(completed.returncode, 0, completed.stderr or completed.stdout)
        self.assertIn("T3_CONTRACT_DESCRIPTORS_PASS", completed.stdout)

    def test_workflow_modes_a_h(self):
        payload = _runner("workflow_modes")
        self.assertTrue(payload.get("ok"), payload)
        self.assertEqual(payload.get("failures"), [])

    def test_mf_tools_mysql_unvalued_gl_blocked_and_permission(self):
        if not _mysql_ok():
            raise unittest.SkipTest("erpsmart-p2p-mysql-test unavailable")
        reset = _runner("reset")
        self.assertTrue(reset.get("ok"), reset)
        contract = _runner("contract_descriptors")
        self.assertTrue(contract.get("ok"), contract)
        self.assertGreaterEqual(contract.get("mf_tool_count", 0), 3)
        seeded = _runner("seed_and_read")
        self.assertTrue(seeded.get("ok"), seeded)
        self.assertEqual(seeded.get("gl_posting"), "GL_BLOCKED")
        self.assertIn(seeded.get("cost_class"), {"UNVALUED", "PROVISIONAL", "POSTED_ACTUAL"})
        # Opening stock with unit_cost 0 → readiness estimate often UNVALUED
        self.assertIn(seeded.get("ready_material_class"), {"UNVALUED", "PROVISIONAL_ESTIMATE"})
        denied = _runner("permission_denied")
        self.assertTrue(denied.get("ok"), denied)
        self.assertTrue(denied.get("permission_denied"))
        self.assertTrue(denied.get("cross_company_blocked"))


if __name__ == "__main__":
    unittest.main()
