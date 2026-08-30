from __future__ import annotations
import sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/"engine"))
import crm_lite as c
class W:
    def __init__(self):self.calls=[]
    def trace(self,*a,**k):pass
    def tool(self,j,n,a,call):
        self.calls.append((n,a))
        if n=="search_parties":return [{"id":3,"code":"CUS-003","name":"کارخانه بهین بسته‌بندی"}]
        if n=="crm_customer_360":return {"party":{"name":"کارخانه بهین بسته‌بندی"},"financial":{"current_balance_irr":727100000,"balance_nature":"debtor","recorded_sales_net_irr":574200000,"sales_document_count":2,"outstanding_sales_quantity":4},"crm":{"open_pipeline_irr":900000000,"weighted_pipeline_irr":450000000,"next_followup":None}}
        if n=="crm_pipeline_summary":return {"open_count":1,"open_amount_irr":900000000,"weighted_amount_irr":450000000,"rows":[]}
        if n=="crm_followup_queue":return {"overdue_count":1,"today_count":0,"upcoming_count":1,"rows":[]}
        if n=="create_crm_activity":return {"proposal_id":20}
        if n=="create_crm_opportunity":return {"proposal_id":21}
        raise AssertionError(n)
class T(unittest.TestCase):
    def setUp(self):self.w=W();self.j={"id":100}
    def test_360(self):
        t,m=c.process360(self.w,self.j,'نمای 360 مشتری «کارخانه بهین بسته‌بندی» را بده');self.assertEqual(m["mode"],"crm_customer_360_read");self.assertIn("727,100,000",t)
    def test_pipeline(self):
        t,m=c.process_pipeline(self.w,self.j);self.assertEqual(m["mode"],"crm_pipeline_read")
    def test_follow(self):
        t,m=c.process_follow(self.w,self.j);self.assertIn("عقب‌افتاده 1",t)
    def test_activity(self):
        t,m=c.process_activity(self.w,self.j,'برای مشتری «کارخانه بهین بسته‌بندی» پیگیری با موضوع «تماس خرید بعدی» برای 1405/06/10 آماده کن');self.assertEqual(m["proposal_id"],20);self.assertEqual([x for x in self.w.calls if x[0]=="create_crm_activity"][0][1]["party_id"],3)
    def test_opp(self):
        t,m=c.process_opp(self.w,self.j,'برای مشتری «کارخانه بهین بسته‌بندی» فرصت فروش «PLC Batch 2» با مبلغ 900000000 ریال و احتمال 50% برای 1405/07/01 آماده کن');a=[x for x in self.w.calls if x[0]=="create_crm_opportunity"][0][1];self.assertEqual(a["amount_irr"],900000000);self.assertEqual(a["probability"],50)
if __name__=="__main__":unittest.main()
