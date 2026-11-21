<?php
/**
 * Safe-AI guardrails for RC1 (T6).
 * AI may propose typed tools only; stock/GL mutations require human approval + domain executor.
 * Free-text never mutates ledgers.
 */
final class AiProposalGuardrails
{
    public const VERSION = 'rc1-t6-ai-guardrails';

    /** Tools that touch stock or GL when executed after approval. */
    public const STOCK_OR_GL_TOOLS = [
        'create_warehouse_receipt',
        'reserve_sales_stock',
        'deliver_sales_stock',
        'create_voucher_draft',
        'create_check',
        'create_purchase_invoice_draft',
        'create_o2c_invoice_from_delivery',
        'add_trade_cost',
    ];

    /** Patterns that must never be treated as executable mutation instructions. */
    public const FREE_TEXT_MUTATION_PATTERNS = [
        '/\b(INSERT|UPDATE|DELETE)\s+INTO\s+acc_(stock_movements|vouchers|voucher_lines)\b/i',
        '/\bpost\s+(voucher|invoice|receipt)\b/i',
        '/\bmutate\s+(stock|gl|ledger)\b/i',
        '/\bبدون\s+تایید\b/u',
        '/\bauto[- ]?post\b/i',
        '/\bdirect\s+stock\s+write\b/i',
        // Privilege / approval-authority escalation via free text (Wave1 AI1)
        '/\bgrant\s+(role\s*=\s*)?admin\b/i',
        '/\bai\.actions\.approve\b/i',
        '/\belevate\s+(permissions?|privileges?|access)\b/i',
        '/\b(role|permission)\s*=\s*admin\b/i',
        '/\bworkspace[_\s-]?owner\b/i',
    ];

    public static function descriptorMode(string $tool): ?string
    {
        if (!class_exists('AiToolRegistry')) {
            return null;
        }
        foreach (AiToolRegistry::descriptors() as $d) {
            if (($d['name'] ?? '') === $tool) {
                return (string)($d['mode'] ?? '');
            }
        }
        return null;
    }

    public static function isStockOrGlTool(string $tool): bool
    {
        return in_array($tool, self::STOCK_OR_GL_TOOLS, true);
    }

    /**
     * Worker path: proposal-mode tools must never execute immediately.
     * @return array{ok:bool,reason:string,requires_human_approval:bool}
     */
    public static function evaluateToolInvocation(string $tool, string $mode): array
    {
        $mode = strtolower(trim($mode));
        if ($mode === 'read' || $mode === 'internal') {
            return ['ok' => true, 'reason' => 'read_or_internal', 'requires_human_approval' => false];
        }
        if ($mode === 'proposal') {
            return [
                'ok' => true,
                'reason' => 'proposal_awaiting_human_approval',
                'requires_human_approval' => true,
            ];
        }
        if (self::isStockOrGlTool($tool)) {
            return [
                'ok' => false,
                'reason' => 'stock_or_gl_tool_requires_proposal_mode',
                'requires_human_approval' => true,
            ];
        }
        return [
            'ok' => false,
            'reason' => 'unknown_or_direct_mutation_blocked',
            'requires_human_approval' => true,
        ];
    }

    /**
     * Reject free-text that attempts to bypass proposal/approval.
     * @return array{ok:bool,reason:string}
     */
    public static function evaluateFreeText(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => true, 'reason' => 'empty'];
        }
        foreach (self::FREE_TEXT_MUTATION_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return ['ok' => false, 'reason' => 'free_text_mutation_blocked'];
            }
        }
        return ['ok' => true, 'reason' => 'no_forbidden_mutation_pattern'];
    }

    public static function assertFreeTextSafe(string $text): void
    {
        $r = self::evaluateFreeText($text);
        if (!$r['ok']) {
            throw new RuntimeException('ai_free_text_mutation_blocked');
        }
    }

    /**
     * Approval gate contract: human + permission + deterministic executor.
     * @return array{ok:bool,checks:array<string,bool>,notes:string[]}
     */
    public static function approvalContract(array $proposal, ?callable $can = null): array
    {
        $can = $can ?? static fn(string $p): bool => class_exists('Tenant') && Tenant::can($p);
        $tool = (string)($proposal['tool_name'] ?? '');
        $status = (string)($proposal['status'] ?? '');
        $mode = self::descriptorMode($tool);
        $checks = [
            'has_tool' => $tool !== '',
            'status_proposed' => $status === 'proposed',
            'mode_is_proposal' => $mode === 'proposal',
            'approver_permission' => $can('ai.actions.approve'),
            'not_free_text_executor' => true,
        ];
        $notes = [
            'Human approval required before AiToolRegistry::executeProposal.',
            'Domain executor re-validates tenancy, permissions, and idempotency.',
            'UI presence of approve button is not authorization.',
        ];
        if (self::isStockOrGlTool($tool)) {
            $notes[] = 'Tool touches stock or GL; free-text bypass is forbidden.';
        }
        $ok = !in_array(false, $checks, true);
        return ['ok' => $ok, 'checks' => $checks, 'notes' => $notes];
    }

    public static function policySummary(): array
    {
        return [
            'version' => self::VERSION,
            'mutations' => 'proposal_only',
            'human_approval_required' => true,
            'financial_posting' => 'never_from_free_text',
            'stock_mutation' => 'never_from_free_text',
            'stock_or_gl_tools' => self::STOCK_OR_GL_TOOLS,
            'executor' => 'AiToolRegistry::executeProposal after AiRepository::approveProposal',
        ];
    }
}
