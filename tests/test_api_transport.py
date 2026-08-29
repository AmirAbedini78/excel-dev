from __future__ import annotations

import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "engine"))

import worker  # noqa: E402


class ApiTransportContractTests(unittest.TestCase):
    def test_keepalive_transport_is_installed(self):
        self.assertTrue(getattr(worker.Api, "_keepalive_transport_v1_installed", False))
        self.assertEqual(worker.Api.post.__name__, "keepalive_post")

    def test_transport_is_thread_local(self):
        api = worker.Api({
            "server_url": "https://example.invalid/ai_api.php",
            "worker_token": "test",
            "request_timeout_seconds": 5,
            "api_retry_attempts": 1,
            "api_retry_base_seconds": 0.2,
        })
        self.assertIsInstance(api._keepalive_local, __import__("threading").local)


if __name__ == "__main__":
    unittest.main()