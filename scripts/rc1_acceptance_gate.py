#!/usr/bin/env python3
"""RC1 REQUIRED acceptance gate — real PHP+MySQL E2E; SKIP/missing deps = nonzero.

Commercial MVP `release_gate.py` is source-only and insufficient for RC1 exit.
This gate:
  - verifies disposable MySQL container identity
  - runs TR-1, TR-2, MF-1, SYS-1, OPS-1 acceptance modules with RC1_REQUIRED=1
  - treats unittest SkipTest and missing Docker/MySQL as FAIL
  - treats Rc1Blocked (missing feature/provenance) as BLOCKED (exit 2)
  - exit 0 only when all five contracts PASS

Usage:
  set RC1_REQUIRED=1
  python scripts/rc1_acceptance_gate.py
  python scripts/rc1_acceptance_gate.py --strict-pass   # exit 2 if any BLOCKED
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time
import traceback
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests" / "harness"))

from rc1_common import (  # noqa: E402
    Rc1Blocked,
    Rc1DependencyMissing,
    evidence_path,
    verify_sandbox,
    write_json,
)

CONTRACTS = [
    ("TR-1", "tests.rc1.test_rc1_tr1_acceptance"),
    ("TR-2", "tests.rc1.test_rc1_tr2_acceptance"),
    ("MF-1", "tests.rc1.test_rc1_mf1_acceptance"),
    ("SYS-1", "tests.rc1.test_rc1_sys1_acceptance"),
    ("OPS-1", "tests.rc1.test_rc1_ops1_acceptance"),
]


class _Result(unittest.TextTestResult):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.blocked: list[str] = []
        self.skipped_tests: list[str] = []

    def addSkip(self, test, reason):
        super().addSkip(test, reason)
        self.skipped_tests.append(f"{test.id()}: {reason}")

    def addError(self, test, err):
        exc_type, exc, _tb = err
        if isinstance(exc, Rc1Blocked) or (exc_type is not None and issubclass(exc_type, Rc1Blocked)):
            self.blocked.append(str(exc))
            self.failures.append((test, "".join(traceback.format_exception(*err))))
            self._mirrorOutput = False  # type: ignore[attr-defined]
            return
        super().addError(test, err)

    def addFailure(self, test, err):
        exc_type, exc, _tb = err
        if isinstance(exc, Rc1Blocked) or (exc_type is not None and issubclass(exc_type, Rc1Blocked)):
            self.blocked.append(str(exc))
        super().addFailure(test, err)


def run_module(modname: str) -> dict:
    loader = unittest.TestLoader()
    try:
        suite = loader.loadTestsFromName(modname)
    except Exception as exc:  # noqa: BLE001
        return {"status": "FAIL", "error": f"load_failed:{exc}", "tests": 0}

    stream = open(os.devnull, "w", encoding="utf-8") if os.environ.get("RC1_QUIET") else sys.stderr
    runner = unittest.TextTestRunner(stream=stream, verbosity=2, resultclass=_Result)
    result: _Result = runner.run(suite)  # type: ignore[assignment]

    if result.skipped_tests:
        return {
            "status": "SKIP",
            "error": "; ".join(result.skipped_tests),
            "tests": result.testsRun,
        }
    if result.blocked:
        return {
            "status": "BLOCKED",
            "error": "; ".join(result.blocked),
            "tests": result.testsRun,
            "failures": len(result.failures),
        }
    if result.errors or result.failures:
        msgs = []
        for test, tb in list(result.errors) + list(result.failures):
            msgs.append(f"{test.id()}: {tb.splitlines()[-1] if tb else 'error'}")
        return {"status": "FAIL", "error": "; ".join(msgs[:5]), "tests": result.testsRun}
    if result.testsRun == 0:
        return {"status": "SKIP", "error": "no_tests_collected", "tests": 0}
    return {"status": "PASS", "tests": result.testsRun}


def main() -> int:
    parser = argparse.ArgumentParser(description="RC1 REQUIRED PHP+MySQL acceptance gate")
    parser.add_argument(
        "--strict-pass",
        action="store_true",
        help="Exit nonzero unless all contracts PASS (BLOCKED counts as failure)",
    )
    parser.add_argument(
        "--allow-blocked",
        action="store_true",
        help="Exit 0 when only BLOCKED remain (still fail on SKIP/FAIL). Default for G1 scaffolding.",
    )
    parser.add_argument("--root", default=str(ROOT))
    args = parser.parse_args()
    root = Path(args.root).resolve()
    os.chdir(root)
    if str(root) not in sys.path:
        sys.path.insert(0, str(root))
    # Ensure tests.rc1 is importable
    (root / "tests" / "rc1" / "__init__.py").touch(exist_ok=True)

    os.environ["RC1_REQUIRED"] = "1"
    os.environ["PYTHONDONTWRITEBYTECODE"] = "1"

    print("ERPSMART — RC1 REQUIRED acceptance gate")
    print("NOTE: Commercial MVP release_gate.py is source-only and insufficient for RC1.")
    started = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

    try:
        sandbox = verify_sandbox(required=True)
        print(f"[sandbox] PASS container={sandbox.get('container_id', '')[:12]} db={sandbox['database']}")
        write_json("sandbox_verified.json", sandbox)
        database_tested = True
    except Rc1DependencyMissing as exc:
        report = {
            "gate": "RC1_REQUIRED",
            "started": started,
            "DATABASE_TESTED": False,
            "status": "FAIL",
            "error": f"sandbox_dependency:{exc}",
            "contracts": {c: {"status": "SKIP", "error": "sandbox_unavailable"} for c, _ in CONTRACTS},
        }
        write_json("rc1_gate_report.json", report)
        print(f"[sandbox] FAIL: {exc}")
        print("RC1 GATE FAIL — mandatory PHP+MySQL dependency missing (SKIP is not PASS).")
        return 1

    # Isolate disposable schemas per contract to avoid DROP/CREATE races on one shared DB.
    db_by_contract = {
        "TR-1": "erpsmart_rc1_tr1_test",
        "TR-2": "erpsmart_rc1_tr2_test",
        "MF-1": "erpsmart_mf1_test",
        "SYS-1": "erpsmart_rc1_sys1_test",
        "OPS-1": os.environ.get("O2C_DB_NAME", "erpsmart_rc1_test"),
    }
    results: dict[str, dict] = {}
    for contract, mod in CONTRACTS:
        print(f"\n=== {contract} ({mod}) ===", flush=True)
        os.environ["O2C_DB_NAME"] = db_by_contract.get(contract, "erpsmart_rc1_test")
        if contract == "MF-1":
            os.environ["O2C_MF_DB_NAME"] = "erpsmart_mf1_test"
        results[contract] = run_module(mod)
        print(f"[{contract}] {results[contract]['status']}", flush=True)

    counts = {"PASS": 0, "FAIL": 0, "BLOCKED": 0, "SKIP": 0}
    for r in results.values():
        counts[r["status"]] = counts.get(r["status"], 0) + 1

    report = {
        "gate": "RC1_REQUIRED",
        "started": started,
        "finished": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "DATABASE_TESTED": database_tested,
        "sandbox": sandbox,
        "contracts": results,
        "counts": counts,
        "commercial_mvp_gate_note": (
            "scripts/release_gate.py + commercial-mvp-gate.yml validate source/runtime only; "
            "they do not prove PHP domain E2E on MySQL."
        ),
    }
    path = write_json("rc1_gate_report.json", report)
    print(f"\nEvidence: {path}")
    print(f"Summary: PASS={counts['PASS']} FAIL={counts['FAIL']} BLOCKED={counts['BLOCKED']} SKIP={counts['SKIP']}")

    if counts["SKIP"] or counts["FAIL"]:
        print("RC1 GATE FAIL — skip or assertion failure on mandatory contract.")
        return 1

    if counts["BLOCKED"]:
        # Default: nonzero so RC exit stays blocked; --allow-blocked for scaffolding CI green-ish signal
        if args.allow_blocked and not args.strict_pass:
            print("RC1 GATE PARTIAL — BLOCKED contracts remain (scaffolding mode --allow-blocked).")
            return 0
        print("RC1 GATE BLOCKED — missing feature/provenance; not a PASS.")
        return 2

    print("RC1 GATE PASS — all five acceptance contracts PASS.")
    return 0


if __name__ == "__main__":
    # Also allow invoking legacy TR-1 file as evidence helper
    if len(sys.argv) > 1 and sys.argv[1] == "--run-legacy-tr1":
        os.environ["RC1_REQUIRED"] = "1"
        env = dict(os.environ)
        env["PYTHONDONTWRITEBYTECODE"] = "1"
        cmd = [sys.executable, "-m", "unittest", "tests.test_ap_trading_php_e2e_1023", "-v"]
        print("+", " ".join(cmd), flush=True)
        raise SystemExit(subprocess.run(cmd, cwd=ROOT, env=env).returncode)
    raise SystemExit(main())
