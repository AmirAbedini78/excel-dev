from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


def read(p: str) -> str:
    return (ROOT / p).read_text(encoding='utf-8')


class Wave4UniversalAiWorkspace(unittest.TestCase):
    def test_workspace_assets_exist_and_expose_api(self):
        js = read('assets/universal-ai-workspace.js')
        css = read('assets/universal-ai-workspace.css')
        self.assertIn('ERPSMART_AI_WORKSPACE', js)
        self.assertIn('CREATED', js)
        self.assertIn('AWAITING_APPROVAL', js)
        self.assertIn('LIVE_ERP_FACT', js)
        self.assertIn('MODEL_INTERPRETATION', js)
        self.assertIn('پیشنهاد — اجرا نشده', js)
        self.assertIn('toJalali', js)
        self.assertIn('formatMoney', js)
        self.assertIn('ai-ws-progress', css)
        self.assertIn('ai-ws-proposal-card', css)
        self.assertIn('ai-ws-evidence', css)

    def test_no_fictitious_progress_without_agent_task(self):
        js = read('assets/universal-ai-workspace.js')
        self.assertIn('do not invent task-machine steps', js)
        self.assertIn('Never invent execution', js)
        self.assertIn("=== 'proposed'", js)
        self.assertIn('executed: false', js)

    def test_sidecar_loads_workspace_and_keeps_copilot(self):
        php = read('app/Core/BusinessCopilot.php')
        idx = read('index.php')
        self.assertIn('data-universal-ai-workspace', php)
        self.assertIn('فضای کار هوشمند', php)
        self.assertIn('universal-ai-workspace.js', php)
        self.assertIn('universal-ai-workspace.css', php)
        self.assertIn('display_currency', php)
        self.assertIn('jalali_today', php)
        self.assertIn('IranLocalizationMoney::companyDisplayContext', php)
        self.assertIn('business-copilot.js?v=10.12.0', idx)
        self.assertIn('BusinessCopilot::renderShell()', idx)

    def test_api_enriches_job_without_touching_repository_protocol(self):
        api = read('app/Core/BusinessCopilotApi.php')
        self.assertIn('enrichWorkspaceJob', api)
        self.assertIn("'agent_task'", api)
        self.assertIn("'evidence'", api)
        self.assertIn("'executed'", api)
        # Must not invent states — only pass through result_json
        self.assertIn("$meta['agent_task']", api)

    def test_copilot_js_wires_workspace_helpers(self):
        js = read('assets/business-copilot.js')
        self.assertIn('ERPSMART_AI_WORKSPACE', js)
        self.assertIn('applyJobUi', js)
        self.assertIn('renderProposalCard', js)
        self.assertIn('markProposalResult', js)
        self.assertIn('moneyCtx', js)
        self.assertIn('فضای کار هوشمند آماده است', js)
        self.assertIn('هنوز اجرا نشده', js)

    def test_ux_doc_present(self):
        doc = read('docs/ai/agentic-next/09-UNIVERSAL-AI-UX.md')
        self.assertIn('Universal AI UX', doc)
        self.assertIn('No fictitious progress', doc)
        self.assertIn('AWAITING_APPROVAL', doc)
        self.assertIn('Blockers for `AI4_ACCEPTED`', doc)

    def test_browser_evidence_helpers_are_synthetic_only(self):
        seed = read('docs/ai/agentic-next/evidence/browser/_seed_wave4_workspace.php')
        journey = read('docs/ai/agentic-next/evidence/browser/wave4_workspace_journey.py')
        self.assertIn('synthetic', seed.lower())
        self.assertIn('NOT production', seed)
        self.assertIn('wave4', journey.lower())
        self.assertIn('proposed', journey)


if __name__ == '__main__':
    unittest.main()
