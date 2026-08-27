from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]

class ModuleKernelContractTests(unittest.TestCase):
    def test_registry_contract(self):
        p=(ROOT/'app/Core/ModuleRegistry.php').read_text(encoding='utf-8')
        for token in ['workspace_modules','default_enabled','enabled_effective','pageEnabled','setEnabled']:
            self.assertIn(token,p)
        self.assertIn("'finance'",p)
        self.assertIn("'inventory'",p)
        self.assertIn("'procurement'",p)
        self.assertIn("'crm'",p)
        self.assertIn("'trade'",p)

    def test_index_uses_module_gate(self):
        p=(ROOT/'index.php').read_text(encoding='utf-8')
        self.assertIn('ModuleCenterModule.php',p)
        self.assertIn('ModuleRegistry::pageEnabled',p)
        self.assertIn("$page === 'modules'",p)
        self.assertIn("str_starts_with($action,'module_')",p)

    def test_bootstrap_loads_registry(self):
        p=(ROOT/'app/bootstrap.php').read_text(encoding='utf-8')
        self.assertIn("ModuleRegistry.php",p)
        self.assertIn('ModuleRegistry::boot()',p)

    def test_v10_contract_doc_exists(self):
        p=ROOT/'docs/ai/10-MODULAR-PILOT-PLATFORM.md'
        self.assertTrue(p.is_file())
        text=p.read_text(encoding='utf-8')
        self.assertIn('Wide Platform',text)
        self.assertIn('Trade Resilience Pack',text)

if __name__=='__main__':
    unittest.main()
