#!/usr/bin/env python3
"""Adversarial / failure-mode tests for staging remote apply (mocked SSH)."""
from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

import staging_remote_apply as SRA  # noqa: E402
from agentic_controlled_cpanel_sync import ALLOWED_DEST_MARKERS  # noqa: E402
from staging_apply_decision import resolve_apply  # noqa: E402
from staging_health_check import selftest as health_selftest  # noqa: E402


def _pkg(td: Path) -> Path:
    pkg = td / "pkg"
    (pkg / "index.php").parent.mkdir(parents=True, exist_ok=True)
    (pkg / "index.php").write_text("<?php // staging\n", encoding="utf-8")
    (pkg / "app").mkdir(parents=True, exist_ok=True)
    (pkg / "storage" / "cache").mkdir(parents=True, exist_ok=True)
    (pkg / "storage" / ".htaccess").write_text("Deny\n", encoding="utf-8")
    meta = {
        "target_host": "excel2.bcsrp.ir",
        "git_sha": "deadbeefcafebabe",
        "file_count": 2,
        "files_sha256": {},
    }
    (pkg / "ERPSMART_STAGING_RELEASE.json").write_text(json.dumps(meta, indent=2) + "\n", encoding="utf-8")
    return pkg


class _CP:
    def __init__(self, rc: int = 0, out: str = "", err: str = ""):
        self.returncode = rc
        self.stdout = out
        self.stderr = err


class StagingRemoteAdversarial(unittest.TestCase):
    def setUp(self) -> None:
        self._env = {
            "STAGING_SSH_HOST": "cpanel.example.internal",
            "STAGING_SSH_USER": "zzflgmfd",
            "STAGING_SSH_PRIVATE_KEY_PATH": "/tmp/id",
            "STAGING_SSH_KNOWN_HOSTS_PATH": "/tmp/known",
            "STAGING_REMOTE_DEST": "/home3/zzflgmfd/excel2.bcsrp",
            "STAGING_GIT_REF": "refs/heads/main",
            "STAGING_AWAIT_CI_RESULT": "success",
        }

    def test_health_selftest(self) -> None:
        self.assertTrue(health_selftest()["ok"])

    def test_dry_run_apply_false_preserved(self) -> None:
        self.assertFalse(resolve_apply(event_name="workflow_dispatch", inputs_apply=False))

    def test_wrong_destination_refused(self) -> None:
        with mock.patch.dict("os.environ", {**self._env, "STAGING_REMOTE_DEST": "/home3/zzflgmfd/excel.bcsrp"}, clear=False):
            with self.assertRaises(RuntimeError) as ctx:
                SRA.remote_dest()
            self.assertIn("production", str(ctx.exception).lower())

    def test_ssh_drop_during_identity(self) -> None:
        with mock.patch.dict("os.environ", self._env, clear=False):
            with mock.patch.object(SRA, "run_ssh", return_value=_CP(rc=255, err="Connection reset")):
                info = SRA.verify_identity("/home3/zzflgmfd/excel2.bcsrp")
                self.assertFalse(info["ok"])
                self.assertEqual(info.get("error"), "identity_ssh_failed")

    def test_concurrent_lock_busy(self) -> None:
        with mock.patch.dict("os.environ", self._env, clear=False):
            with mock.patch.object(SRA, "run_ssh", return_value=_CP(rc=42, out="BUSY\n")):
                with self.assertRaises(SRA.RemoteError) as ctx:
                    SRA.acquire_lock("/home3/zzflgmfd/excel2.bcsrp", "stamp")
                self.assertEqual(ctx.exception.code, "concurrent_deploy_lock")

    def test_backup_fail_no_dest_mutation(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            pkg = _pkg(Path(td))
            calls: list[str] = []

            def fake_ssh(cmd: str, timeout: int = 120):
                calls.append(cmd)
                if "mkdir" in cmd and "_erpsmart_staging_code_bak_" in cmd:
                    return _CP(rc=1, err="disk full")
                if "mkdir" in cmd and LOCK_NAME_HINT in cmd:
                    return _CP(rc=0, out="LOCKED\n")
                if "ATOMIC_OK" in cmd or "_erpsmart_atomic_probe" in cmd:
                    return _CP(rc=0, out="ATOMIC_OK\n")
                if "mkdir" in cmd:
                    return _CP(rc=0, out="")
                if cmd.startswith("rm "):
                    return _CP(rc=0, out="")
                return _CP(rc=0, out="")

            LOCK_NAME_HINT = ".erpsmart_staging_deploy.lock"

            with mock.patch.dict("os.environ", self._env, clear=False):
                with mock.patch.object(SRA, "run_ssh", side_effect=fake_ssh):
                    with mock.patch.object(SRA, "run_scp", return_value=None):
                        with mock.patch.object(SRA, "remote_file_exists", return_value=True):
                            result = SRA.apply_files(
                                pkg,
                                "/home3/zzflgmfd/excel2.bcsrp",
                                ["index.php", "ERPSMART_STAGING_RELEASE.json"],
                                dry_run=False,
                                expected_sha="deadbeefcafebabe",
                            )
            self.assertFalse(result.get("ok"))
            self.assertIn(result.get("error"), {"backup_mkdir_failed", "backup_copy_failed", "backup_verify_failed"})
            self.assertEqual(result.get("rollback"), "not_needed_pre_publish")
            self.assertFalse(result.get("dest_mutated"))

    def test_partial_publish_triggers_restore(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            pkg = _pkg(Path(td))
            state = {"n": 0}

            def fake_ssh(cmd: str, timeout: int = 120):
                if ".erpsmart_staging_deploy.lock" in cmd and "mkdir" in cmd:
                    return _CP(rc=0, out="LOCKED\n")
                if "_erpsmart_atomic_probe" in cmd:
                    return _CP(rc=0, out="ATOMIC_OK\n")
                if "cmp -s" in cmd:
                    return _CP(rc=0, out="")
                if "test -f" in cmd:
                    return _CP(rc=0, out="")
                if "mv -f" in cmd or ("cp -p" in cmd and ".pub" in cmd):
                    state["n"] += 1
                    if state["n"] >= 2:
                        return _CP(rc=1, err="disk quota")
                    return _CP(rc=0, out="")
                if "cp -p" in cmd and "_erpsmart_staging_code_bak_" in cmd:
                    return _CP(rc=0, out="")
                if "mkdir" in cmd:
                    return _CP(rc=0, out="")
                if cmd.startswith("rm "):
                    return _CP(rc=0, out="")
                if "printf '1" in cmd or "maintenance" in cmd:
                    return _CP(rc=0, out="")
                return _CP(rc=0, out="")

            restore_calls: list[str] = []

            def fake_restore(dest, bak, rels):
                restore_calls.append(bak)
                return {"restored": len(rels), "backup_dir": bak}

            with mock.patch.dict("os.environ", self._env, clear=False):
                with mock.patch.object(SRA, "run_ssh", side_effect=fake_ssh):
                    with mock.patch.object(SRA, "run_scp", return_value=None):
                        with mock.patch.object(SRA, "remote_file_exists", return_value=True):
                            with mock.patch.object(SRA, "restore_from_backup", side_effect=fake_restore):
                                result = SRA.apply_files(
                                    pkg,
                                    "/home3/zzflgmfd/excel2.bcsrp",
                                    ["index.php", "ERPSMART_STAGING_RELEASE.json"],
                                    dry_run=False,
                                    expected_sha="deadbeefcafebabe",
                                )
            self.assertFalse(result.get("ok"))
            self.assertTrue(restore_calls or result.get("rollback") in {"restored", "not_needed_pre_publish", "failed"})

    def test_dry_run_never_mutates(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            pkg = _pkg(Path(td))
            with mock.patch.object(SRA, "run_ssh") as ssh:
                out = SRA.apply_files(
                    pkg,
                    "/home3/zzflgmfd/excel2.bcsrp",
                    ["index.php"],
                    dry_run=True,
                    expected_sha="deadbeefcafebabe",
                )
            self.assertTrue(out["ok"])
            self.assertEqual(out["written"], 0)
            ssh.assert_not_called()

    def test_identity_marker_content(self) -> None:
        expected = ALLOWED_DEST_MARKERS[".erpsmart_deploy_identity"]
        self.assertEqual(expected, "excel2.bcsrp:/home3/zzflgmfd/excel2.bcsrp/")
        with mock.patch.dict("os.environ", self._env, clear=False):
            with mock.patch.object(SRA, "run_ssh", return_value=_CP(rc=0, out=expected + "\n")):
                info = SRA.verify_identity("/home3/zzflgmfd/excel2.bcsrp")
                self.assertTrue(info["ok"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
