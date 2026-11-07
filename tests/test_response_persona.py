import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENGINE = Path("/app") if Path("/app/business_skills.py").is_file() else ROOT / "engine"
sys.path.insert(0, str(ENGINE))
import business_skills as BS  # noqa: E402
import response_persona as RP  # noqa: E402


class ResponsePersonaTests(unittest.TestCase):
    def test_premium_tier_from_engine_selection(self):
        job = {"context": {"engine_selection": {"provider": "grok", "model": "grok-4.7"}}}
        self.assertEqual(RP.delivery_tier(job, object()), "premium")

    def test_weak_ollama_is_deterministic_only(self):
        job = {"context": {"engine_selection": {"provider": "ollama", "model": "qwen3.5:0.8b"}}}
        self.assertEqual(RP.delivery_tier(job, object()), "deterministic_only")

    def test_deterministic_only_skips_model_synthesis(self):
        class W:
            def model_for(self, role):
                raise AssertionError("model_for should not run for deterministic_only")

            def trace(self, *a, **k):
                pass

        job = {"id": 1, "context": {"engine_selection": {"provider": "ollama", "model": "qwen3.5:0.8b"}}}
        text, meta = BS.grounded_synthesis(
            W(), job, "گزارش بده", "• فروش: 100 ریال", {}, "financial-report",
        )
        self.assertEqual(meta.get("synthesis"), "deterministic_only")
        self.assertIn("مسیر ابزارهای ERP", text)
        self.assertIn("100 ریال", text)

    def test_premium_render_uses_paragraph_lead(self):
        out = RP.render_grounded_hybrid("• x", "جمع‌بندی روان", ["نکته"], [], [], "premium")
        self.assertIn("بر اساس دادهٔ تازه ERP", out)
        self.assertIn("جمع‌بندی روان", out)
        self.assertNotIn("تحلیل هوشمند روی شواهد ERP", out)


if __name__ == "__main__":
    unittest.main()
