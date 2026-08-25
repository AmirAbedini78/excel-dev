from __future__ import annotations

import json
import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


class ServerBoundaryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.repository = (ROOT / "app/Core/AiRepository.php").read_text(encoding="utf-8")
        cls.registry = (ROOT / "app/Core/AiToolRegistry.php").read_text(encoding="utf-8")
        cls.schema = (ROOT / "app/Core/AiSchema.php").read_text(encoding="utf-8")
        cls.api = (ROOT / "ai_api.php").read_text(encoding="utf-8")
        cls.module = (ROOT / "app/Modules/AiModule.php").read_text(encoding="utf-8")
        cls.live_asset = (ROOT / "assets/ai-live.js").read_text(encoding="utf-8")

    def test_proposal_tools_are_explicitly_risk_classified(self):
        self.assertRegex(self.registry, r"'create_sales_invoice_draft','mode'=>'proposal','risk'=>'medium'")
        self.assertRegex(self.registry, r"'create_voucher_draft','mode'=>'proposal','risk'=>'high'")
        self.assertIn("requires_approval,status,proposed_at", self.registry)
        self.assertIn("1,'proposed',NOW()", self.registry)

    def test_approval_is_permission_checked_and_transactional(self):
        self.assertIn("Tenant::requirePermission('ai.actions.approve')", self.repository)
        self.assertIn("status='proposed' FOR UPDATE", self.repository)
        self.assertIn("$pdo->beginTransaction()", self.repository)
        self.assertIn("$pdo->rollBack()", self.repository)

    def test_proposal_idempotency_is_atomic(self):
        self.assertIn("uniq_ai_action_idempotency", self.schema)
        self.assertIn("ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", self.registry)
        self.assertNotIn("SELECT id FROM ai_action_proposals WHERE workspace_id=? AND job_id=? AND idempotency_key=?", self.registry)

    def test_terminal_retry_is_idempotent_and_bounded(self):
        self.assertIn("lockJobForTerminalWrite", self.repository)
        self.assertIn("lease_retry_window_expired", self.repository)
        self.assertIn("time()-86400", self.repository)
        self.assertIn("'replayed'=>$replayed", self.api)
        terminal_section = self.repository.split("private static function lockJobForTerminalWrite", 1)[1]
        self.assertNotIn("lease_hash=NULL", terminal_section)

    def test_api_has_request_correlation_and_error_redaction(self):
        self.assertIn("X-Request-ID", self.api)
        self.assertIn("ai_request_id", self.api)
        self.assertIn("SQLSTATE", self.api)
        self.assertIn("server_error", self.api)

    def test_worker_has_no_operational_database_driver(self):
        for path in (ROOT / "engine").glob("*.py"):
            text = path.read_text(encoding="utf-8")
            self.assertNotRegex(text, r"(?i)\b(?:pymysql|mysql\.connector|psycopg|DB_PASSWORD)\b", path.name)

    def test_live_payload_exposes_redacted_commercial_metadata(self):
        self.assertIn("'commercial_hardening'=>(array)($meta['commercial_hardening']??[])", self.repository)

    def test_live_payload_exposes_only_bounded_attempt_observability(self):
        self.assertIn("public static function safeToolNames(mixed $value): array", self.repository)
        self.assertIn("preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$name)", self.repository)
        self.assertIn("if(count($safe)>=32)break", self.repository)
        self.assertIn("'tools_used'=>self::safeToolNames($meta['tools_used']??[])", self.repository)
        self.assertIn("'tools_attempted'=>self::safeToolNames($meta['tools_attempted']??[])", self.repository)
        self.assertIn("public static function safeModelMetrics(mixed $value): array", self.repository)
        self.assertIn("['first_chunk_seconds','elapsed_seconds','prompt_eval_count','prompt_eval_duration','eval_count','eval_duration']", self.repository)
        self.assertIn("self::safeModelMetrics($meta['attempted_metrics']??[])", self.repository)
        self.assertIn("AiRepository::safeModelMetrics($meta['attempted_metrics']??[])", self.module)

    def test_live_renderer_has_v9302_metadata_contract(self):
        self.assertIn('assets/ai-live.js?v=9.3.0.2', self.module)
        self.assertNotIn('assets/ai-live.js?v=8.0.0', self.module)
        self.assertIn("hardeningText(job?.commercial_hardening)", self.live_asset)
        self.assertIn("toolText(job)", self.live_asset)
        self.assertIn("safeToolNames(job?.tools_attempted)", self.live_asset)
        self.assertIn("AiRepository::safeToolNames($meta['tools_attempted']??[])", self.module)
        self.assertNotIn("tool_arguments", self.live_asset)
        self.assertNotIn("tool_results", self.live_asset)
        self.assertIn('forecast_risk_anomaly: "پیش‌بینی، ریسک و ناهنجاری"', self.live_asset)
        self.assertIn('commercial_hardening_complete: "تأیید قرارداد تجاری"', self.live_asset)
        self.assertIn('within_budget: "پاس"', self.live_asset)
        self.assertIn('exceeded: "بیش‌ازحد"', self.live_asset)

    def test_live_stage_labels_cover_every_worker_trace_stage(self):
        trace_pattern = re.compile(r'(?:self|worker)\.trace\(\s*job\s*,\s*"([a-z0-9_]+)"')
        stages = set()
        for path in (ROOT / "engine").glob("*.py"):
            stages.update(trace_pattern.findall(path.read_text(encoding="utf-8")))
        missing = sorted(stage for stage in stages if f'{stage}: "' not in self.live_asset)
        self.assertEqual(missing, [])

    def test_release_gate_requires_javascript_in_ci(self):
        workflow = (ROOT / ".github/workflows/commercial-mvp-gate.yml").read_text(encoding="utf-8")
        gate = (ROOT / "scripts/release_gate.py").read_text(encoding="utf-8")
        self.assertIn("actions/setup-node@v4", workflow)
        self.assertIn("--require-php --require-node", workflow)
        self.assertIn('node, "--check"', gate)

    def test_release_gate_runs_php_observability_behavior(self):
        gate = (ROOT / "scripts/release_gate.py").read_text(encoding="utf-8")
        behavior = ROOT / "tests/php_live_observability_test.php"
        self.assertTrue(behavior.is_file())
        self.assertIn('run([php, "-n", str(behavior_test)], root)', gate)
        self.assertIn("PHP_LIVE_ATTEMPT_OBSERVABILITY: PASS", behavior.read_text(encoding="utf-8"))


class ReleaseArtifactTests(unittest.TestCase):
    def test_config_examples_have_all_latency_budget_classes(self):
        expected = {"deterministic", "read_model", "action", "deep", "fallback"}
        for name in ("config.example.json", "config.docker.example.json"):
            data = json.loads((ROOT / "engine" / name).read_text(encoding="utf-8"))
            self.assertEqual(set(data["latency_budgets_seconds"]), expected)

    def test_worker_installs_commercial_guard_last(self):
        worker = (ROOT / "engine/worker.py").read_text(encoding="utf-8")
        proactive = worker.index("_install_proactive_agent(Worker)")
        hardening = worker.index("_install_commercial_hardening(Worker)")
        self.assertGreater(hardening, proactive)
        self.assertIn("_validate_runtime_config(cfg)", worker)
        self.assertIn('str(d.get("mode") or "read") != "proposal"', worker)

    def test_docker_candidate_compiles_runtime(self):
        dockerfile = (ROOT / "engine/Dockerfile").read_text(encoding="utf-8")
        self.assertIn("commercial_hardening.py", dockerfile)
        self.assertIn("python -m compileall -q /app", dockerfile)

    def test_no_committed_secret_pattern(self):
        token = re.compile(r"aiw_[A-Fa-f0-9]{24,}")
        private_key = re.compile(r"BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY")
        allowed_suffixes = {".py", ".php", ".js", ".json", ".md", ".yml", ".yaml", ".ps1"}
        for path in ROOT.rglob("*"):
            if not path.is_file() or path.suffix.lower() not in allowed_suffixes:
                continue
            if any(part in {".git", "__pycache__", "sample_import"} for part in path.parts):
                continue
            text = path.read_text(encoding="utf-8", errors="ignore")
            self.assertIsNone(token.search(text), str(path.relative_to(ROOT)))
            self.assertIsNone(private_key.search(text), str(path.relative_to(ROOT)))


if __name__ == "__main__":
    unittest.main()
