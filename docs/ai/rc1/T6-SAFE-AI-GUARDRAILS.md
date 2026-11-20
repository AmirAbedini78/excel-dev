# T6 Safe AI Guardrails (RC1)

**Code:** `app/Core/AiProposalGuardrails.php`  
**Existing hooks:** `AiToolRegistry::executeForWorker` (proposal mode → store only), `AiRepository::approveProposal` (`ai.actions.approve` + `executeProposal`).

## Policy
1. AI mutations are **proposal-only**.
2. Human approval + scoped permission + deterministic domain executor required.
3. Free-text never mutates `acc_stock_movements` / `acc_vouchers` / GL.
4. UI approve button is not authorization.

## Stock/GL tools (must stay proposal mode)
`create_warehouse_receipt`, `reserve_sales_stock`, `deliver_sales_stock`, `create_voucher_draft`, `create_check`, `create_purchase_invoice_draft`, `create_o2c_invoice_from_delivery`, `add_trade_cost`.

## Cockpit stance
`UiCockpitModule` hard-refuses `ui_cockpit_post_stock`, `ui_cockpit_post_gl`, `ui_cockpit_ai_direct_execute` with HTTP 403.

## Tests
- `tests/test_rc1_t6_platform_ux.py` — source contracts + free-text patterns
- `tests/php_rc1_t6_guardrails_test.php` — pure PHP evaluation via Docker PHP image
