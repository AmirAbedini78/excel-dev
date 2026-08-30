from __future__ import annotations
import json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class T(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.d=(ROOT/"app/Core/CrmDomain.php").read_text(encoding="utf-8");cls.r=(ROOT/"app/Core/AiToolRegistry.php").read_text(encoding="utf-8");cls.m=(ROOT/"app/Core/ModuleRegistry.php").read_text(encoding="utf-8");cls.i=(ROOT/"index.php").read_text(encoding="utf-8");cls.w=(ROOT/"engine/worker.py").read_text(encoding="utf-8");cls.h=(ROOT/"engine/commercial_hardening.py").read_text(encoding="utf-8")
    def test_identity(self):self.assertIn("acc_parties",self.d);self.assertNotIn("CREATE TABLE IF NOT EXISTS crm_customers",self.d)
    def test_tables(self):
        for x in ("crm_party_contacts","crm_opportunities","crm_activities"):self.assertIn("CREATE TABLE IF NOT EXISTS "+x,self.d)
    def test_truth(self):
        for x in ("acc_voucher_lines","acc_sales_docs","acc_sales_delivery_lines"):self.assertIn(x,self.d)
    def test_module(self):
        self.assertIn("public const VERSION='10.4.0'",self.m);self.assertIn("'implemented'=>true,'default_enabled'=>true",self.m);self.assertIn("'pages'=>['crm']",self.m);self.assertIn("CrmModule::render()",self.i)
    def test_ai(self):
        for x in ("crm_customer_360","crm_pipeline_summary","crm_followup_queue","create_crm_activity","create_crm_opportunity"):self.assertIn("'name'=>'"+x+"'",self.r)
    def test_worker(self):self.assertIn("_install_crm_lite(Worker)",self.w);self.assertIn('"create_crm_activity"',self.h);self.assertIn('"crm_customer_360_read"',self.h)
    def test_state(self):
        s=json.loads((ROOT/"docs/ai/04-docops/task_state.json").read_text(encoding="utf-8"))
        gate=str(s["release_gates"]["crm_lite_slice"])
        self.assertTrue(gate=="IMPLEMENTED_CANDIDATE" or gate.startswith("PASS"))
        self.assertIn("cycle_7",s["live_validation_summary"])
    def test_crm_ui_has_accounting_company_selector(self):
        ui=(ROOT/"app/Modules/CrmModule.php").read_text(encoding="utf-8")
        self.assertIn("AccountingRepository::companies()",ui)
        self.assertIn('name="company_id"',ui)
        self.assertIn("شرکت فعال",ui)
if __name__=="__main__":unittest.main()
