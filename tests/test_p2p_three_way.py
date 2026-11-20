"""v10.16 procurement matching source and safe boundary contracts."""
import pathlib
import shutil
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

def source(path):
    return (ROOT / path).read_text(encoding="utf-8")


class ThreeWayPurchaseTests(unittest.TestCase):
    def test_version_and_migration_order(self):
        b = source('app/bootstrap.php')
        self.assertIn("SCHEMA_VERSION = '10.20.1'", source('app/Core/RuntimeCache.php'))
        self.assertIn("VERSION='10.20.1'", source('app/Core/ModuleRegistry.php'))
        self.assertLess(b.index('AccountingSchema::migrate(pdo())'),b.index('PurchaseMatchingDomain::migrate(pdo())'))
        self.assertLess(b.index('EnterpriseDomain::migrate(pdo())'),b.index('PurchaseMatchingDomain::migrate(pdo())'))

    def test_scoped_p2p_tables(self):
        d = source('app/Core/PurchaseMatchingDomain.php')
        self.assertGreaterEqual(d.count('CREATE TABLE IF NOT EXISTS erp_p2p_'),4)
        self.assertIn('UNIQUE KEY uq_p2p_supplier_invoice (workspace_id,company_id,supplier_id,supplier_invoice_no)',d)
        self.assertIn('UNIQUE KEY uq_p2p_canonical (workspace_id,canonical_doc_id)',d)
        self.assertIn('UNIQUE KEY uq_p2p_bill_line (workspace_id,bill_id,purchase_line_id)',d)
        self.assertIn('erp_p2p_company_policy',d)
        self.assertIn('erp_p2p_bill_revisions',d)
        self.assertIn('erp_p2p_policy_exceptions',d)

    def test_only_approved_purchase_orders(self):
        d=source('app/Core/PurchaseMatchingDomain.php')
        self.assertIn("['approved','final']",d)
        self.assertIn("doc_type=\\'purchase_order_goods\\'",d)
        self.assertIn("$bill['submitted_by']===$uid",d)
        self.assertIn("$po['created_by']===$uid",d)
        self.assertIn('workflow_status=\'approved\'',d)

    def test_receipt_and_line_validation(self):
        d=source('app/Core/PurchaseMatchingDomain.php')
        self.assertIn("r.status='posted'",d)
        self.assertIn('rl.accepted_qty',d)
        self.assertIn("b.status='matched'",d)
        self.assertIn('min($ordered,$accepted)-$billed',d)
        self.assertIn('unit_price',d)
        self.assertIn('FOR UPDATE',d)

    def test_draft_only_bridge_and_no_payments(self):
        d=source('app/Core/PurchaseMatchingDomain.php')
        self.assertIn("'purchase_invoice_goods'",d)
        self.assertIn("'draft','not_sent'",d)
        for forbidden in ('INSERT INTO acc_vouchers','INSERT INTO acc_stock_movements','INSERT INTO acc_checks','UPDATE acc_cash_accounts'):
            self.assertNotIn(forbidden,d)

    def test_mutation_guard_and_route(self):
        i=source('index.php')
        m=source('app/Modules/PurchaseMatchingModule.php')
        self.assertIn("str_starts_with($action,'p2p_')",i)
        self.assertIn("$page === 'p2p'",i)
        self.assertIn("'p2p'=>'accounting.purchase.view'",i)
        self.assertIn("ModuleRegistry::enabled('p2p')",m)
        self.assertIn("Tenant::requirePermission('accounting.purchase.manage')",m)
        self.assertIn('csrf_field()',m)
        self.assertIn("'default_enabled'=>false",source('app/Core/ModuleRegistry.php'))

    def test_matched_invoice_never_reenters_inventory_pipeline(self):
        inventory=source('app/Core/InventoryDomain.php')
        self.assertGreaterEqual(inventory.count("p2p.canonical_doc_id=d.id AND p2p.status='matched'"),3)
        self.assertIn('public static function createReceipt(',inventory)
        self.assertIn('self::purchaseDocument($wid,$cid,$docId)',inventory)

    def test_delete_prevents_broken_references(self):
        a=source('app/Modules/AccountingIndustrialModule.php')
        self.assertIn("erp_p2p_vendor_bills WHERE workspace_id=? AND company_id=?",a)
        self.assertIn("acc_inventory_receipts WHERE workspace_id=? AND company_id=?",a)
        self.assertIn("$doc['workflow_status']!=='draft'",a)

    def test_php_pure_rules(self):
        php=shutil.which('php')
        if not php:self.skipTest('PHP CLI unavailable; CI must run')
        p=subprocess.run([php,str(ROOT/'tests/php_p2p_contract_test.php')],text=True,capture_output=True)
        self.assertEqual(0,p.returncode,p.stderr+p.stdout)
        self.assertIn('P2P_CONTRACT=PASS',p.stdout)

if __name__=='__main__':unittest.main()
