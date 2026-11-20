"""Opt-in manufacturing planning and policy gates, no live database needed."""
from __future__ import annotations

import shutil
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'engine'))
import provider_gateway as gateway


class ManufacturingPilotContracts(unittest.TestCase):
    def test_explicit_opt_in_and_scoped_ui(self):
        modules = (ROOT / 'app/Core/ModuleRegistry.php').read_text(encoding='utf-8')
        self.assertIn("'pages'=>['production']", modules)
        production = modules.split("'production'=>[", 1)[1].split("'projects'=>[", 1)[0]
        self.assertIn("'implemented'=>true", production)
        self.assertIn("'default_enabled'=>false", production)
        root = (ROOT / 'index.php').read_text(encoding='utf-8')
        self.assertIn("str_starts_with($action,'prod_')", root)
        self.assertIn("elseif($page === 'production') ProductionModule::render();", root)
        self.assertIn("'production'=>'production.view'", root)
        page = (ROOT / 'app/Modules/ProductionModule.php').read_text(encoding='utf-8')
        self.assertIn("Tenant::requirePermission('production.manage')", page)

    def test_migration_is_additive_and_tenant_scoped(self):
        domain = (ROOT / 'app/Core/ProductionDomain.php').read_text(encoding='utf-8')
        for name in ('erp_production_boms', 'erp_production_bom_lines', 'erp_production_plans'):
            self.assertIn('CREATE TABLE IF NOT EXISTS ' + name, domain)
        self.assertIn('workspace_id=? AND company_id=?', domain)
        self.assertIn('InventoryDomain::inventoryPosition', domain)
        self.assertNotIn('acc_stock_movements SET', domain)
        self.assertIn('ProductionDomain::migrate(pdo());', (ROOT / 'app/bootstrap.php').read_text(encoding='utf-8'))
        self.assertIn('10.19.1', (ROOT / 'app/Core/RuntimeCache.php').read_text(encoding='utf-8'))  # Additive suite migration advances schema gate

    def test_demo_seeder_requires_explicit_demo_scope(self):
        seed = (ROOT / 'scripts/seed_manufacturing_demo.php').read_text(encoding='utf-8')
        for token in ('PHP_SAPI', "isset($opts['apply'])", 'DEMO\\s*[-—]', 'erp_production_plans', 'beginTransaction', 'rollBack'):
            self.assertIn(token, seed)
        self.assertNotIn('INSERT INTO acc_stock_movements', seed)
        self.assertNotIn('INSERT INTO acc_vouchers', seed)

    def test_quantity_rounding_and_invalid_scrap(self):
        php = shutil.which('php')
        if not php:
            self.skipTest('PHP is available in CI')
        src = '''
          require 'app/Core/ProductionDomain.php';
          $lines=[['component_item_id'=>1,'quantity_per'=>2.25,'scrap_percent'=>10]];
          $a=ProductionDomain::requirements($lines,5,11);
          if (abs($a[0]['required_qty']-5.445)>0.00001) exit(1);
          try {ProductionDomain::requirements([['quantity_per'=>1,'scrap_percent'=>101]],1,1);exit(2);}catch(RuntimeException $e){}
          try {ProductionDomain::requirements($lines,0,1);exit(3);}catch(RuntimeException $e){}
          echo 'PRODUCTION_PURE_MRP_PASS';
        '''
        p = subprocess.run([php, '-r', src], cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(p.returncode, 0, p.stderr + p.stdout)
        self.assertIn('PRODUCTION_PURE_MRP_PASS', p.stdout)


class ProviderSafety(unittest.TestCase):
    def test_local_only_never_escapes_to_xai_or_cursor(self):
        class Worker:
            cfg = {'cursor_agent': {'enabled': True, 'allowed_models': ['cursor-x']},
                   'xai_provider': {'enabled': True, 'allowed_models': ['grok-x']}}
            _provider_strategy = 'local_only'
            _local_role_models = {'analysis': 'qwen'}
            _cloud_role_models = {'analysis': 'grok-x'}
            _local_available = True
            _cloud_available = True
            _cursor_available = True
            _xai_available = True
        for name in ('grok-x', 'cursor-x', 'gemini-x'):
            self.assertEqual(gateway._provider_order(Worker(), 'analysis', name), ['local'])
        Worker._local_available = False
        self.assertEqual(gateway._provider_order(Worker(), 'analysis', 'grok-x'), [])

    def test_explicit_cloud_selection_cannot_bypass_local_only(self):
        gateway_source=(ROOT/'engine/provider_gateway.py').read_text(encoding='utf-8')
        self.assertIn('provider_blocked_by_local_only_policy', gateway_source)
        self.assertIn("self._provider_strategy == 'local_only'", gateway_source)


if __name__ == '__main__':
    unittest.main()
