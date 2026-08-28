from __future__ import annotations
import unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def read(p):return (ROOT/p).read_text(encoding='utf-8')
class Cycle4Contracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.schema=read('app/Core/AccountingSchema.php');cls.domain=read('app/Core/InventoryDomain.php');cls.modules=read('app/Core/ModuleRegistry.php');cls.index=read('index.php');cls.registry=read('app/Core/AiToolRegistry.php');cls.worker=read('engine/worker.py');cls.docker=read('engine/Dockerfile');cls.hard=read('engine/commercial_hardening.py');cls.cache=read('app/Core/RuntimeCache.php')
    def test_schema_tables(self):
        for t in ('acc_inventory_receipts','acc_inventory_receipt_lines','acc_stock_movements','acc_inventory_reservations'):self.assertIn('CREATE TABLE IF NOT EXISTS '+t,self.schema)
        self.assertIn("SCHEMA_VERSION = '10.1.0'",self.cache)
    def test_module_kernel(self):
        self.assertIn("'stage'=>'pilot','implemented'=>true,'default_enabled'=>true",self.modules);self.assertIn("'pages'=>['inventory']",self.modules);self.assertIn("'pages'=>['procurement']",self.modules);self.assertIn("updated_by IS NULL",self.modules)
    def test_routes(self):
        self.assertIn("InventoryProcurementModule.php",self.index);self.assertIn("str_starts_with($action,'inv_')",self.index);self.assertIn("$page === 'inventory'",self.index);self.assertIn("$page === 'procurement'",self.index)
    def test_inventory_truth(self):
        self.assertIn("direction='in' THEN quantity ELSE -quantity",self.domain);self.assertIn("status='active'",self.domain);self.assertIn("expected_inbound",self.domain);self.assertIn("if($l['accepted_qty']>0)$move->execute",self.domain);self.assertIn("$ownsTransaction=!$pdo->inTransaction()",self.domain)
    def test_ai_contract(self):
        self.assertIn("'create_warehouse_receipt','mode'=>'proposal','risk'=>'high'",self.registry);self.assertIn("'inventory_position'",self.registry);self.assertIn("'purchase_pipeline'",self.registry);self.assertIn("'replenishment_risk'",self.registry);self.assertIn("InventoryDomain::createReceipt",self.registry)
    def test_worker_wiring(self):
        self.assertIn('_install_inventory_procurement(Worker)',self.worker);self.assertIn('inventory_procurement.py',self.docker);self.assertIn('create_warehouse_receipt',self.hard);self.assertIn('guarded_inventory_receipt_proposal',self.hard)
if __name__=='__main__':unittest.main()
