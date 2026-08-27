from __future__ import annotations

import sys
import threading
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENGINE = Path('/app') if Path('/app/finance_actions.py').is_file() else ROOT / 'engine'
sys.path.insert(0, str(ENGINE))

import finance_actions as FA  # noqa: E402


class DummyWorker:
    def __init__(self):
        self.calls = []
        self.progress_lock = threading.Lock()
        self.current_trace = []

    def trace(self, *args, **kwargs):
        return None

    def model_for(self, role):
        return 'none'

    def ollama_chat(self, *args, **kwargs):
        raise AssertionError('LLM should not be needed in deterministic tests')

    def tool(self, job, name, arguments, call_id):
        self.calls.append((name, arguments))
        if name == 'search_parties':
            q = arguments['query']
            if 'تامین' in q or 'آریا' in q:
                return {'rows': [{'id': 12, 'code': 'SUP-12', 'name': 'تامین آریا', 'party_type': 'supplier'}]}
            return {'rows': [{'id': 3, 'code': 'CUS-003', 'name': 'کارخانه بهین بسته‌بندی', 'party_type': 'customer'}]}
        if name == 'search_items':
            return {'rows': [{'id': 7, 'code': 'IT-7', 'name': arguments['query'], 'item_type': 'material'}]}
        if name == 'create_purchase_invoice_draft':
            return {'proposal_id': 21, 'status': 'awaiting_human_approval'}
        if name == 'search_cash_accounts':
            return {'rows': [{'id': 5, 'code': 'BANK-1', 'name': 'بانک ملت - جاری', 'bank_name': 'ملت'}]}
        if name == 'create_check':
            return {'proposal_id': 22, 'status': 'awaiting_human_approval'}
        if name == 'check_analytics':
            return {
                'total_count': 1,
                'total_amount': 100000000,
                'rows': [{'check_no': 'CH-100', 'direction': 'receivable', 'due_date_fa': '1405/06/20', 'amount': 100000000, 'party_name': 'کارخانه بهین بسته‌بندی', 'status': 'open'}],
            }
        raise AssertionError(name)


class FinanceActionRoutingTests(unittest.TestCase):
    def test_purchase_prompt_routes(self):
        p = 'برای تامین کننده «تامین آریا» فاکتور خرید بساز\n2 عدد کابل قدرت\nبا قیمت واحد 500000 ریال'
        self.assertTrue(FA.is_purchase_create_request(p))
        spec = FA._purchase_deterministic(p)
        self.assertEqual(spec['party_query'], 'تامین آریا')
        self.assertEqual(spec['lines'][0]['item_query'], 'کابل قدرت')
        self.assertEqual(spec['lines'][0]['quantity'], 2)
        self.assertEqual(spec['lines'][0]['unit_price'], 500000)

    def test_check_prompt_routes_and_parses(self):
        p = 'چک دریافتنی شماره چک CH-100 به مبلغ 100000000 ریال سررسید 1405/06/20 برای مشتری «کارخانه بهین بسته‌بندی» بانک «بانک ملت - جاری» آماده کن'
        self.assertTrue(FA.is_check_create_request(p))
        spec = FA._check_deterministic(p)
        self.assertEqual(spec['direction'], 'receivable')
        self.assertEqual(spec['check_no'], 'CH-100')
        self.assertEqual(spec['amount'], 100000000)
        self.assertEqual(spec['due_date'], '1405/06/20')
        self.assertEqual(spec['party_query'], 'کارخانه بهین بسته‌بندی')
        self.assertEqual(spec['cash_query'], 'بانک ملت - جاری')

    def test_check_read_is_deterministic(self):
        p = 'چک های دریافتنی باز سررسید این هفته را گزارش بده'
        self.assertTrue(FA.is_check_read_request(p))
        args = FA._check_read_args(p)
        self.assertEqual(args['direction'], 'receivable')
        self.assertEqual(args['status'], 'open')
        self.assertEqual(args['due_scope'], 'upcoming_7')


class FinanceActionExecutionTests(unittest.TestCase):
    def test_purchase_creates_proposal_only(self):
        p = 'برای تامین کننده «تامین آریا» فاکتور خرید بساز\n2 عدد کابل قدرت\nبا قیمت واحد 500000 ریال'
        w = DummyWorker()
        tools = [{'name': x} for x in ('search_parties', 'search_items', 'create_purchase_invoice_draft')]
        text, meta = FA.process_purchase(w, {'id': 60}, p, tools)
        self.assertEqual([x[0] for x in w.calls], ['search_parties', 'search_items', 'create_purchase_invoice_draft'])
        self.assertEqual(meta['proposal_id'], 21)
        self.assertEqual(meta['mode'], 'guarded_purchase_invoice_proposal')
        self.assertIn('Proposal #21', text)
        proposal = w.calls[-1][1]
        self.assertEqual(proposal['party_id'], 12)
        self.assertEqual(proposal['lines'][0]['item_id'], 7)
        self.assertEqual(proposal['doc_type'], 'purchase_invoice_goods')

    def test_check_creates_proposal_only_with_grounded_ids(self):
        p = 'چک دریافتنی شماره چک CH-100 به مبلغ 100000000 ریال سررسید 1405/06/20 برای مشتری «کارخانه بهین بسته‌بندی» بانک «بانک ملت - جاری» آماده کن'
        w = DummyWorker()
        tools = [{'name': x} for x in ('search_parties', 'search_cash_accounts', 'create_check')]
        text, meta = FA.process_check_create(w, {'id': 61}, p, tools)
        self.assertEqual([x[0] for x in w.calls], ['search_parties', 'search_cash_accounts', 'create_check'])
        self.assertEqual(meta['proposal_id'], 22)
        proposal = w.calls[-1][1]
        self.assertEqual(proposal['party_id'], 3)
        self.assertEqual(proposal['cash_account_id'], 5)
        self.assertEqual(proposal['amount'], 100000000)
        self.assertIn('Proposal #22', text)

    def test_check_read_calls_read_tool_only(self):
        p = 'چک های دریافتنی باز سررسید این هفته را گزارش بده'
        w = DummyWorker()
        tools = [{'name': 'check_analytics'}]
        text, meta = FA.process_check_read(w, {'id': 62}, p, tools)
        self.assertEqual([x[0] for x in w.calls], ['check_analytics'])
        self.assertEqual(meta['mode'], 'treasury_check_read')
        self.assertIn('CH-100', text)
        self.assertNotIn('Proposal', text)


if __name__ == '__main__':
    unittest.main()
