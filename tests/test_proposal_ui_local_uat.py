#!/usr/bin/env python3
"""Local proposal UI contract UAT without browser credentials."""
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
JS = (ROOT / "assets" / "business-copilot.js").read_text(encoding="utf-8")


class ProposalUiLocalUat(unittest.TestCase):
    def test_render_supports_multi_card_and_tool_label(self) -> None:
        self.assertIn("copilot-proposal-card", JS)
        self.assertIn("copilot-proposal-tool", JS)
        self.assertIn("data-proposal-approve", JS)
        self.assertIn("data-proposal-reject", JS)
        self.assertIn("حذف فیزیکی اسناد مالی از این مسیر ممکن نیست", JS)

    def test_no_hard_delete_button_copy(self) -> None:
        self.assertNotIn("حذف قطعی", JS)
        self.assertNotIn("physical_delete=true", JS)


if __name__ == "__main__":
    unittest.main(verbosity=2)
