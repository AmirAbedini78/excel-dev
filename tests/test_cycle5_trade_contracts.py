from pathlib import Path
import unittest, json
ROOT=Path(__file__).resolve().parents[1]
class Cycle5Contracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.schema=(ROOT/'app/Core/AccountingSchema.php').read_text(encoding='utf-8')
        cls.registry=(ROOT/'app/Core/AiToolRegistry.php').read_text(encoding='utf-8')
        cls.modules=(ROOT/'app/Core/ModuleRegistry.php').read_text(encoding='utf-8')
        cls.index=(ROOT/'index.php').read_text(encoding='utf-8')
        cls.bootstrap=(ROOT/'app/bootstrap.php').read_text(encoding='utf-8')
        cls.hard=(ROOT/'engine/commercial_hardening.py').read_text(encoding='utf-8')
        cls.worker=(ROOT/'engine/worker.py').read_text(encoding='utf-8')
    def test_schema(self):
        for t in ('acc_trade_cases','acc_trade_shipments','acc_trade_costs','acc_trade_milestones'):self.assertIn('CREATE TABLE IF NOT EXISTS '+t,self.schema)
        self.assertIn("['trade.view'",self.schema);self.assertIn("['trade.manage'",self.schema)
    def test_module_and_page(self):
        self.assertIn("'trade'=>[",self.modules);self.assertIn("'stage'=>'pilot','implemented'=>true,'default_enabled'=>true",self.modules);self.assertIn("'pages'=>['trade']",self.modules)
        self.assertIn("TradeModule.php",self.index);self.assertIn("str_starts_with($action,'trade_')",self.index);self.assertIn("elseif($page === 'trade') TradeModule::render();",self.index)
    def test_domain_boot(self):self.assertIn("TradeDomain.php",self.bootstrap);self.assertTrue((ROOT/'app/Core/TradeDomain.php').is_file());self.assertTrue((ROOT/'app/Modules/TradeModule.php').is_file())
    def test_tools(self):
        for x in ('search_trade_cases','trade_case_snapshot','landed_cost_summary','trade_risk_summary','create_trade_case','create_trade_shipment','add_trade_cost'):self.assertIn("'name'=>'"+x+"'",self.registry)
    def test_guard(self):
        for x in ('create_trade_case','create_trade_shipment','add_trade_cost'):self.assertIn('"'+x+'"',self.hard)
        for x in ('trade_case_read','trade_landed_cost_read','trade_risk_read','guarded_trade_case_proposal','guarded_trade_shipment_proposal','guarded_trade_cost_proposal'):self.assertIn('"'+x+'"',self.hard)
    def test_worker(self):self.assertIn('_install_trade_logistics(Worker)',self.worker);self.assertLess(self.worker.index('_install_trade_logistics(Worker)'),self.worker.index('_install_commercial_hardening(Worker)'))
    def test_task_state_json(self):
        j=json.loads((ROOT/'docs/ai/04-docops/task_state.json').read_text(encoding='utf-8'));self.assertEqual(j['current_milestone'],'v10.2 Trade Logistics + Landed Cost Vertical Slice');self.assertEqual(j['release_gates']['inventory_procurement_slice'],'PASS_LIVE_JOB70')
if __name__=='__main__':unittest.main()
