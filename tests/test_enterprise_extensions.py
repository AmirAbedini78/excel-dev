"""Source-and-PHP behavioral release contracts for v10.15 enterprise extensions."""
from pathlib import Path
import json, subprocess, unittest, shutil

ROOT=Path(__file__).resolve().parents[1]


def read(path):
    return (ROOT/path).read_text(encoding='utf-8')


class EnterpriseExtensions(unittest.TestCase):
    def test_schema_version_bumped(self):
        self.assertIn("SCHEMA_VERSION = '10.18.0'",read('app/Core/RuntimeCache.php'))
        self.assertIn("VERSION='10.18.0'",read('app/Core/ModuleRegistry.php'))

    def test_bootstrap_order_after_canonical(self):
        boot=read('app/bootstrap.php')
        self.assertIn("require_once APP_ROOT . '/app/Core/EnterpriseDomain.php'",boot)
        self.assertGreater(boot.index('EnterpriseDomain::migrate(pdo());'),boot.index('WorkflowDomain::migrate(pdo());'))
        self.assertLess(boot.index('EnterpriseDomain::migrate(pdo());'),boot.index('RuntimeCache::markSchema('))

    def test_group_registry_opt_in_not_fake_planned(self):
        registry=read('app/Core/ModuleRegistry.php')
        for group in ('commerce','warehouse','factory','people','service','growth','knowledge','governance'):
            self.assertIn("'enterprise_"+group+"'=>[",registry)
        self.assertGreaterEqual(registry.count("'default_enabled'=>false"),8)

    def test_all_namespaced_routes_have_permission_checks(self):
        src=read('index.php')
        for page in ('enterprise_sales','enterprise_wms','enterprise_factory','enterprise_people','enterprise_service_plus','enterprise_growth','enterprise_knowledge','enterprise_governance'):
            self.assertIn("'"+page+"'",src)
        self.assertIn("EnterpriseModule::render($page)",src)
        self.assertIn("EnterpriseModule::handle($action)",src)
        self.assertIn('verify_csrf();',src)

    def test_tenant_permissions_only_owner_granted(self):
        src=read('app/Core/Tenant.php')
        for group in ('commerce','warehouse','factory','people','service','growth','knowledge','governance'):
            for perm in ('view','manage','approve'):
                self.assertIn('enterprise.'+group+'.'+perm,src)
        self.assertIn("r.role_key='owner'",src)

    def test_scope_and_concurrency_and_history_guards(self):
        src=read('app/Core/EnterpriseDomain.php')
        for needle in ('workspace_id=? AND company_id=?', 'FOR UPDATE', 'revision=revision+1', 'rowCount()!==1', 'INSERT INTO erp_ext_events', 'is_archived=1'):
            self.assertIn(needle,src)
        self.assertIn("$old['created_by']===$uid",src)
        self.assertIn("self::validateRef('party'",src)

    def test_bridge_never_posts_money_or_stock(self):
        src=read('app/Core/EnterpriseDomain.php')
        self.assertIn('createPurchaseDraft(',src)
        self.assertIn('acc_purchase_docs',src)
        self.assertIn('acc_purchase_lines',src)
        self.assertIn('erp_ext_links',src)
        self.assertIn("'purchase_order_goods'",src)
        self.assertIn("'draft','not_sent'",src)
        for table in ('acc_vouchers','acc_stock_movements','acc_cash_transactions','acc_inventory_receipts'):
            self.assertNotIn('INSERT INTO '+table,src)

    def test_workflow_subject_ref_is_scoped(self):
        wf=read('app/Core/WorkflowDomain.php')
        for ref in ('erp_ext_vendor_offers','erp_ext_cycle_counts','erp_ext_engineering_changes','erp_ext_contract_register'):
            self.assertIn(ref,wf)
        self.assertIn('SELECT 1 FROM `$table` WHERE id=? AND workspace_id=? AND company_id=?',wf)

    def test_documentation_truth(self):
        d=read('docs/ai/35-ENTERPRISE-CAPABILITY-MATRIX-1015.md')
        for s in ('39', '41', 'Odoo', 'سپیدار', 'not full parity', 'Order-to-Cash'):
            self.assertIn(s,d)

    def test_php_business_contracts(self):
        exe=shutil.which('php')
        if not exe:self.skipTest('PHP CLI absent; GitHub Actions must run')
        p=subprocess.run([exe,str(ROOT/'tests/php_enterprise_suite_contract_test.php')],cwd=ROOT,encoding='utf-8',capture_output=True,timeout=30)
        self.assertEqual(p.returncode,0,p.stdout+p.stderr)
        self.assertIn('ENTERPRISE_PHP=PASS',p.stdout)

    def test_php_syntax_for_bounded_new_sources(self):
        exe=shutil.which('php')
        if not exe:self.skipTest('PHP CLI absent; GitHub Actions must run')
        for f in ('app/Core/EnterpriseCatalog.php','app/Core/EnterpriseDomain.php','app/Modules/EnterpriseModule.php','tests/php_enterprise_suite_contract_test.php'):
            p=subprocess.run([exe,'-l',str(ROOT/f)],capture_output=True,text=True,timeout=30)
            self.assertEqual(p.returncode,0,f+': '+p.stdout+p.stderr)


if __name__=='__main__':unittest.main()
