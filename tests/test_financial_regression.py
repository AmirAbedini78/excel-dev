from __future__ import annotations

import sys
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "engine"))

import action_orchestrator as AO  # noqa: E402
import financial_intelligence as FI  # noqa: E402
import forecast_risk as FR  # noqa: E402
import proactive_agent as PA  # noqa: E402


class RoutingRegressionTests(unittest.TestCase):
    def test_proactive_is_recommendation_only(self):
        self.assertTrue(PA.is_proactive_candidate(
            "خودت وضعیت حسابداری و مالی شرکت را بررسی کن و اقدامات بعدی را بگو"
        ))
        self.assertFalse(PA.is_proactive_candidate(
            "خودت وضعیت مالی را بررسی کن و سند پرداخت ثبت کن"
        ))

    def test_intelligence_write_prompt_is_not_read_route(self):
        self.assertTrue(FI.is_intelligence_candidate("وضعیت مالی شرکت و ریسک های مالی را تحلیل کن"))
        self.assertFalse(FI.is_intelligence_candidate("وضعیت مالی را تحلیل کن و یک فاکتور بساز"))

    def test_fixed_predictive_and_proactive_plans_are_read_only(self):
        forecast_plan = FR.collect_tool_plan()
        proactive_plan = FR.collect_tool_plan()
        self.assertEqual(len(forecast_plan), 9)
        self.assertEqual(len(proactive_plan), 9)
        forbidden = {"create_sales_invoice_draft", "create_voucher_draft"}
        self.assertFalse({row[0] for row in forecast_plan}.intersection(forbidden))


class ForecastRegressionTests(unittest.TestCase):
    def test_incomplete_current_month_is_excluded(self):
        result = {"groups": [
            {"key": "1405/03", "document_count": 2, "net_total": 100},
            {"key": "1405/04", "document_count": 2, "net_total": 200},
            {"key": "1405/05", "document_count": 2, "net_total": 300},
            {"key": "1405/06", "document_count": 1, "net_total": 999999},
        ]}
        series = FR._month_series(result, "1405/06/02")
        self.assertEqual([x["key"] for x in series], ["1405/03", "1405/04", "1405/05"])

    def test_linear_forecast_is_deterministic_and_bounded(self):
        series = [
            {"key": "1405/03", "index": FR._month_index("1405/03"), "net_total": 100.0},
            {"key": "1405/04", "index": FR._month_index("1405/04"), "net_total": 200.0},
            {"key": "1405/05", "index": FR._month_index("1405/05"), "net_total": 300.0},
        ]
        out = FR._linear_forecast(series, "1405/06")
        self.assertTrue(out["available"])
        self.assertAlmostEqual(out["forecast"], 400.0)
        self.assertLessEqual(out["range_low"], out["forecast"])
        self.assertGreaterEqual(out["range_high"], out["forecast"])
        self.assertEqual(out["range_semantics"], "approximate_error_band_not_confidence_interval")

    def test_robust_anomaly_requires_four_complete_months(self):
        out = FR._robust_latest_anomaly([
            {"key": "1405/03", "net_total": 100},
            {"key": "1405/04", "net_total": 100},
            {"key": "1405/05", "net_total": 100},
        ])
        self.assertFalse(out["available"])

    def test_severity_gate_beats_model_order(self):
        findings = [
            {"id": "info", "severity": "info"},
            {"id": "warning", "severity": "warning"},
            {"id": "critical", "severity": "critical"},
        ]
        self.assertEqual(
            FR.canonical_priority(findings, ["info", "warning", "critical"]),
            ["critical", "warning", "info"],
        )


class ActionAndProactiveRegressionTests(unittest.TestCase):
    def test_action_idempotency_key_is_stable_and_input_sensitive(self):
        spec = {"amount_rial": 100_000_000}
        first = AO._stable_call_id(42, spec, 7, 10101, 11001)
        second = AO._stable_call_id(42, dict(spec), 7, 10101, 11001)
        changed = AO._stable_call_id(42, {"amount_rial": 90_000_000}, 7, 10101, 11001)
        self.assertEqual(first, second)
        self.assertNotEqual(first, changed)

    def test_ambiguous_account_resolution_fails_closed(self):
        rows = [
            {"id": 1, "code": "10101", "name": "بانک ملت"},
            {"id": 2, "code": "10102", "name": "بانک پاسارگاد"},
        ]
        row, reason, choices = AO._resolve_unique(rows, "بانک", ("code", "name"))
        self.assertIsNone(row)
        self.assertEqual(reason, "ambiguous")
        self.assertEqual(len(choices), 2)

    def test_proactive_severity_and_impact_gate(self):
        recs = [
            {"id": "info", "severity": "info", "impact_score": 99},
            {"id": "warn-low", "severity": "warning", "impact_score": 50},
            {"id": "warn-high", "severity": "warning", "impact_score": 90},
            {"id": "critical", "severity": "critical", "impact_score": 10},
        ]
        out = PA.canonical_priority(recs, ["info", "warn-low", "critical", "warn-high"])
        self.assertEqual(out, ["critical", "warn-high", "warn-low", "info"])

    def test_receivables_and_payables_ratios_use_grounded_balances(self):
        results = {"trial": {"rows": [
            {"code": "11001", "name": "حساب‌های دریافتنی تجاری", "balance": 300.0},
            {"code": "21001", "name": "حساب‌های پرداختنی تجاری", "balance": -500.0},
        ]}}
        metrics = {
            "sales_series": [{"net_total": 100.0}],
            "purchase_series": [{"net_total": 200.0}],
        }
        by_id = {x["id"]: x for x in PA.build_extra_signals(results, metrics)}
        self.assertEqual(by_id["receivables_burden"]["severity"], "critical")
        self.assertEqual(by_id["payables_burden"]["severity"], "critical")
        self.assertAlmostEqual(by_id["receivables_burden"]["evidence"]["months_equivalent"], 3.0)
        self.assertAlmostEqual(by_id["payables_burden"]["evidence"]["months_equivalent"], 2.5)


if __name__ == "__main__":
    unittest.main()
