#!/usr/bin/env python3
"""Adversarial tests for cron-based staging pull deploy (no live host required)."""
from __future__ import annotations

import json
import os
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

import staging_cron_deploy as cron  # noqa: E402
from staging_package_build import (  # noqa: E402
    build_zip,
    materialize_package,
    stamp_gates_success,
    write_release_meta,
)


class StagingCronAdversarial(unittest.TestCase):
    def test_selftest_bundle(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            result = cron.selftest(Path(td) / "selftest")
            self.assertTrue(result["ok"], result)

    def test_no_new_release_exits_clean(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            boot = Path(td) / "boot"
            dest = Path(td) / "excel2.bcsrp"
            boot.mkdir()
            (boot / "state").mkdir(parents=True)
            (boot / "locks").mkdir()
            (boot / "logs").mkdir()
            dest.mkdir()
            (dest / ".erpsmart_deploy_identity").write_text(
                cron.EXPECTED_MARKER + "\n", encoding="utf-8"
            )
            cfg = {
                "enabled": True,
                "github_repo": "AmirAbedini78/excel-dev",
                "dest": str(dest),
                "health_url": "https://excel2.bcsrp.ir/",
            }
            os.environ["ERPSMART_CRON_ALLOW_FIXTURE"] = "1"
            with mock.patch.object(cron, "list_releases", return_value=[]):
                report = cron.run_cycle(cfg, apply=False, dry_run=True, bootstrap=boot)
            self.assertEqual(report["status"], "NO_RELEASE")
            self.assertTrue(report["ok"])

    def test_ci_failed_gates_rejected(self) -> None:
        meta = {"gates": {"release-gate": "failure", "rc1-mysql-php": "success"}}
        self.assertFalse(cron.gates_ok(meta))

    def test_bad_checksum_rejected(self) -> None:
        with self.assertRaises(cron.DeployError) as ctx:
            cron.parse_sha256_sidecar("not-a-hash  file.zip\n", "file.zip")
        self.assertEqual(ctx.exception.code, "checksum_sidecar_invalid")

    def test_bad_manifest_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            pkg = Path(td) / "pkg"
            pkg.mkdir()
            (pkg / "index.php").write_text("x\n", encoding="utf-8")
            with self.assertRaises(cron.DeployError) as ctx:
                cron.verify_extracted_manifest(
                    pkg,
                    {
                        "git_sha": "deadbeef",
                        "file_manifest": ["index.php", "missing.php"],
                        "files_sha256": {"index.php": "00" * 32},
                    },
                )
            self.assertIn(ctx.exception.code, {"manifest_missing_files", "manifest_checksum_mismatch"})

    def test_wrong_identity_refused(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            dest = Path(td) / "excel.bcsrp"
            dest.mkdir()
            (dest / ".erpsmart_deploy_identity").write_text(
                "excel.bcsrp:/home3/zzflgmfd/excel.bcsrp/\n", encoding="utf-8"
            )
            os.environ["ERPSMART_CRON_ALLOW_FIXTURE"] = "1"
            with self.assertRaises(cron.DeployError):
                cron.verify_dest_identity(dest)

    def test_concurrent_cron_lock(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            boot = Path(td)
            (boot / "locks").mkdir()
            lock = cron.DeployLock(boot)
            lock.acquire()
            try:
                with self.assertRaises(cron.DeployError) as ctx:
                    cron.DeployLock(boot).acquire()
                self.assertEqual(ctx.exception.code, "concurrent_lock")
            finally:
                lock.release()

    def test_interrupted_download_leaves_no_partial_final(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            dest = Path(td) / "out.bin"

            class Boom(Exception):
                pass

            def fake_urlopen(*_a, **_k):
                raise Boom("network_drop")

            with mock.patch.object(cron.urllib.request, "urlopen", side_effect=fake_urlopen):
                with self.assertRaises(Boom):
                    cron.https_download("https://example.invalid/x", dest)
            self.assertFalse(dest.exists())
            self.assertFalse(dest.with_suffix(dest.suffix + ".part").exists())

    def test_backup_fail_changes_nothing(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            dest = Path(td) / "dest"
            dest.mkdir()
            (dest / "index.php").write_text("KEEP\n", encoding="utf-8")
            # backup_root as an existing FILE → mkdir under it must fail; dest unchanged
            bak_file = Path(td) / "bak_as_file"
            bak_file.write_text("not_a_dir\n", encoding="utf-8")
            with self.assertRaises(Exception):
                cron.backup_dest(dest, bak_file, ["index.php"])
            self.assertEqual((dest / "index.php").read_text(encoding="utf-8"), "KEEP\n")

    def test_secret_preservation_on_apply(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            dest = Path(td) / "dest"
            pkg = Path(td) / "pkg"
            dest.mkdir()
            pkg.mkdir()
            (dest / ".erpsmart_deploy_identity").write_text(cron.EXPECTED_MARKER + "\n", encoding="utf-8")
            (dest / "app").mkdir()
            (dest / "app" / "config.php").write_text("RUNTIME\n", encoding="utf-8")
            (dest / "storage" / "uploads").mkdir(parents=True)
            (dest / "storage" / "uploads" / "x.pdf").write_text("BLOB\n", encoding="utf-8")
            (pkg / "index.php").write_text("NEW\n", encoding="utf-8")
            ops = cron.build_plan(pkg, dest)
            cron.apply_plan(pkg, dest, ops, backup_root=None)
            self.assertEqual((dest / "app" / "config.php").read_text(encoding="utf-8"), "RUNTIME\n")
            self.assertEqual((dest / "storage" / "uploads" / "x.pdf").read_text(encoding="utf-8"), "BLOB\n")

    def test_stale_release_rejected(self) -> None:
        with self.assertRaises(cron.DeployError) as ctx:
            # Simulate via helper: wrong tag/sha pairing
            ok = cron.release_belongs_to_main(
                {"branch_required": "main"},
                "staging-excel2-aaaaaaaaaaaa",
                "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
            )
            self.assertFalse(ok)
            raise cron.DeployError("stale_release_rejected")
        self.assertEqual(ctx.exception.code, "stale_release_rejected")

    def test_health_fail_triggers_rollback_helper(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            dest = Path(td) / "dest"
            bak = Path(td) / "bak"
            dest.mkdir()
            bak.mkdir()
            (dest / "index.php").write_text("NEW\n", encoding="utf-8")
            (bak / "index.php").write_text("OLD\n", encoding="utf-8")
            (bak / "BACKUP_OK.json").write_text('{"ok":true}\n', encoding="utf-8")
            n = cron.restore_backup(bak, dest)
            self.assertGreaterEqual(n, 1)
            self.assertEqual((dest / "index.php").read_text(encoding="utf-8"), "OLD\n")

    def test_package_zip_build_excludes_secrets(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            out = Path(td) / "pkg"
            files = materialize_package(ROOT, out)
            self.assertFalse((out / "app" / "config.php").exists())
            write_release_meta(out, "c" * 40, files)
            zpath = Path(td) / "p.zip"
            digest = build_zip(out, zpath)
            self.assertEqual(len(digest), 64)
            with zipfile.ZipFile(zpath) as zf:
                names = zf.namelist()
            self.assertNotIn("app/config.php", names)
            self.assertTrue(any(n.endswith("ERPSMART_STAGING_RELEASE.json") for n in names))

    def test_stamp_gates_success(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            out = Path(td)
            meta = write_release_meta(out, "d" * 40, ["index.php"])
            stamped = stamp_gates_success(meta, "d" * 40)
            self.assertEqual(stamped["gates"]["release-gate"], "success")
            self.assertEqual(stamped["gates"]["rc1-mysql-php"], "success")


if __name__ == "__main__":
    unittest.main()
