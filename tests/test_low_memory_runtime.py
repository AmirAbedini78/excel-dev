from __future__ import annotations
import contextlib
import http.server
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
import provider_gateway as P
import worker as W
import agent_intelligence_kernel as K
import decision_brief as D
import agent_kernel_smoke as S
from rag import RagIndex
from test_cycle12_3_agent_intelligence_kernel import FakeWorker, READ_TOOLS, job, supplier_rows


@contextlib.contextmanager
def ollama_server(kind="ok"):
    requests = []
    class Handler(http.server.BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"
        def log_message(self, *args):
            pass
        def do_GET(self):
            if kind == "inventory_trickle":
                self.send_response(200)
                self.send_header("Connection", "close")
                self.end_headers()
                try:
                    for _ in range(80):
                        self.wfile.write(b" ")
                        self.wfile.flush()
                        time.sleep(.01)
                except OSError:
                    pass
                return
            data = {"models": [{"name": "tiny", "size": 1024, "digest": "digest-a"}]} if self.path == "/api/tags" else {"models": []}
            raw = json.dumps(data).encode()
            self.send_response(200)
            self.send_header("Content-Length", str(len(raw)))
            self.end_headers()
            self.wfile.write(raw)
        def do_POST(self):
            body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
            requests.append(body)
            self.send_response(500 if kind == "oom" else 200)
            self.send_header("Connection", "close")
            self.end_headers()
            try:
                if kind == "oom":
                    self.wfile.write(b'{"error":"model requires more system memory private-secret"}')
                    return
                if kind == "trickle":
                    for _ in range(80):
                        self.wfile.write(b" ")
                        self.wfile.flush()
                        time.sleep(.01)
                    return
                if kind == "stream_error":
                    self.wfile.write(b'{"error":"out of memory private-secret"}\n')
                    return
                if kind == "big":
                    self.wfile.write(b"x" * 4096 + b"\n")
                    return
                content = '{"finding_ids":["F0"],"action_id":"A0"}'
                if kind == "semantic":
                    prompt = json.loads(body["messages"][1]["content"])
                    top = max(prompt["findings"], key=lambda x: x["severity"])["id"]
                    content = json.dumps({"finding_ids": [top], "action_id": "A0"})
                chunk = {"model": body["model"], "message": {"content": content}}
                if kind != "incomplete":
                    chunk.update(done=True, done_reason="length" if kind == "length" else "stop")
                self.wfile.write(json.dumps(chunk).encode() + b"\n")
            except (OSError, BrokenPipeError):
                pass
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    server.daemon_threads = True
    thread = threading.Thread(target=server.serve_forever, kwargs={"poll_interval": .01}, daemon=True)
    thread.start()
    try:
        # Transport fixtures must not depend on free RAM while the target's real
        # Ollama may still be serving a job. Admission behavior has separate tests.
        with patch.object(L, "_host_available_mb", return_value=16384):
            yield f"http://127.0.0.1:{server.server_port}", requests
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=1)


def cfg(url, directory):
    return {"ollama_url": url, "chat_model": "tiny", "agent_model": "tiny", "analysis_model": "tiny",
            "provider_strategy": "local_only", "server_url": "https://example.invalid/ai_api.php",
            "worker_token": "unused-offline", "local_runtime": {"state_dir": directory}}


class TransportTests(unittest.TestCase):
    def call(self, url, directory, **kwargs):
        runtime = L.LocalInference(cfg(url, directory))
        return runtime.chat({"id": 0}, 0, [{"role": "user", "content": "hello"}], [], trace=lambda *x: None,
                            model="tiny", num_ctx=1024, num_predict=128, **kwargs)

    def test_actual_http_options_and_model_are_preserved(self):
        with tempfile.TemporaryDirectory() as td, ollama_server() as (url, calls):
            response = self.call(url, td)
        self.assertEqual(response["_metrics"]["model"], "tiny")
        self.assertEqual(calls[0]["options"]["num_ctx"], 1024)
        self.assertEqual(calls[0]["options"]["num_batch"], 32)
        self.assertEqual(calls[0]["keep_alive"], "5m")

    def test_truncation_stream_errors_and_eof_never_count_as_success(self):
        for kind, code in [("length", "truncated"), ("incomplete", "incomplete_stream"),
                           ("oom", "memory_pressure"), ("stream_error", "memory_pressure")]:
            with self.subTest(kind=kind), tempfile.TemporaryDirectory() as td, ollama_server(kind) as (url, calls):
                with self.assertRaises(L.InferenceError) as cm:
                    self.call(url, td)
                self.assertEqual(cm.exception.code, code)
                self.assertNotIn("private-secret", str(cm.exception))

    def test_wall_deadline_stops_continuous_partial_bytes(self):
        with tempfile.TemporaryDirectory() as td, ollama_server("trickle") as (url, _):
            started = time.monotonic()
            with self.assertRaises(L.InferenceError) as cm:
                self.call(url, td, timeout_seconds=.12)
            elapsed = time.monotonic() - started
        self.assertEqual(cm.exception.code, "deadline")
        self.assertLess(elapsed, .5)

    def test_response_budget_is_checked_before_json_parse(self):
        with ollama_server("big") as (url, _):
            with self.assertRaises(L.InferenceError) as cm:
                L.stream_chat(url, {"model": "tiny"}, time.monotonic() + 2, max_bytes=1024)
        self.assertEqual(cm.exception.code, "response_budget")

    def test_inventory_has_wall_timeout_for_continuous_partial_bytes(self):
        with ollama_server("inventory_trickle") as (url, _):
            started = time.monotonic()
            with self.assertRaises((L.InferenceError, OSError, http.client.HTTPException)):
                L._json_get(url, "/api/tags", timeout=.12)
            self.assertLess(time.monotonic() - started, .5)

    def test_existing_config_context_and_output_preferences_are_bounded(self):
        with tempfile.TemporaryDirectory() as td, ollama_server() as (url, calls):
            config = cfg(url, td)
            config.update(num_ctx=1536, num_predict=96, fast_num_ctx=1024, fast_num_predict=64)
            runtime = L.LocalInference(config)
            for fast in (False, True):
                runtime.chat({"id": 0}, 0, [{"role": "user", "content": "x"}], [],
                             trace=lambda *x: None, fast=fast)
            self.assertEqual([(x["options"]["num_ctx"], x["options"]["num_predict"]) for x in calls],
                             [(1536, 96), (1024, 64)])

    def test_oversized_persian_prompt_never_reaches_provider(self):
        with tempfile.TemporaryDirectory() as td, ollama_server() as (url, calls):
            runtime = L.LocalInference(cfg(url, td))
            with self.assertRaises(L.InferenceError) as cm:
                runtime.chat({"id": 1}, 0, [{"role": "user", "content": "موجودی " * 1000}], [],
                             model="tiny", trace=lambda *x: None)
        self.assertEqual(cm.exception.code, "context_budget")
        self.assertEqual(calls, [])

    def test_explicit_peer_receives_same_model_after_primary_oom(self):
        with tempfile.TemporaryDirectory() as td, ollama_server("oom") as (first, calls1), ollama_server() as (second, calls2):
            config = cfg(first, td)
            config["ollama_fallback_endpoints"] = [{"url": second}]
            runtime = L.LocalInference(config)
            result = runtime.chat({"id": 1}, 0, [{"role": "user", "content": "x"}], [], model="tiny", trace=lambda *x: None)
            self.assertEqual(result["_metrics"]["model"], "tiny")
            runtime.chat({"id": 2}, 0, [{"role": "user", "content": "x"}], [], model="tiny", trace=lambda *x: None)
            self.assertEqual(len(calls1), 1)  # OOM circuit skips repeated load on same node.
            self.assertEqual(len(calls2), 2)
            self.assertEqual(calls1[0]["messages"], calls2[0]["messages"])

    def test_declared_memory_budget_blocks_load_before_inference(self):
        with tempfile.TemporaryDirectory() as td, ollama_server() as (url, calls):
            config = cfg(url, td)
            config["local_runtime"]["memory_budget_mb"] = 100
            runtime = L.LocalInference(config)
            runtime.models(refresh=True)
            with self.assertRaises(L.InferenceError) as cm:
                runtime.chat({"id": 1}, 0, [{"role": "user", "content": "x"}], [], model="tiny", trace=lambda *x: None)
        self.assertEqual(cm.exception.code, "memory_admission")
        self.assertFalse(calls)

    def test_remote_admission_never_uses_worker_host_memory(self):
        runtime = L.LocalInference({"ollama_url": "http://trusted-peer:11434"})
        ep = runtime.endpoints[0]
        runtime.inventory[ep["id"]] = [{"name": "tiny", "size": 1024}]
        with patch.object(L, "_host_available_mb", side_effect=AssertionError("wrong host")):
            runtime._admit(ep, "tiny", 1024)

    def test_two_process_handles_cannot_enter_same_endpoint_lock(self):
        with tempfile.TemporaryDirectory() as td:
            with L.endpoint_lock(Path(td), "endpoint", time.monotonic() + 1):
                with self.assertRaises(L.InferenceError):
                    with L.endpoint_lock(Path(td), "endpoint", time.monotonic() + .03):
                        self.fail("second lock entered")


class GatewayRegression(unittest.TestCase):
    def test_actual_worker_honors_explicit_model_after_stale_role_hint(self):
        with tempfile.TemporaryDirectory() as td, ollama_server() as (url, calls):
            config = cfg(url, td)
            config["analysis_model"] = "analyst"
            w = W.Worker(config)
            w._local_models += ["analyst"]
            w.local_inference.inventory[w.local_inference.endpoints[0]["id"]].append({"name": "analyst", "size": 1024})
            w.model_for("agent")  # Original gateway overwrote the next explicit model.
            w.ollama_chat({"id": 0}, 0, [{"role": "user", "content": "x"}], [], model="analyst")
            self.assertEqual(calls[0]["model"], "analyst")

    def test_local_only_cannot_be_changed_by_cloud_model_name(self):
        class Dummy:
            _provider_strategy = "local_only"
            _local_role_models = {"analysis": "local"}
            _cloud_role_models = {"analysis": "cloud"}
            _local_available = _cloud_available = True
        self.assertEqual(P._provider_order(Dummy(), "analysis", "cloud"), ["local"])

    def test_actual_worker_and_production_preflight_share_semantic_contract(self):
        with tempfile.TemporaryDirectory() as td, ollama_server("semantic") as (url, calls):
            w = W.Worker(cfg(url, td))
            checks = S.qualify(w, "tiny", time.monotonic() + 5)
            self.assertEqual(len(checks), 3)
            self.assertTrue(all(x["prompt_token_upper_bound"] + 320 <= 2048 for x in checks))
            self.assertEqual(len(calls), 3)


class BusinessRegression(unittest.TestCase):
    def test_reported_portfolio_tie_keeps_each_supplier_and_known_risk(self):
        data = supplier_rows((11, 22, 33, 44))
        for r in data["rows"]:
            r.update(open_qty=100, receipt_fill_rate=0)
        w = FakeWorker(analyst={"bad": "json"}, tool_results={"supplier_performance_summary": data})
        w.cfg = {}
        text, meta = K.run_kernel(w, job("تامین‌کننده‌های ما را از نظر عملکرد خرید بررسی و مقایسه کن"), READ_TOOLS, "supplier.performance")
        for r in data["rows"]:
            self.assertIn(r["name"], text)
        self.assertLess(text.index("تامین برق ایرانیان"), text.index("ابزار دقیق سپهر"))
        self.assertIn("به‌تنهایی", text)
        self.assertIn("دیرکرد از این عدد قابل استنتاج نیست", text)
        self.assertEqual(meta["planner_calls"], 0)

    def test_model_cannot_hide_critical_supplier_or_invent_quality_claim(self):
        brief = D.build({"capability": "supplier.performance", "supplier_rows": supplier_rows()["rows"]})
        selected = D.validate({"finding_ids": ["F0"], "action_id": "A1"}, brief)
        text = D.render(brief, selected)
        self.assertLess(text.index("تامین برق ایرانیان"), text.index("ابزار دقیق سپهر"))
        with self.assertRaises(ValueError):
            D.validate({"finding_ids": ["F0"], "action_id": "A0", "why": "guaranteed best"}, brief)

    def test_explicit_trade_context_does_not_analyze_another_case(self):
        risk = {"rows": [{"trade_case_id": 2, "case_no": "WRONG", "risk_score": 9, "risk_level": "high"}]}
        w = FakeWorker(tool_results={"trade_risk_summary": risk})
        w.cfg = {}
        text, _ = K.run_kernel(w, job("ریسک این پرونده بازرگانی", attached=[{"type": "trade.case", "id": 1}]), READ_TOOLS, "trade.risk")
        self.assertNotIn("WRONG", text)
        self.assertIn("پرونده انتخاب‌شده", text)

    def test_optional_read_failure_preserves_primary_comparison(self):
        def fail(_):
            raise RuntimeError("read unavailable")
        w = FakeWorker(tool_results={"supplier_performance_summary": supplier_rows(), "search_trade_cases": fail})
        w.cfg = {}
        text, _ = K.run_kernel(w, job("کدام تامین کننده بهتر است؟"), READ_TOOLS, "supplier.performance")
        self.assertIn("ابزار دقیق سپهر", text)
        self.assertIn("خوانش تکمیلی", text)

    def test_supported_persian_period_is_not_replaced_with_six_months(self):
        w = FakeWorker(tool_results={"supplier_performance_summary": supplier_rows()})
        w.cfg = {}
        K.run_kernel(w, job("عملکرد تامین کننده ها در سه ماه گذشته را مقایسه کن"), READ_TOOLS, "supplier.performance")
        self.assertEqual(w.tool_calls[0][1]["months"], 3)

    def test_unsupported_period_does_not_read_another_period(self):
        w = FakeWorker()
        text, meta = K.run_kernel(w, job("تامین کننده ها را در سال گذشته مقایسه کن"), READ_TOOLS, "supplier.performance")
        self.assertEqual(meta["block_reason"], "supplier_period_unsupported")
        self.assertFalse(w.tool_calls)

    def test_current_trade_case_requires_disambiguation(self):
        w = FakeWorker(tool_results={"trade_risk_summary": {"rows": [
            {"trade_case_id": 1, "risk_score": 9, "case_no": "T1"},
            {"trade_case_id": 2, "risk_score": 1, "case_no": "T2"}]}})
        _, meta = K.run_kernel(w, job("ریسک پرونده بازرگانی فعلی را بررسی کن"), READ_TOOLS, "trade.risk")
        self.assertEqual(meta["block_reason"], "trade_context_ambiguous")

    def test_empty_rows_do_not_create_metrics_or_best_supplier(self):
        text = D.render(D.build({"capability": "supplier.performance", "supplier_rows": []}))
        self.assertIn("خرید قطعی قابل مقایسه‌ای", text)
        self.assertNotIn("٪", text)

    def test_degraded_route_expires_and_runtime_fingerprint_invalidates_it(self):
        w = FakeWorker()
        with tempfile.TemporaryDirectory() as td:
            path = Path(td) / "route.json"
            data = {"kernel_version": K.PATCH_VERSION, "analyst_contract": K.ANALYST_CONTRACT,
                    "configured_decision_models": K._configured_decision_models(w),
                    "runtime_fingerprint": "offline", "expires_epoch": time.time() - 1,
                    "mode": "deterministic_decision_fallback"}
            path.write_text(json.dumps(data))
            self.assertTrue(K._decision_models(w, path))
            data.update(expires_epoch=time.time() + 60, runtime_fingerprint="old-runtime")
            path.write_text(json.dumps(data))
            self.assertTrue(K._decision_models(w, path))


class RetrievalTests(unittest.TestCase):
    def test_persian_arabic_letter_variants_match_with_fts_and_fallback(self):
        with tempfile.TemporaryDirectory() as td:
            p = Path(td) / "policy.md"
            p.write_text("شرايط تحويل كالاي سفارش داده شده", encoding="utf-8")
            idx = RagIndex(Path(td) / "r.db", "", "", scope="1:1")
            try:
                idx.index_path(p)
                for enabled in (idx.fts, False):
                    idx.fts = enabled
                    self.assertEqual(len(idx.search("شرایط کالای")), 1)
                p.write_text("راهنمای جدید", encoding="utf-8")
                idx.index_path(p)
                self.assertEqual(idx.search("کالای"), [])
            finally:
                idx.close()

    def test_company_isolation_incremental_index_and_no_embedding_load(self):
        with tempfile.TemporaryDirectory() as td, patch("rag.embed", side_effect=AssertionError("interactive embedding")):
            document = Path(td) / "policy.md"
            document.write_text("supplier delivery policy alpha")
            one = RagIndex(Path(td) / "r.db", "", "", scope="1:1")
            two = RagIndex(Path(td) / "r.db", "", "", scope="1:2")
            try:
                self.assertEqual(one.index_path(document), 1)
                self.assertEqual(one.index_path(document), 0)
                self.assertEqual(len(one.search("supplier")), 1)
                self.assertEqual(two.search("supplier"), [])
                self.assertLessEqual(sum(len(r["text"]) for r in one.search("supplier", max_chars=12)), 12)
            finally:
                one.close()
                two.close()

    def test_failed_reindex_keeps_last_good_document(self):
        with tempfile.TemporaryDirectory() as td:
            p = Path(td) / "policy.md"
            p.write_text("original supplier policy")
            idx = RagIndex(Path(td) / "r.db", "", "embed", scope="1:1")
            try:
                idx.index_path(p)
                p.write_text("replacement supplier policy")
                with patch("rag.embed", side_effect=RuntimeError("oom")), self.assertRaises(RuntimeError):
                    idx.index_path(p, use_embeddings=True)
                self.assertIn("original", idx.search("supplier")[0]["text"])
            finally:
                idx.close()


if __name__ == "__main__":
    unittest.main()
