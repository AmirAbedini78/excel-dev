"""Regression for real HTTP/1.1 chunk cancellation observed after v10.10.2."""
from __future__ import annotations
import contextlib
import http.server
import io
import json
import sys
import tempfile
import threading
import time
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "engine"))
import local_inference as L
import agent_kernel_smoke as S
import agent_intelligence_kernel as K
import runtime_diagnostics as RD
import worker as W
from test_low_memory_runtime import cfg, ollama_server


@contextlib.contextmanager
def chunked_server(mode="deadline"):
    calls = []
    class Handler(http.server.BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"
        def log_message(self, *args):
            pass
        def send_chunk(self, data):
            self.wfile.write(f"{len(data):x}\r\n".encode() + data + b"\r\n")
            self.wfile.flush()
        def do_GET(self):
            if self.path == "/api/tags":
                data = {"models": [{"name": name, "size": 1024} for name in ("tiny", "other")]}
            elif self.path == "/api/version":
                data = {"version": "0.0.0-fixture", "private": "secret"}
            else:
                data = {"models": [{"name": "tiny", "size": 2048, "size_vram": 0, "private": "secret"}]}
            self.send_response(200)
            self.send_header("Transfer-Encoding", "chunked")
            self.end_headers()
            try:
                if mode == "get_deadline":
                    for _ in range(40):
                        self.send_chunk(b" ")
                        time.sleep(.01)
                else:
                    self.send_chunk(json.dumps(data).encode())
                self.wfile.write(b"0\r\n\r\n")
            except OSError:
                pass
        def do_POST(self):
            body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
            calls.append(body)
            try:
                if mode == "headers_deadline":
                    time.sleep(.3)
                self.send_response(200)
                self.send_header("Transfer-Encoding", "chunked")
                self.end_headers()
                if mode == "recover" and body["model"] == "other":
                    findings = json.loads(body["messages"][1]["content"])["findings"]
                    top = max(findings, key=lambda x: x["severity"])["id"]
                    content = json.dumps({"finding_ids": [top], "action_id": "A0"})
                    self.send_chunk((json.dumps({"message": {"content": content}, "done": True,
                                                "model": body["model"], "done_reason": "stop"}) + "\n").encode())
                    self.wfile.write(b"0\r\n\r\n")
                elif mode == "ok":
                    text = "پاسخ تست"
                    wire = (json.dumps({"message": {"content": text}, "done": False}, ensure_ascii=False) + "\n" +
                            json.dumps({"message": {"content": ""}, "done": True, "model": body["model"],
                                        "done_reason": "stop", "eval_count": 7}) + "\n").encode()
                    # HTTP chunks may split UTF-8 characters and NDJSON lines.
                    for offset in range(0, len(wire), 3):
                        self.send_chunk(wire[offset:offset + 3])
                    self.wfile.write(b"0\r\n\r\n")
                elif mode == "disconnect":
                    self.send_chunk(b'{"message":{"content":"partial"},"done":false}\n')
                    self.close_connection = True
                else:
                    for _ in range(40):
                        self.send_chunk(b'{"message":{"thinking":"x"},"done":false}\n')
                        time.sleep(.01)
            except OSError:
                pass
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    server.daemon_threads = True
    thread = threading.Thread(target=server.serve_forever, kwargs={"poll_interval": .01}, daemon=True)
    thread.start()
    try:
        with patch.object(L, "_host_available_mb", return_value=16384):
            yield f"http://127.0.0.1:{server.server_port}", calls
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=1)


class ChunkedTransportTests(unittest.TestCase):
    def test_chunked_deadline_is_classified_without_cross_thread_close(self):
        with chunked_server() as (url, _):
            for _ in range(3):
                started = time.monotonic()
                with self.assertRaises(L.InferenceError) as caught:
                    L.stream_chat(url, {"model": "tiny"}, started + .12)
                self.assertEqual(caught.exception.code, "deadline")
                self.assertLess(time.monotonic() - started, .6)
                details = L.failure_details(caught.exception)
                self.assertEqual(details["phase"], "stream")
                self.assertGreater(details["thinking_chars"], 0)
                self.assertEqual(details["content_chars"], 0)

    def test_split_multibyte_ndjson_and_separate_final_metrics(self):
        with chunked_server("ok") as (url, _):
            result = L.stream_chat(url, {"model": "tiny"}, time.monotonic() + 2)
        self.assertEqual(result["message"]["content"], "پاسخ تست")
        self.assertEqual(result["_metrics"]["eval_count"], 7)
        self.assertEqual(result["_metrics"]["chunk_count"], 2)

    def test_disconnected_chunked_stream_never_returns_partial_answer(self):
        with chunked_server("disconnect") as (url, _):
            with self.assertRaises(L.InferenceError) as caught:
                L.stream_chat(url, {"model": "tiny"}, time.monotonic() + 2)
        self.assertIn(caught.exception.code, {"network", "incomplete_stream"})

    def test_header_wait_is_bounded_and_distinguished_from_generation(self):
        with chunked_server("headers_deadline") as (url, _):
            with self.assertRaises(L.InferenceError) as caught:
                L.stream_chat(url, {"model": "tiny"}, time.monotonic() + .12)
        self.assertEqual(caught.exception.code, "deadline")
        self.assertEqual(caught.exception.diagnostics["phase"], "headers")
        self.assertIsNone(caught.exception.diagnostics["first_chunk_seconds"])

    def test_chunked_inventory_deadline_has_single_thread_cleanup(self):
        with chunked_server("get_deadline") as (url, _):
            with self.assertRaises(L.InferenceError) as caught:
                L._json_get(url, "/api/tags", timeout=.12)
        self.assertEqual(caught.exception.code, "deadline")

    def test_actual_worker_preserves_timeout_details_through_gateway(self):
        with tempfile.TemporaryDirectory() as td, chunked_server() as (url, _):
            worker = W.Worker(cfg(url, td))
            with self.assertRaises(Exception) as caught:
                worker.ollama_chat({"id": 0}, 0, [{"role": "user", "content": "private prompt"}], [],
                                   model="tiny", timeout_seconds=.12)
            self.assertEqual(caught.exception.code, "deadline")
            details = L.failure_details(caught.exception)
            self.assertIn("InferenceError", details["exception_types"])
            self.assertEqual(details["phase"], "stream")
            self.assertNotIn("private prompt", json.dumps(details))

    def test_timeout_cools_same_daemon_across_models_and_worker_instances(self):
        with tempfile.TemporaryDirectory() as td, chunked_server() as (url, calls):
            worker = W.Worker(cfg(url, td))
            with self.assertRaises(Exception):
                worker.ollama_chat({"id": 0}, 0, [{"role": "user", "content": "x"}], [],
                                   model="tiny", timeout_seconds=.12)
            restarted = W.Worker(cfg(url, td))
            with self.assertRaises(Exception) as caught:
                restarted.ollama_chat({"id": 0}, 0, [{"role": "user", "content": "x"}], [], model="other")
            self.assertEqual(caught.exception.code, "endpoint_cooling")
            self.assertEqual(len(calls), 1)
            path = next(Path(td).glob("*.cooldown.json"))
            path.write_text(json.dumps({"until_epoch": time.time() - 1}))
            with self.assertRaises(Exception) as caught:
                restarted.ollama_chat({"id": 0}, 0, [{"role": "user", "content": "x"}], [],
                                      model="other", timeout_seconds=.12)
            self.assertEqual(caught.exception.code, "deadline")
            self.assertEqual(len(calls), 2)

    def test_slow_endpoint_still_allows_explicit_peer_with_same_total_budget(self):
        with tempfile.TemporaryDirectory() as td, chunked_server() as (slow, a), ollama_server() as (good, b):
            config = cfg(slow, td)
            config["ollama_fallback_endpoints"] = [{"url": good}]
            worker = W.Worker(config)
            result = worker.ollama_chat({"id": 0}, 0, [{"role": "user", "content": "x"}], [],
                                       model="tiny", timeout_seconds=.4)
            self.assertEqual(len(a), 1)
            self.assertEqual(len(b), 1)
            self.assertEqual(result["_metrics"]["endpoint_id"], worker.local_inference.endpoints[1]["id"])


class QualificationEvidenceTests(unittest.TestCase):
    def test_cli_never_promotes_a_timeout_or_skipped_model_and_require_model_exits(self):
        with tempfile.TemporaryDirectory() as td, chunked_server() as (url, calls):
            config = cfg(url, td)
            config["analysis_model"] = "other"
            config["local_runtime"]["max_call_seconds"] = .12
            config_path, route_path = Path(td) / "config.json", Path(td) / "route.json"
            config_path.write_text(json.dumps(config))
            argv = ["agent_kernel_smoke", "--config", str(config_path), "--route-file", str(route_path),
                    "--budget-seconds", "5", "--require-model"]
            with patch.object(sys, "argv", argv), contextlib.redirect_stdout(io.StringIO()), self.assertRaises(SystemExit) as caught:
                S.main()
            self.assertEqual(caught.exception.code, 2)
            route = json.loads(route_path.read_text())
            self.assertEqual(route["mode"], "deterministic_decision_fallback")
            self.assertEqual(route["analyst_model"], "")
            self.assertEqual([x["status"] for x in route["evaluation"]], ["FAIL", "SKIPPED"])
            self.assertEqual(len(calls), 1)

    def test_cli_can_qualify_next_model_after_bounded_capacity_wait(self):
        with tempfile.TemporaryDirectory() as td, chunked_server("recover") as (url, calls):
            config = cfg(url, td)
            config["analysis_model"] = "other"
            config["local_runtime"].update(max_call_seconds=.12, endpoint_cooldown_seconds=5)
            config_path, route_path = Path(td) / "config.json", Path(td) / "route.json"
            config_path.write_text(json.dumps(config))
            argv = ["agent_kernel_smoke", "--config", str(config_path), "--route-file", str(route_path),
                    "--budget-seconds", "10", "--require-model"]
            started = time.monotonic()
            with patch.object(sys, "argv", argv), contextlib.redirect_stdout(io.StringIO()):
                S.main()
            elapsed = time.monotonic() - started
            route = json.loads(route_path.read_text())
            self.assertEqual(route["analyst_model"], "other")
            self.assertEqual([x["status"] for x in route["evaluation"]], ["FAIL", "PASS", "PASS", "PASS"])
            self.assertEqual(len(calls), 4)
            self.assertGreaterEqual(elapsed, 5)
            self.assertLess(elapsed, 9)

    def test_first_request_and_subsequent_budgets_are_bounded_and_semantic(self):
        limits = []
        class Model:
            def ollama_chat(self, job, round_no, messages, tools, **kw):
                limits.append(kw["timeout_seconds"])
                finding = ("F3", "F1", "F0")[len(limits) - 1]
                return {"message": {"content": json.dumps({"finding_ids": [finding], "action_id": "A0"})},
                        "_metrics": {"model": "tiny", "load_duration": 1_000_000_000}}
        results = S.qualify(Model(), "tiny", time.monotonic() + 200)
        self.assertEqual(limits, [K.DECISION_CALL_SECONDS, 30, 30])
        self.assertTrue(all(x["status"] == "PASS" for x in results))
        self.assertEqual(results[0]["load_duration"], 1_000_000_000)

    def test_partial_passes_and_safe_failure_details_survive_second_case_failure(self):
        evidence = []
        class Model:
            def ollama_chat(self, *args, **kw):
                if evidence:
                    raise RuntimeError("secret provider text")
                return {"message": {"content": '{"finding_ids":["F3"],"action_id":"A0"}'}}
        with self.assertRaises(RuntimeError):
            S.qualify(Model(), "tiny", time.monotonic() + 200, evidence=evidence)
        self.assertEqual([x["status"] for x in evidence], ["PASS", "FAIL"])
        self.assertEqual(evidence[1]["capability"], "trade.risk")
        self.assertNotIn("secret", json.dumps(evidence))

    def test_runtime_report_includes_only_allowed_daemon_and_residency_facts(self):
        with tempfile.TemporaryDirectory() as td, chunked_server("ok") as (url, _):
            report = RD.report(cfg(url, td))
        row = report["endpoints"][0]
        self.assertEqual(row["ollama_version"], "0.0.0-fixture")
        self.assertEqual(row["loaded_models"][0]["vram_bytes"], 0)
        self.assertNotIn("secret", json.dumps(report))
        self.assertNotIn(url, json.dumps(report))


if __name__ == "__main__":
    unittest.main()
