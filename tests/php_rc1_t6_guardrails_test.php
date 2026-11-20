<?php
/**
 * Pure PHP guardrails + role matrix smoke (no DB).
 */
require __DIR__ . '/../app/Core/AiProposalGuardrails.php';
require __DIR__ . '/../app/Core/UiRoleActionMatrix.php';
require __DIR__ . '/../app/Core/UiRtlSupport.php';

function t6_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

$ok = AiProposalGuardrails::evaluateFreeText('گزارش مانده سفارش‌ها را بده');
t6_assert($ok['ok'] === true, 'safe free text');

$bad = AiProposalGuardrails::evaluateFreeText('INSERT INTO acc_stock_movements VALUES (1)');
t6_assert($bad['ok'] === false && $bad['reason'] === 'free_text_mutation_blocked', 'block stock SQL free text');

$bad2 = AiProposalGuardrails::evaluateFreeText('please auto-post voucher without approval');
t6_assert($bad2['ok'] === false, 'block auto-post free text');

$prop = AiProposalGuardrails::evaluateToolInvocation('create_warehouse_receipt', 'proposal');
t6_assert($prop['ok'] === true && $prop['requires_human_approval'] === true, 'proposal mode ok');

$direct = AiProposalGuardrails::evaluateToolInvocation('create_warehouse_receipt', 'write');
t6_assert($direct['ok'] === false, 'direct stock write blocked');

$read = AiProposalGuardrails::evaluateToolInvocation('inventory_position', 'read');
t6_assert($read['ok'] === true && $read['requires_human_approval'] === false, 'read tools ok');

$canDeny = static fn(string $p): bool => false;
$contract = AiProposalGuardrails::approvalContract([
    'tool_name' => 'create_warehouse_receipt',
    'status' => 'proposed',
], $canDeny);
t6_assert($contract['checks']['approver_permission'] === false, 'approve permission required');

$roles = UiRoleActionMatrix::roles();
t6_assert(isset($roles['buyer'], $roles['treasurer'], $roles['production_planner']), 'role keys');
t6_assert(UiRoleActionMatrix::requiresPermission('ai_approve') === 'ai.actions.approve', 'ai approve perm');

$visible = UiRoleActionMatrix::visibleActions(static fn(string $p): bool => $p === 'procurement.view');
t6_assert(count($visible) >= 1, 'visible actions with procurement.view');

t6_assert(UiRtlSupport::moneyIrr(1000) !== '', 'money helper');
t6_assert(str_contains(UiRtlSupport::badge('ok', 'ok'), 'ui-badge-ok'), 'badge helper');

$policy = AiProposalGuardrails::policySummary();
t6_assert($policy['mutations'] === 'proposal_only', 'policy mutations');
t6_assert($policy['financial_posting'] === 'never_from_free_text', 'policy posting');

echo "T6_GUARDRAILS_PASS\n";
