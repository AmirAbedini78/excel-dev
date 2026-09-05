from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[1]
def read(p): return (ROOT/p).read_text(encoding='utf-8')

class Cycle11WorkspaceCopilotMvp(unittest.TestCase):
    def test_registry_v2_has_company_and_business_categories(self):
        s=read('app/Core/AiEntityRegistry.php')
        self.assertIn("VERSION='v2'",s)
        for entity_type in ['company','party.customer','party.supplier','contact','crm.opportunity','crm.activity','sales.document','delivery','purchase.document','trade.case','shipment','item','warehouse','inventory.receipt','finance.voucher','cash.account','check']:
            self.assertIn("'"+entity_type+"'=>",s)
        for key in ['organization','parties','commerce','trade','inventory','finance']:
            self.assertIn("'"+key+"'=>",s)
        self.assertIn('category_title',s)
        self.assertIn('type_title',s)
        self.assertIn("elseif($type==='contact')",s)
        self.assertIn("elseif($type==='crm.opportunity')",s)
        self.assertIn("elseif($type==='crm.activity')",s)
        self.assertIn("elseif($type==='delivery')",s)
        self.assertIn("elseif($type==='inventory.receipt')",s)
        self.assertIn("elseif($type==='cash.account')",s)
        self.assertIn("elseif($type==='check')",s)

    def test_workspace_search_spans_active_companies_and_keeps_permission_checks(self):
        s=read('app/Core/AiEntityRegistry.php')
        self.assertIn('searchWorkspaceDetailed',s)
        self.assertIn('workspaceCompanies',s)
        self.assertIn("'company_id'=>$cid",s)
        self.assertIn("'company_name'=>$companyName",s)
        self.assertIn('Tenant::can',s)
        self.assertIn('ModuleRegistry::pageEnabled',s)
        self.assertIn('SEARCH_TOTAL=120',s)

    def test_api_search_is_workspace_global_and_conversations_remain_company_scoped(self):
        s=read('app/Core/BusinessCopilotApi.php')
        self.assertIn('AiEntityRegistry::searchWorkspaceDetailed',s)
        self.assertIn("$action==='conversations'",s)
        self.assertIn("c.workspace_id=? AND c.user_id=? AND c.company_id=?",s)
        self.assertIn("c.status='active'",s)
        self.assertIn('conversationJobsForUser($id,60,$cid)',s)

    def test_sidecar_exposes_company_scope_conversation_switcher_and_new_chat(self):
        s=read('app/Core/BusinessCopilot.php')
        self.assertIn("'companies'=>$companies",s)
        self.assertIn('data-copilot-company',s)
        self.assertIn('data-copilot-conversation',s)
        self.assertIn('data-copilot-new',s)
        self.assertIn('شرکت گفتگو',s)
        self.assertIn('گفتگوی جدید',s)

    def test_browser_migrates_legacy_state_and_keeps_conversations_per_company(self):
        s=read('assets/business-copilot.js')
        self.assertIn('erpsmart:copilot:v2:',s)
        self.assertIn('conversation_ids',s)
        self.assertIn('scope_company_id',s)
        self.assertIn('legacyKey',s)
        self.assertIn('refreshConversations',s)
        self.assertIn('restoreLatest',s)

    def test_foreign_company_entity_switches_copilot_scope_not_page_or_mixed_context(self):
        s=read('assets/business-copilot.js')
        self.assertIn('targetCid!==scopeId()',s)
        self.assertIn('await setScope(targetCid',s)
        self.assertIn('Number(cfg.company_id)===cid',s)
        self.assertIn('page_context_refs_json',s)
        self.assertIn('state.refs=[]',s)

    def test_mention_browser_is_collapsible_category_then_type(self):
        js=read('assets/business-copilot.js');css=read('assets/business-copilot.css')
        self.assertIn("document.createElement('details')",js)
        self.assertIn('copilot-mention-category',js)
        self.assertIn('copilot-mention-type-head',js)
        self.assertIn('company_name',js)
        self.assertIn('.copilot-mention-category',css)
        self.assertIn('.copilot-mention-item em',css)

    def test_company_itself_is_mentionable_and_previewable(self):
        s=read('app/Core/AiEntityRegistry.php')
        self.assertIn("if($type==='company')",s)
        self.assertIn('companyEntity',s)
        self.assertIn("'company'=>'index.php?page=industrial&company_id='",s)
        self.assertIn('شناسه ملی',s)
        self.assertIn('پرونده بازرگانی',s)

    def test_grounded_quick_prompts_are_visible_but_not_auto_executed(self):
        php=read('app/Core/BusinessCopilot.php');js=read('assets/business-copilot.js')
        for label in ['بریف مدیرعامل','ریسک بازرگانی','ریسک موجودی','نقدینگی و وصول']:
            self.assertIn(label,php)
        self.assertIn('data-copilot-template',php)
        self.assertIn("input.value=input.value.trim()?",js)
        self.assertNotIn("data-copilot-template-auto-send",php)

    def test_assets_are_cache_busted_for_cycle11(self):
        idx=read('index.php')
        self.assertIn('business-copilot.css?v=10.8.0',idx)
        self.assertIn('business-copilot.js?v=10.8.0',idx)

if __name__=='__main__': unittest.main()
