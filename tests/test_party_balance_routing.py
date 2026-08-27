from __future__ import annotations

import sys
import threading
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENGINE = Path('/app') if Path('/app/read_guard.py').is_file() else ROOT / 'engine'
sys.path.insert(0, str(ENGINE))

import read_guard as RG  # noqa: E402


class DummyWorker:
    def __init__(self):
        self.calls = []
        self.progress_lock = threading.Lock()
        self.current_trace = []

    def tool(self, job, name, arguments, call_id):
        self.calls.append((name, arguments))
        if name == 'search_parties':
            return {'rows': [{'id': 3, 'code': 'CUS-003', 'name': 'کارخانه بهین بسته‌بندی'}]}
        if name == 'party_ledger':
            return {
                'balance': 727_100_000,
                'rows': [
                    {'voucher_date': '1405/05/20', 'voucher_no': 'V-1', 'debit': 10, 'credit': 0, 'running_balance': 10},
                ],
            }
        raise AssertionError(name)

    def trace(self, *args, **kwargs):
        return None


class NamedPartyBalanceRoutingTests(unittest.TestCase):
    def test_exact_live_prompt_routes_deterministically(self):
        prompt = 'مانده کارخانه بهین بسته‌بندی را بررسی کن و فقط وضعیت فعلی را بگو.'
        plan = RG.route(prompt)
        self.assertIsNotNone(plan)
        self.assertEqual(plan['intent'], 'party_ledger')
        self.assertEqual(plan['query'], 'کارخانه بهین بسته‌بندی')
        self.assertTrue(plan['summary_only'])

    def test_common_unquoted_variants_extract_party(self):
        cases = {
            'مانده مشتری کارخانه بهین بسته‌بندی چقدره؟': 'کارخانه بهین بسته‌بندی',
            'مانده حساب کارخانه بهین بسته‌بندی را بگو': 'کارخانه بهین بسته‌بندی',
            'گردش حساب کارخانه بهین بسته‌بندی را نشان بده': 'کارخانه بهین بسته‌بندی',
            'مانده طرف حساب پارس تجارت چیست؟': 'پارس تجارت',
            'مانده تامین کننده آریا چقدر است؟': 'آریا',
        }
        for prompt, expected in cases.items():
            with self.subTest(prompt=prompt):
                plan = RG.route(prompt)
                self.assertIsNotNone(plan)
                self.assertEqual(plan['intent'], 'party_ledger')
                self.assertEqual(plan['query'], expected)

    def test_summary_only_executes_real_party_tools_without_history_noise(self):
        prompt = 'مانده کارخانه بهین بسته‌بندی را بررسی کن و فقط وضعیت فعلی را بگو.'
        plan = RG.route(prompt)
        worker = DummyWorker()
        text, meta = RG.execute_one(worker, {'id': 55, 'prompt': prompt}, plan)
        self.assertEqual([c[0] for c in worker.calls], ['search_parties', 'party_ledger'])
        self.assertIn('کارخانه بهین بسته‌بندی', text)
        self.assertIn('727,100,000 ریال', text)
        self.assertNotIn('• 1405/05/20', text)
        self.assertEqual(meta['intent'], 'party_ledger')


if __name__ == '__main__':
    unittest.main()
