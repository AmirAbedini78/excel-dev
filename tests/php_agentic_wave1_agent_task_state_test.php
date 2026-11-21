<?php
/**
 * Wave1 AiAgentTaskState pure PHP tests (no DB).
 */
require __DIR__ . '/../app/Core/AiAgentTaskState.php';

function w1_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

$t = AiAgentTaskState::newTask(1, 'supplier.performance', 42);
w1_assert($t['state'] === 'CREATED', 'created');
$t = AiAgentTaskState::transition($t, 'VALIDATING');
$t = AiAgentTaskState::transition($t, 'PLANNING');
$t = AiAgentTaskState::transition($t, 'EXECUTING_READS');
$t = AiAgentTaskState::transition($t, 'ANALYZING');
$t = AiAgentTaskState::transition($t, 'COMPLETED');
w1_assert($t['state'] === 'COMPLETED', 'completed path');

$blocked = AiAgentTaskState::rejectInvalidTool(
    AiAgentTaskState::transition(AiAgentTaskState::newTask(1), 'VALIDATING'),
    'invented_tool_xyz',
    'planner'
);
w1_assert($blocked['state'] === 'BLOCKED', 'invalid tool blocks');
w1_assert(str_contains((string)$blocked['block_reason'], 'invalid_tool'), 'invalid tool reason');

$m = AiAgentTaskState::transition(AiAgentTaskState::newTask(2), 'VALIDATING');
$m = AiAgentTaskState::transition($m, 'PLANNING');
$m = AiAgentTaskState::transition($m, 'ANALYZING');
$m = AiAgentTaskState::transition($m, 'PROPOSAL_READY');
[$m, $apply] = AiAgentTaskState::beginMutation($m, 'key-1');
w1_assert($apply === true, 'first mutation applies');
$m = AiAgentTaskState::completeMutation($m, 'key-1');
[$m2, $apply2] = AiAgentTaskState::beginMutation($m, 'key-1');
w1_assert($apply2 === false, 'duplicate mutation blocked');
$m3 = AiAgentTaskState::recordDuplicateCompletion($m2, 'key-1');
w1_assert(count($m3['completed_mutation_keys']) === 1, 'single mutation key retained');

$mid = AiAgentTaskState::transition(AiAgentTaskState::newTask(3), 'VALIDATING');
foreach (['PLANNING','ANALYZING','PROPOSAL_READY','AWAITING_APPROVAL','EXECUTING_APPROVED_ACTION'] as $s) {
    $mid = AiAgentTaskState::transition($mid, $s);
}
$mid['completed_mutation_keys'] = ['done-key'];
$resumed = AiAgentTaskState::resumeAfterLeaseRecovery($mid, 3);
w1_assert($resumed['state'] === 'VERIFYING', 'mid-mutation lease recovery -> VERIFYING');
[$r2, $should] = AiAgentTaskState::beginMutation($resumed, 'done-key');
w1_assert($should === false, 'recovered task does not re-apply');

$co = AiAgentTaskState::transition(AiAgentTaskState::newTask(10), 'VALIDATING');
$co = AiAgentTaskState::transition($co, 'PLANNING');
$co = AiAgentTaskState::revalidateForCompanyChange($co, 77);
w1_assert($co['state'] === 'VALIDATING' && (int)$co['company_id'] === 77, 'company change revalidates');

$task = AiAgentTaskState::transition(AiAgentTaskState::newTask(1), 'VALIDATING');
$json1 = AiAgentTaskState::mergeResultJson(null, ['stage' => 'a'], $task);
$decoded1 = json_decode($json1, true);
w1_assert(($decoded1['agent_task']['state'] ?? '') === 'VALIDATING', 'merge writes agent_task');
$json2 = AiAgentTaskState::mergeResultJson($json1, ['stage' => 'heartbeat'], null);
$decoded2 = json_decode($json2, true);
w1_assert(($decoded2['agent_task']['state'] ?? '') === 'VALIDATING', 'heartbeat preserves agent_task');
w1_assert(($decoded2['live']['stage'] ?? '') === 'heartbeat', 'heartbeat updates live');

$repo = file_get_contents(__DIR__ . '/../app/Core/AiRepository.php');
w1_assert(str_contains($repo, 'AiAgentTaskState::mergeResultJson'), 'touchLease uses merge helper');

echo "WAVE1_AGENT_TASK_STATE_PASS\n";
