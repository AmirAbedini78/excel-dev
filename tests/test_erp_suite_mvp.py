"""ERP Suite 10.13 contract guards; not a substitute for MySQL integration tests."""
import pathlib
import re
import shutil
import subprocess
import unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]

def source(path):
    return (ROOT/path).read_text(encoding='utf-8')

class SuiteContract(unittest.TestCase):
    def test_suites_registered_and_disabled_by_default(self):
        code=source('app/Core/ModuleRegistry.php')
        for module in ('projects','hr','pos','service','assets','expenses','operations'):
            self.assertRegex(code,rf"(?s)'{module}'=>\[.*?'default_enabled'=>false.*?'pages'=>\['{module}'\]",module)

    def test_boundaries_canonical_and_scoped(self):
        code=source('app/Core/ErpSuiteDomain.php')
        self.assertIn("'table'=>'acc_projects','canonical'=>true",code)
        self.assertIn('FOR UPDATE',code)
        self.assertIn('convertCartToSalesDraft',code)
        self.assertIn("'preinvoice'",code)
        self.assertIn("'draft'",code)
        for token in ('workspace_id','company_id','is_archived','assertScope','resolveReference','Audit::log','status=\\\'pending\\\''):
            self.assertIn(token,code)
        self.assertNotIn('INSERT INTO acc_stock_movements',code)
        self.assertNotIn('INSERT INTO acc_vouchers',code)

    def test_workflow_permissions_and_csrf(self):
        ui=source('app/Modules/ErpSuiteModule.php')
        self.assertIn("'erp_review'",ui)
        self.assertIn("'.approve'",ui)
        self.assertIn('csrf_field()',ui)
        self.assertIn('requirePermission',ui)
        self.assertIn('is_archived',source('app/Core/ErpSuiteDomain.php'))
        shell=source('index.php')
        self.assertIn('ErpSuiteModule::handle($action)',shell)
        self.assertIn('ErpSuiteModule::render($page)',shell)

    def test_schema_gate_and_permissions(self):
        self.assertIn("SCHEMA_VERSION = '10.21.0'",source('app/Core/RuntimeCache.php'))
        self.assertIn('ErpSuiteDomain::migrate(pdo())',source('app/bootstrap.php'))
        tenant=source('app/Core/Tenant.php')
        for permission in ('projects.manage','hr.approve','pos.manage','service.manage','assets.manage','expenses.approve','operations.view'):
            self.assertIn("'"+permission+"'",tenant)

    def test_seed_fails_closed(self):
        seed=source('scripts/seed_erp_suite_demo.php')
        self.assertIn("PHP_SAPI!=='cli'",seed)
        self.assertIn("!isset($opts['apply'])",seed)
        self.assertIn('preg_match',seed)
        self.assertIn('DEMO_ALREADY_SEEDED',seed)
        self.assertIn('rollBack()',seed)
        self.assertNotIn('INSERT INTO acc_stock_movements',seed)
        self.assertNotIn('INSERT INTO acc_vouchers',seed)

    @unittest.skipUnless(shutil.which('php'),'PHP CLI absent; CI must test')
    def test_php_domain_validators(self):
        p=subprocess.run(['php',str(ROOT/'tests/php_erp_suite_contract_test.php')],capture_output=True,text=True,timeout=20)
        self.assertEqual(p.returncode,0,p.stdout+'\n'+p.stderr)
        self.assertIn('ERP_SUITE_CONTRACT=PASS',p.stdout)

if __name__=='__main__':unittest.main()
