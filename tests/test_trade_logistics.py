from __future__ import annotations
import sys, threading, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];ENGINE=Path('/app') if Path('/app/trade_logistics.py').is_file() else ROOT/'engine';sys.path.insert(0,str(ENGINE))
import trade_logistics as TL

class Dummy:
    def __init__(self):self.calls=[];self.progress_lock=threading.Lock();self.current_trace=[]
    def trace(self,*a,**k):return None
    def tool(self,job,name,args,call_id):
        self.calls.append((name,args))
        if name=='search_purchase_documents':return {'rows':[{'id':8,'document_no':'AI-PUR-20260828-214858-1275','supplier_name':'تامین برق ایرانیان'}]}
        if name=='search_trade_cases':return {'rows':[{'id':3,'case_no':'TRD-20260829-030000-ABCD','purchase_document_no':'AI-PUR-20260828-214858-1275','proforma_no':'PF-01'}]}
        if name=='create_trade_case':return {'proposal_id':8,'status':'awaiting_human_approval'}
        if name=='create_trade_shipment':return {'proposal_id':9,'status':'awaiting_human_approval'}
        if name=='add_trade_cost':return {'proposal_id':10,'status':'awaiting_human_approval'}
        if name=='trade_case_snapshot':return {'case':{'case_no':'TRD-20260829-030000-ABCD','purchase_document_no':'AI-PUR-20260828-214858-1275','supplier_name':'تامین برق ایرانیان','incoterm':'FOB','status':'in_transit','clearance_status':'not_started'},'shipments':[{'mode':'sea','status':'in_transit','eta':'2026-09-15'}],'landed_cost':{'projected_landed_total_irr':'620000000.00'}}
        if name=='landed_cost_summary':return {'case_no':'TRD-20260829-030000-ABCD','purchase_base_irr':'500000000.00','estimated_additional_irr':'120000000.00','actual_additional_recorded_irr':'100000000.00','projected_landed_total_irr':'600000000.00','allocations':[{'item_code':'PLC-S7-1200','base_unit_cost_irr':'250000000.00','projected_landed_unit_cost_irr':'300000000.00','accepted_qty':'2.0000'}]}
        if name=='trade_risk_summary':return {'rows':[{'case_no':'TRD-20260829-030000-ABCD','risk_level':'medium','delay_days':'3','shipment_status':'in_transit','clearance_status':'not_started','projected_landed_total_irr':'600000000.00'}]}
        raise AssertionError(name)

class Tests(unittest.TestCase):
    def test_case_proposal(self):
        p='برای سند خرید «AI-PUR-20260828-214858-1275» پرونده بازرگانی با اینکوترمز «FOB» مبدا «China» مقصد «Iran» ارز «USD» نرخ تبدیل 900000 ریال آماده کن';w=Dummy();t,m=TL.process_case_create(w,{'id':80},p);self.assertEqual([x[0] for x in w.calls],['search_purchase_documents','create_trade_case']);self.assertEqual(m['proposal_id'],8);self.assertEqual(w.calls[-1][1]['incoterm'],'FOB')
    def test_shipment_proposal(self):
        p='برای پرونده بازرگانی «TRD-20260829-030000-ABCD» حمل دریایی از مبدا «Shanghai» به مقصد «Bandar Abbas» با ETD «1405/06/10» و ETA «1405/06/25» آماده کن';w=Dummy();t,m=TL.process_shipment_create(w,{'id':81},p);self.assertEqual(m['proposal_id'],9);self.assertEqual(w.calls[-1][1]['mode'],'sea')
    def test_cost_proposal(self):
        p='برای پرونده بازرگانی «TRD-20260829-030000-ABCD» هزینه واقعی حمل 1000 USD با نرخ 900000 آماده کن';w=Dummy();t,m=TL.process_cost_create(w,{'id':82},p);self.assertEqual(m['proposal_id'],10);self.assertEqual(w.calls[-1][1]['cost_type'],'freight');self.assertEqual(w.calls[-1][1]['basis'],'actual')
    def test_landed_decimal_strings(self):
        w=Dummy();t,m=TL.process_landed_read(w,{'id':83},'بهای تمام‌شده واردات پرونده بازرگانی «TRD-20260829-030000-ABCD» را گزارش بده');self.assertIn('600,000,000',t);self.assertIn('300,000,000',t);self.assertEqual(m['model'],'none')
    def test_risk_read(self):
        w=Dummy();t,m=TL.process_risk(w,{'id':84});self.assertIn('تاخیر 3 روز',t);self.assertEqual(m['mode'],'trade_risk_read')

if __name__=='__main__':unittest.main()
