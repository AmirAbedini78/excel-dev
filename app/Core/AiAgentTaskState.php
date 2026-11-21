<?php
/**
 * Durable agent task state helpers for Agentic Next Wave1.
 * Additive JSON under ai_jobs.result_json.agent_task — no parallel ledger.
 */
final class AiAgentTaskState
{
    public const SCHEMA = 'erpsmart.agent_task.v1';

    public const ACTIVE = [
        'CREATED','VALIDATING','PLANNING','EXECUTING_READS','ANALYZING',
        'PROPOSAL_READY','AWAITING_APPROVAL','EXECUTING_APPROVED_ACTION','VERIFYING',
    ];

    public const TERMINAL = [
        'COMPLETED','FAILED','CANCELLED','EXPIRED','BLOCKED','PARTIALLY_COMPLETED',
    ];

    /** @var array<string,list<string>> */
    private static array $transitions = [
        'CREATED' => ['VALIDATING','FAILED','CANCELLED','EXPIRED','BLOCKED'],
        'VALIDATING' => ['PLANNING','FAILED','CANCELLED','EXPIRED','BLOCKED'],
        'PLANNING' => ['EXECUTING_READS','ANALYZING','FAILED','CANCELLED','EXPIRED','BLOCKED'],
        'EXECUTING_READS' => ['PLANNING','ANALYZING','FAILED','CANCELLED','EXPIRED','BLOCKED','PARTIALLY_COMPLETED'],
        'ANALYZING' => ['PROPOSAL_READY','COMPLETED','PARTIALLY_COMPLETED','FAILED','CANCELLED','EXPIRED','BLOCKED'],
        'PROPOSAL_READY' => ['AWAITING_APPROVAL','FAILED','CANCELLED','EXPIRED','BLOCKED'],
        'AWAITING_APPROVAL' => ['EXECUTING_APPROVED_ACTION','CANCELLED','EXPIRED','FAILED','BLOCKED'],
        'EXECUTING_APPROVED_ACTION' => ['VERIFYING','FAILED','CANCELLED','EXPIRED','BLOCKED','PARTIALLY_COMPLETED'],
        'VERIFYING' => ['COMPLETED','FAILED','PARTIALLY_COMPLETED','BLOCKED'],
        'COMPLETED' => ['COMPLETED'],
        'FAILED' => ['FAILED'],
        'CANCELLED' => ['CANCELLED'],
        'EXPIRED' => ['EXPIRED'],
        'BLOCKED' => ['BLOCKED','VALIDATING'],
        'PARTIALLY_COMPLETED' => ['PARTIALLY_COMPLETED'],
    ];

    public static function newTask(int $companyId, ?string $capability = null, ?int $jobId = null): array
    {
        return [
            'schema' => self::SCHEMA,
            'state' => 'CREATED',
            'company_id' => $companyId,
            'capability' => $capability,
            'job_id' => $jobId ?: null,
            'executed_action_ids' => [],
            'tool_sequence' => [],
            'planner_calls' => 0,
            'evidence_count' => 0,
            'completed_mutation_keys' => [],
            'mutation_attempts' => 0,
            'block_reason' => null,
            'invalid_tool_rejections' => [],
            'transition_log' => [],
            'updated_at' => gmdate('c'),
        ];
    }

    public static function extract(?string $resultJson): ?array
    {
        if ($resultJson === null || trim($resultJson) === '') {
            return null;
        }
        $meta = json_decode($resultJson, true);
        if (!is_array($meta)) {
            return null;
        }
        $task = $meta['agent_task'] ?? null;
        if (is_array($task) && ($task['schema'] ?? '') === self::SCHEMA) {
            return $task;
        }
        $live = is_array($meta['live'] ?? null) ? $meta['live'] : [];
        $details = is_array($live['details'] ?? null) ? $live['details'] : [];
        $nested = $details['agent_task'] ?? null;
        if (is_array($nested) && ($nested['schema'] ?? '') === self::SCHEMA) {
            return $nested;
        }
        return null;
    }

    public static function canTransition(string $from, string $to): bool
    {
        $allowed = self::$transitions[$from] ?? [];
        return in_array($to, $allowed, true);
    }

    public static function transition(array $task, string $to, ?string $reason = null): array
    {
        $from = (string)($task['state'] ?? 'CREATED');
        if ($from === $to) {
            $task['updated_at'] = gmdate('c');
            return $task;
        }
        if (!self::canTransition($from, $to)) {
            throw new RuntimeException("illegal_agent_task_transition:{$from}->{$to}");
        }
        $log = is_array($task['transition_log'] ?? null) ? $task['transition_log'] : [];
        $entry = ['from' => $from, 'to' => $to, 'at' => gmdate('c')];
        if ($reason !== null && $reason !== '') {
            $entry['reason'] = mb_substr($reason, 0, 200);
        }
        $log[] = $entry;
        $task['transition_log'] = array_slice($log, -40);
        $task['state'] = $to;
        $task['updated_at'] = gmdate('c');
        if (in_array($to, ['FAILED','BLOCKED','CANCELLED','EXPIRED'], true) && $reason) {
            $task['block_reason'] = mb_substr($reason, 0, 300);
        }
        return $task;
    }

    public static function rejectInvalidTool(array $task, string $toolId, string $source = 'control_plane'): array
    {
        $rejections = is_array($task['invalid_tool_rejections'] ?? null) ? $task['invalid_tool_rejections'] : [];
        $rejections[] = [
            'tool_id' => mb_substr($toolId, 0, 120),
            'source' => mb_substr($source, 0, 40),
            'at' => gmdate('c'),
        ];
        $task['invalid_tool_rejections'] = array_slice($rejections, -20);
        $state = (string)($task['state'] ?? 'CREATED');
        if (!in_array($state, self::TERMINAL, true)) {
            try {
                $task = self::transition($task, 'BLOCKED', 'invalid_tool:'.$toolId);
            } catch (Throwable $e) {
                $task['state'] = 'BLOCKED';
                $task['block_reason'] = 'invalid_tool:'.$toolId;
                $task['updated_at'] = gmdate('c');
            }
        } else {
            $task['block_reason'] = 'invalid_tool:'.$toolId;
        }
        return $task;
    }

    public static function mutationAlreadyApplied(array $task, string $key): bool
    {
        $key = trim($key);
        if ($key === '') {
            return false;
        }
        foreach ((array)($task['completed_mutation_keys'] ?? []) as $k) {
            if ((string)$k === $key) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:array,1:bool} [task, should_apply] */
    public static function beginMutation(array $task, string $idempotencyKey): array
    {
        $key = trim($idempotencyKey);
        if ($key === '') {
            throw new RuntimeException('mutation_idempotency_key_required');
        }
        $task['mutation_attempts'] = (int)($task['mutation_attempts'] ?? 0) + 1;
        if (self::mutationAlreadyApplied($task, $key)) {
            return [$task, false];
        }
        $state = (string)($task['state'] ?? '');
        if ($state === 'PROPOSAL_READY') {
            $task = self::transition($task, 'AWAITING_APPROVAL', 'mutation_begin');
            $state = 'AWAITING_APPROVAL';
        }
        if ($state === 'AWAITING_APPROVAL') {
            $task = self::transition($task, 'EXECUTING_APPROVED_ACTION', 'mutation_begin');
        }
        return [$task, true];
    }

    public static function completeMutation(array $task, string $idempotencyKey): array
    {
        $key = trim($idempotencyKey);
        if ($key === '') {
            throw new RuntimeException('mutation_idempotency_key_required');
        }
        $keys = [];
        foreach ((array)($task['completed_mutation_keys'] ?? []) as $k) {
            $keys[] = (string)$k;
        }
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
        }
        $task['completed_mutation_keys'] = array_slice($keys, -64);
        if ((string)($task['state'] ?? '') === 'EXECUTING_APPROVED_ACTION') {
            $task = self::transition($task, 'VERIFYING', 'mutation_applied');
        }
        return $task;
    }

    public static function recordDuplicateCompletion(array $task, string $idempotencyKey): array
    {
        if (self::mutationAlreadyApplied($task, $idempotencyKey)) {
            $task['updated_at'] = gmdate('c');
            return $task;
        }
        return self::completeMutation($task, $idempotencyKey);
    }

    public static function requireCompanyRevalidation(array $task, int $jobCompanyId): bool
    {
        $prior = (int)($task['company_id'] ?? 0);
        return $prior > 0 && $jobCompanyId > 0 && $prior !== $jobCompanyId;
    }

    public static function revalidateForCompanyChange(array $task, int $jobCompanyId): array
    {
        if (!self::requireCompanyRevalidation($task, $jobCompanyId)) {
            return $task;
        }
        $task['company_id'] = $jobCompanyId;
        $task['block_reason'] = 'company_changed';
        $task['executed_action_ids'] = [];
        $task['tool_sequence'] = [];
        $task['planner_calls'] = 0;
        $task['evidence_count'] = 0;
        $current = (string)($task['state'] ?? 'CREATED');
        if (in_array($current, self::TERMINAL, true) && $current !== 'BLOCKED') {
            $task['state'] = 'BLOCKED';
            return self::transition($task, 'VALIDATING', 'company_changed');
        }
        if ($current === 'BLOCKED') {
            return self::transition($task, 'VALIDATING', 'company_changed');
        }
        if ($current === 'CREATED') {
            return self::transition($task, 'VALIDATING', 'company_changed');
        }
        $log = is_array($task['transition_log'] ?? null) ? $task['transition_log'] : [];
        $log[] = ['from' => $current, 'to' => 'VALIDATING', 'at' => gmdate('c'), 'reason' => 'company_changed'];
        $task['transition_log'] = array_slice($log, -40);
        $task['state'] = 'VALIDATING';
        $task['updated_at'] = gmdate('c');
        return $task;
    }

    public static function resumeAfterLeaseRecovery(array $task, int $jobCompanyId): array
    {
        if (self::requireCompanyRevalidation($task, $jobCompanyId)) {
            return self::revalidateForCompanyChange($task, $jobCompanyId);
        }
        $state = (string)($task['state'] ?? 'CREATED');
        if (in_array($state, ['COMPLETED','CANCELLED','FAILED','EXPIRED'], true)) {
            return $task;
        }
        if ($state === 'EXECUTING_APPROVED_ACTION') {
            return self::transition($task, 'VERIFYING', 'lease_recovery_mid_mutation');
        }
        if ($state === 'CREATED') {
            return self::transition($task, 'VALIDATING', 'lease_recovery');
        }
        return $task;
    }

    /**
     * Merge live heartbeat + durable agent_task without wiping prior task state.
     */
    public static function mergeResultJson(?string $existingJson, array $live, ?array $agentTask = null): string
    {
        $base = [];
        if ($existingJson !== null && trim($existingJson) !== '') {
            $decoded = json_decode($existingJson, true);
            if (is_array($decoded)) {
                $base = $decoded;
            }
        }
        $prior = self::extract($existingJson);
        $base['live'] = $live;
        if (is_array($agentTask) && ($agentTask['schema'] ?? '') === self::SCHEMA) {
            $base['agent_task'] = $agentTask;
        } elseif ($prior !== null) {
            $base['agent_task'] = $prior;
        }
        return json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
