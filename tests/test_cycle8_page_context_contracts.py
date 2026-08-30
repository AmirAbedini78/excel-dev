from __future__ import annotations
import json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class Cycle8PageContextContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ctx=(ROOT/"app/Core/AiPageContext.php").read_text(encoding="utf-8")
        cls.repo=(ROOT/"app/Core/AiRepository.php").read_text(encoding="utf-8")
        cls.ai=(ROOT/"app/Modules/AiModule.php").read_text(encoding="utf-8")
        cls.crm=(ROOT/"app/Modules/CrmModule.php").read_text(encoding="utf-8")
        cls.worker=(ROOT/"engine/crm_lite.py").read_text(encoding="utf-8")
        cls.boot=(ROOT/"app/bootstrap.php").read_text(encoding="utf-8")
    def test_server_context_resolver_is_loaded(self):
        self.assertIn("AiPageContext.php",self.boot)
        self.assertIn("public const VERSION='v1'",self.ctx)
    def test_context_is_server_validated_and_company_scoped(self):
        self.assertIn("workspace_id=? AND company_id=? AND id=? AND active=1",self.ctx)
        self.assertIn("party_type IN ('customer','both')",self.ctx)
        self.assertIn("Tenant::can('crm.view')",self.ctx)
        self.assertIn("ModuleRegistry::pageEnabled('crm')",self.ctx)
        self.assertIn("'validated'=>true",self.ctx)
    def test_browser_only_submits_typed_refs_not_labels(self):
        self.assertIn("context_type=party&context_id=",self.crm)
        self.assertIn("context_source=crm",self.crm)
        self.assertIn("context_refs_json",self.ai)
        self.assertIn("AiPageContext::decodeRefs",self.ai)
    def test_job_context_persists_resolved_page_context(self):
        self.assertIn("array $contextRefs=[]",self.repo)
        self.assertIn("AiPageContext::resolve($wid,$companyId,$contextRefs)",self.repo)
        self.assertIn("$context['page_context']=$pageContext",self.repo)
    def test_worker_consumes_validated_context_and_fails_mismatch(self):
        self.assertIn('pc.get("validated") is not True',self.worker)
        self.assertIn('e.get("type")=="party"',self.worker)
        self.assertIn("مشتری نوشته‌شده با زمینه انتخاب‌شده صفحه یکسان نیست",self.worker)
        self.assertIn('"page_context_used":bool(context_party(j))',self.worker)
    def test_cycle8_candidate_state(self):
        s=json.loads((ROOT/"docs/ai/04-docops/task_state.json").read_text(encoding="utf-8"))
        self.assertEqual(s["current_milestone"],"v10.5 Page-aware AI / Context Picker")
        self.assertEqual(s["release_gates"]["page_aware_context_slice"],"IMPLEMENTED_CANDIDATE")
        self.assertTrue(s["live_validation_pending"])
if __name__=="__main__":unittest.main()
