from __future__ import annotations
import sys
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/"engine"))
import sales_fulfillment as sf  # noqa: E402

class FakeWorker:
    def __init__(self):
        self.calls=[]
        self.traces=[]
    def trace(self,job,stage,message,details=None):
        self.traces.append((stage,message,details or {}))
    def tool(self,job,name,args,call_id):
        self.calls.append((name,args,call_id))
        if name=="search_sales_documents":
            return {"rows":[{"id":21,"document_no":"AI-SAL-TEST-1","party_name":"مشتری تست"}]}
        if name=="search_warehouses":
            return {"rows":[{"id":1,"code":"WH-MAIN","name":"انبار مرکزی"}]}
        if name=="sales_fulfillment":
            return {"sales_doc_id":21,"document_no":"AI-SAL-TEST-1","customer_name":"مشتری تست","ordered_quantity":2,"reserved_quantity":2,"delivered_quantity":0,"outstanding_quantity":2,
                    "rows":[{"sales_line_id":31,"item_code":"PLC-S7-1200","ordered_qty":2,"reserved_qty":2,"delivered_qty":0,"outstanding_qty":2}]}
        if name=="reserve_sales_stock":
            return {"proposal_id":12,"status":"awaiting_human_approval"}
        if name=="deliver_sales_stock":
            return {"proposal_id":13,"status":"awaiting_human_approval"}
        if name=="sales_margin_summary":
            return {"document_no":"AI-SAL-TEST-1","revenue_ex_tax_irr":800000000,"cogs_irr":620000000,"gross_margin_irr":180000000,"gross_margin_pct":22.5,"margin_basis":"actual_landed"}
        if name=="trade_manager_brief":
            return {"trade":{"risk_count":1,"rows":[{"case_no":"TRD-1","risk_level":"high","clearance_status":"hold","projected_landed_total_irr":620000000}]},
                    "inventory":{"shortage_count":1,"rows":[]},"sales":{"at_risk_count":0,"at_risk":[]}}
        raise AssertionError(name)

class SalesFulfillmentAgentTests(unittest.TestCase):
    def setUp(self):
        self.w=FakeWorker();self.job={"id":90}

    def test_reservation_proposal_is_explicit_and_guarded(self):
        text,meta=sf.process_reserve(self.w,self.job,'برای سند فروش «AI-SAL-TEST-1» موجودی را در انبار «انبار مرکزی» رزرو کن')
        self.assertEqual(meta["mode"],"guarded_sales_reservation_proposal")
        self.assertEqual(meta["proposal_id"],12)
        proposal=[c for c in self.w.calls if c[0]=="reserve_sales_stock"][0][1]
        self.assertEqual(proposal,{"sales_doc_id":21,"warehouse_id":1,"lines":[{"sales_line_id":31,"quantity":2.0}]})
        self.assertIn("تا تأیید انسانی",text)

    def test_delivery_uses_reserved_quantity_only(self):
        text,meta=sf.process_delivery(self.w,self.job,'رزرو سند فروش «AI-SAL-TEST-1» را از انبار «انبار مرکزی» تحویل کن')
        self.assertEqual(meta["mode"],"guarded_sales_delivery_proposal")
        proposal=[c for c in self.w.calls if c[0]=="deliver_sales_stock"][0][1]
        self.assertEqual(proposal["lines"],[{"sales_line_id":31,"quantity":2.0}])
        self.assertIn("خروجی انبار",text)

    def test_fulfillment_read_is_deterministic(self):
        text,meta=sf.process_fulfillment(self.w,self.job,'وضعیت تامین و تحویل سند فروش «AI-SAL-TEST-1» را گزارش بده')
        self.assertEqual(meta["model"],"none")
        self.assertEqual(meta["mode"],"sales_fulfillment_read")
        self.assertIn("رزرو 2",text)

    def test_margin_read_is_grounded(self):
        text,meta=sf.process_margin(self.w,self.job,'حاشیه سود سند فروش «AI-SAL-TEST-1» را گزارش بده')
        self.assertEqual(meta["mode"],"sales_margin_read")
        self.assertIn("180,000,000",text)
        self.assertIn("actual_landed",text)

    def test_manager_brief_has_no_model(self):
        text,meta=sf.process_brief(self.w,self.job)
        self.assertEqual(meta["mode"],"trade_manager_brief_read")
        self.assertEqual(meta["tools_used"],["trade_manager_brief"])
        self.assertIn("Cash projection",text)

if __name__=="__main__":
    unittest.main()
