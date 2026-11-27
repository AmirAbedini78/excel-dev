#!/usr/bin/env python3
"""Guards for staging-only package build and destination refuse rules."""
from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
import sys

sys.path.insert(0, str(ROOT / "scripts"))

from agentic_controlled_cpanel_sync import refuse_non_staging_dest  # noqa: E402
from staging_health_check import host_allowed, selftest as health_selftest  # noqa: E402
from staging_package_build import materialize_package, write_release_meta  # noqa: E402


class StagingPackageGuards(unittest.TestCase):
    def test_health_host_allowlist(self) -> None:
        self.assertTrue(host_allowed("excel2.bcsrp.ir"))
        self.assertFalse(host_allowed("excel.bcsrp.ir"))
        self.assertTrue(health_selftest()["ok"])

    def test_refuse_production_dest(self) -> None:
        self.assertEqual(
            refuse_non_staging_dest("/home3/zzflgmfd/excel.bcsrp"),
            "refused_production_web_root",
        )
        self.assertIsNone(refuse_non_staging_dest("/home3/zzflgmfd/excel2.bcsrp"))
        self.assertIsNone(refuse_non_staging_dest("/home3/zzflgmfd/excel2.bcsrp/"))

    def test_package_excludes_config_and_engine(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            out = Path(td) / "pkg"
            files = materialize_package(ROOT, out)
            self.assertFalse((out / "app" / "config.php").exists())
            self.assertFalse((out / "engine").exists())
            self.assertNotIn("engine/worker.py", files)
            meta = write_release_meta(out, "deadbeef", files)
            data = json.loads(meta.read_text(encoding="utf-8"))
            self.assertEqual(data["target_host"], "excel2.bcsrp.ir")
            self.assertEqual(data["worker_engine"], "OUT_OF_SCOPE")
            self.assertEqual(data["migrations"], "NOT_INCLUDED_NOT_AUTO")


if __name__ == "__main__":
    unittest.main()
