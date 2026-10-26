"""Static integration contracts for the Gemini-on-existing-worktree patch."""
from pathlib import Path
import unittest
R=Path(__file__).resolve().parents[1]
class Contract(unittest.TestCase):
    def test_db_queue_routes_by_capability(self):
        src=(R/'app/Core/AiRepository.php').read_text(encoding='utf-8')
        self.assertIn("'llm.gemini':'llm'",src)
        self.assertEqual(src.count("$context['engine_selection']=$selection"),2)
        self.assertEqual(src.count("VALUES (?,?,?,?, 'agent_chat'"),2)
    def test_no_default_gemini_privilege(self):
        src=(R/'app/Core/AiRepository.php').read_text(encoding='utf-8')
        self.assertIn("$capabilities=['llm','rag','forecast']",src)
        mod=(R/'app/Modules/AiModule.php').read_text(encoding='utf-8')
        self.assertIn("['llm.gemini']",mod)
        self.assertIn("Tenant::requirePermission('ai.workers.manage')",mod)
    def test_server_scoped_selection(self):
        src=(R/'app/Core/BusinessCopilotApi.php').read_text(encoding='utf-8')
        self.assertIn("$_POST['engine']",src)
        self.assertIn("$_POST['model']",src)
        selector=(R/'assets/business-copilot.js').read_text(encoding='utf-8')
        self.assertIn("fd.set('model'",selector)
        config=(R/'app/Core/AiEnginePreferences.php').read_text(encoding='utf-8')
        self.assertIn('Tenant::id()',config)
        self.assertIn("AiEnginePreferences", config)
    def test_no_shared_runtime_volume(self):
        x=(R/'engine/compose.gemini.yaml').read_text(encoding='utf-8')
        self.assertIn('gemini_state:/app/data',x)
        self.assertNotIn('./data:/app/data',x)
