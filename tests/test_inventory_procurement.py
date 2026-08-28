from __future__ import annotations
import sys, threading, unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];ENGINE=Path('/app') if Path('/app/inventory_procurement.py').is_file() else ROOT/'engine';sys.path.insert(0,str(ENGINE))
import inventory_procurement as IP

class Dummy:
    def __init__(self):self.calls=[];self.progress_lock=threading.Lock();self.current_trace=[]
    def trace(self,*a,**k):return None
    def tool(self,job,name,args,call_id):
        self.calls.append((name,args))
        if name=='search_purchase_documents':return {'rows':[{'id':8,'document_no':'AI-PUR-20260828-214858-1275','supplier_name':'تامین برق ایرانیان'}]}
        if name=='search_warehouses':return {'rows':[{'id':2,'code':'WH-01','name':'انبار مرکزی','warehouse_type':'general'}]}
        if name=='purchase_pipeline':return {'rows':[{'purchase_doc_id':8,'purchase_line_id':11,'document_no':'AI-PUR-20260828-214858-1275','supplier_name':'تامین برق ایرانیان','item_id':1,'item_code':'PLC-S7-1200','item_name':'PLC S7-1200 CPU 1212C','ordered_qty':'2.0000','accepted_qty':'0.0000','expected_inbound':'2.0000'}]}
        if name=='create_warehouse_receipt':return {'proposal_id':7,'status':'awaiting_human_approval'}
        if name=='search_items':return {'rows':[{'id':1,'code':'PLC-S7-1200','name':'PLC S7-1200 CPU 1212C'}]}
        if name=='inventory_position':return {'rows':[{'code':'PLC-S7-1200','name':'PLC S7-1200 CPU 1212C','on_hand':'0.0000','reserved':'0.0000','available':'0.0000','expected_inbound':'2.0000','projected_available':'2.0000'}]}
        if name=='replenishment_risk':return {'rows':[{'code':'TEST','name':'TEST','available':'1.0000','expected_inbound':'2.0000','min_stock':'5.0000','suggested_replenishment':'2.0000'}]}
        raise AssertionError(name)

class Tests(unittest.TestCase):
    def test_receipt_is_proposal_only(self):
        p='برای سند خرید «AI-PUR-20260828-214858-1275» دریافت 2 عدد «PLC S7-1200 CPU 1212C» در انبار «انبار مرکزی» آماده کن'
        self.assertTrue(IP.is_receipt_create(p));w=Dummy();text,meta=IP.process_receipt(w,{'id':70},p)
        self.assertEqual([x[0] for x in w.calls],['search_purchase_documents','search_warehouses','purchase_pipeline','create_warehouse_receipt']);self.assertEqual(meta['proposal_id'],7);self.assertEqual(meta['mode'],'guarded_inventory_receipt_proposal');self.assertIn('Proposal #7',text);args=w.calls[-1][1];self.assertEqual(args['purchase_doc_id'],8);self.assertEqual(args['warehouse_id'],2);self.assertEqual(args['lines'][0]['purchase_line_id'],11);self.assertEqual(args['lines'][0]['accepted_qty'],2.0)
    def test_inventory_read_is_grounded(self):
        w=Dummy();text,meta=IP.process_inventory(w,{'id':71},'موجودی «PLC S7-1200 CPU 1212C» را با ورودی مورد انتظار گزارش بده')
        self.assertEqual([x[0] for x in w.calls],['search_items','inventory_position']);self.assertEqual(meta['model'],'none');self.assertIn('ورودی مورد انتظار 2',text)
    def test_warehouse_list(self):
        w=Dummy();text,meta=IP.process_warehouse_list(w,{'id':72});self.assertIn('انبار مرکزی',text);self.assertEqual(meta['mode'],'inventory_warehouses_read')
    def test_replenishment_no_llm(self):
        w=Dummy();text,meta=IP.process_replenishment(w,{'id':73});self.assertEqual(meta['model'],'none');self.assertEqual(w.calls[0][0],'replenishment_risk')

    def test_pipeline_accepts_decimal_strings(self):
        w=Dummy();text,meta=IP.process_pipeline(w,{'id':74})
        self.assertEqual(meta['mode'],'procurement_pipeline_read');self.assertIn('2',text)
if __name__=='__main__':unittest.main()
