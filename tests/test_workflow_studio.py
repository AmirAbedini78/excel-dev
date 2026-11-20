"""Workflow Studio source and behavior gate without live company data."""
import re
import shutil
import subprocess
import unittest
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]


def src(path):
    return (ROOT/path).read_text(encoding='utf-8')


class WorkflowStudio(unittest.TestCase):
    def test_version_schema_gate(self):
        self.assertIn("SCHEMA_VERSION = '10.24.0-rc1'", src('app/Core/RuntimeCache.php'))
        self.assertIn('WorkflowDomain::migrate(pdo())', src('app/bootstrap.php'))

    def test_four_additive_scoped_tables(self):
        domain = src('app/Core/WorkflowDomain.php')
        for table in ('erp_workflow_definitions', 'erp_workflow_steps',
                      'erp_workflow_cases', 'erp_workflow_events'):
            self.assertIn('CREATE TABLE IF NOT EXISTS '+table,domain)
        self.assertEqual(domain.count('CREATE TABLE IF NOT EXISTS'),4)
        self.assertIn('workspace_id INT NOT NULL, company_id INT NOT NULL',domain)
        self.assertIn('UNIQUE KEY uq_workflow_action',domain)

    def test_no_gl_inventory_or_payroll_posting_in_workflow(self):
        domain = src('app/Core/WorkflowDomain.php')
        for forbidden in ('INSERT INTO acc_vouchers','INSERT INTO acc_stock_movements',
                          'INSERT INTO acc_journal', 'UPDATE acc_sales_docs SET workflow_status'):
            self.assertNotIn(forbidden,domain)
        self.assertIn("$effect==='leave_request'",domain)
        self.assertIn('status=\'pending\'',domain)

    def test_transition_guard_and_idempotency(self):
        domain = src('app/Core/WorkflowDomain.php')
        self.assertIn('SELECT * FROM erp_workflow_cases',domain)
        self.assertIn('FOR UPDATE',domain)
        self.assertIn("'revision'",domain)
        self.assertIn('revision=revision+1',domain)
        self.assertIn('uq_workflow_action',domain)
        self.assertIn("if($uid===(int)($case['opened_by']??0))",domain)

    def test_permissions_and_pilot_opt_in(self):
        registry=src('app/Core/ModuleRegistry.php')
        self.assertRegex(registry,r"(?s)'workflows'=>\[\s*'title'=>.*?'stage'=>'pilot','implemented'=>true,'default_enabled'=>false")
        perms=src('app/Core/Tenant.php')
        for p in ('workflow.view','workflow.manage','workflow.approve'):
            self.assertIn(p,perms)
        module=src('app/Modules/WorkflowModule.php')
        self.assertIn('Tenant::requirePermission',module)
        self.assertIn('csrf_field()',module)

    def test_navigation_and_route(self):
        html=src('index.php')
        self.assertIn("WorkflowModule::render()",html)
        self.assertIn("WorkflowModule::handle($action)",html)
        self.assertIn("'workflows'=>'workflow.view'",html)
        self.assertIn('assets/workflows.css?v=10.14.0',html)

    def test_cross_link_is_to_validated_case_with_no_direct_post(self):
        suite=src('app/Modules/ErpSuiteModule.php')
        self.assertIn("'expenses'=>'expense'",suite)
        self.assertIn("'leave'=>'leave'",suite)
        self.assertIn('ارجاع به فرآیند',suite)
        domain=src('app/Core/WorkflowDomain.php')
        self.assertIn('validateSubject',domain)
        self.assertIn('Tenant::requirePermission($types[$type][2])',domain)

    @unittest.skipUnless(shutil.which('php'), 'PHP CLI absent; CI must run')
    def test_pure_php_business_rules(self):
        r=subprocess.run(['php',str(ROOT/'tests/php_workflow_contract_test.php')],
                         cwd=ROOT,capture_output=True,text=True,timeout=15)
        self.assertEqual(r.returncode,0,r.stdout+'\n'+r.stderr)
        self.assertIn('WORKFLOW_BEHAVIOR=PASS',r.stdout)


if __name__ == '__main__': unittest.main()
