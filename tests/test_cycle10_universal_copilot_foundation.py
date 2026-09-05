from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[1]
def read(p): return (ROOT/p).read_text(encoding='utf-8')
class Cycle10UniversalCopilotFoundation(unittest.TestCase):
    def test_global_shell_is_wired_once(self):
        idx=read('index.php');self.assertIn('BusinessCopilot::renderLauncher()',idx);self.assertIn('BusinessCopilot::renderShell()',idx);self.assertIn('business-copilot.js',idx);self.assertIn('business-copilot.css',idx)
        self.assertNotIn('context_type=party&context_id=',read('app/Modules/CrmModule.php'))
    def test_registry_has_initial_p0_providers(self):
        s=read('app/Core/AiEntityRegistry.php')
        for x in ['party.customer','party.supplier','item','sales.document','purchase.document','trade.case','shipment','warehouse','finance.voucher']: self.assertIn("'"+x+"'",s)
        self.assertIn('Tenant::can',s);self.assertIn('workspace_id=? AND company_id=?',s)
    def test_browser_labels_are_not_identity(self):
        s=read('app/Core/AiContextResolver.php');self.assertIn('Browser-provided label',s);self.assertIn("['type'=>$type,'id'=>$id]",s)
    def test_context_envelope_v2_separates_page_and_attached(self):
        s=read('app/Core/AiContextEnvelope.php');self.assertIn("VERSION='v2'",s);self.assertIn("'current_page'",s);self.assertIn("'attached_entities'",s)
        r=read('app/Core/AiRepository.php');self.assertIn("$context['context_envelope']=$envelope",r);self.assertIn('queueCopilotChat',r)
    def test_sidecar_search_preview_do_not_require_llm(self):
        api=read('app/Core/BusinessCopilotApi.php');reg=read('app/Core/AiEntityRegistry.php');self.assertIn("$action==='search'",api);self.assertIn("$action==='preview'",api);self.assertNotIn('provider_gateway',reg.lower());self.assertNotIn('ollama',reg.lower())
    def test_conversation_is_user_scoped(self):
        r=read('app/Core/AiRepository.php');self.assertIn('workspace_id=? AND user_id=?',r);self.assertIn('conversationJobsForUser',r);self.assertIn('requested_by=? AND conversation_id=?',r);self.assertIn('AND company_id=?',r)
    def test_worker_understands_v2_and_legacy_context(self):
        s=read('engine/crm_lite.py');self.assertIn('context_envelope',s);self.assertIn('"v2"',s);self.assertIn('page_context',s);self.assertIn('چند مشتری',s);self.assertIn('Explicit @ attachments outrank implicit current-page context',s)
    def test_customer_360_button_opens_copilot(self):
        s=read('app/Modules/CrmModule.php');self.assertIn('data-copilot-attach',s);self.assertIn('party.customer',s);self.assertIn('از Copilot درباره این مشتری بپرس',s)
    def test_sidecar_state_is_company_scoped_and_reserves_desktop_space(self):
        js=read('assets/business-copilot.js');css=read('assets/business-copilot.css')
        self.assertIn('${cfg.company_id}',js);self.assertIn('body.copilot-open .app',css)
    def test_magic_customer_review_routes_from_attached_context(self):
        s=read('engine/crm_lite.py');self.assertIn('وضعیت معاملات',s);self.assertIn('معاملاتمون',s);self.assertIn('customer_review=',s)
    def test_copilot_api_returns_json_csrf_error(self):
        s=read('app/Core/BusinessCopilotApi.php');self.assertIn("'csrf_mismatch'",s);self.assertIn("'X-Request-ID: '",s)
    def test_mention_search_hotfix_is_visible_and_provider_isolated(self):
        js=read('assets/business-copilot.js');api=read('app/Core/BusinessCopilotApi.php');reg=read('app/Core/AiEntityRegistry.php');idx=read('index.php')
        self.assertIn('در حال جست‌وجو',js);self.assertIn('request_id',js);self.assertIn('HTTP',js);self.assertIn('response.text()',js);self.assertIn('compositionend',js)
        self.assertIn('textSlice',api);self.assertIn('searchWorkspaceDetailed',api);self.assertIn('JSON_INVALID_UTF8_SUBSTITUTE',api)
        self.assertIn('failed_provider_count',reg);self.assertIn('entity search provider failed',reg);self.assertIn("function_exists('mb_substr')",reg)
        self.assertIn('business-copilot.js?v=10.8.0',idx)
    def test_copilot_api_keeps_php80_compatible_return_syntax(self):
        api=read('app/Core/BusinessCopilotApi.php');wrapper=read('copilot_api.php')
        self.assertIn('final class BusinessCopilotApi',api)
        self.assertNotIn(': never',api)
        self.assertIn('BusinessCopilotApi::handle()',wrapper)
        self.assertIn("'Content-Type: application/json; charset=utf-8'",api)
    def test_copilot_uses_main_entrypoint_bridge_and_safe_json(self):
        idx=read('index.php');boot=read('app/bootstrap.php');cop=read('app/Core/BusinessCopilot.php');api=read('app/Core/BusinessCopilotApi.php')
        self.assertIn("isset($_GET['copilot_api'])",idx)
        self.assertLess(idx.index("isset($_GET['copilot_api'])"),idx.index("$page = $_GET['page']"))
        self.assertIn("BusinessCopilotApi.php",boot)
        self.assertIn("'endpoint'=>'index.php?copilot_api=1'",cop)
        self.assertIn("JSON_INVALID_UTF8_SUBSTITUTE",api)
        self.assertIn("'json_encode_failed'",api)
        self.assertIn("'server_error'",api)
    def test_inventory_procurement_has_company_selector(self):
        s=read('app/Modules/InventoryProcurementModule.php')
        self.assertIn("'inv_select_company'",s)
        self.assertIn('AccountingRepository::companies()',s)
        self.assertIn('name="return_page"',s)
        self.assertIn("in_array($return,['inventory','procurement'],true)",s)
        self.assertIn('acc-company-bar',s)
        self.assertIn("self::companyBar((string)($_GET['page']??'inventory'))",s)

if __name__=='__main__': unittest.main()
