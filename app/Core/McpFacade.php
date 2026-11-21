<?php
/**
 * Authenticated MCP facade over AiToolRegistry.
 *
 * Not a second ERP engine: tools/resources are projected from AiToolRegistry
 * descriptors only. Mutations remain proposal-only; human approval stays in
 * the web app (AiRepository::approveProposal → executeProposal).
 *
 * DISABLED BY DEFAULT until security acceptance (setting mcp_enabled=1).
 */
final class McpFacade
{
    /** Upstream MCP protocol revision this facade speaks. */
    public const PROTOCOL_VERSION = '2024-11-05';

    /** ERPSMART facade contract version (additive to PROTOCOL_VERSION). */
    public const FACADE_VERSION = 'erp-mcp-facade-v1';

    public const SETTING_KEY = 'mcp_enabled';

    /** Tools that must never be exposed even if somehow registered. */
    public const FORBIDDEN_TOOL_NAMES = [
        'shell', 'bash', 'exec', 'system', 'powershell',
        'sql', 'raw_sql', 'query_sql', 'database_query',
        'filesystem', 'read_file', 'write_file', 'list_dir', 'fs',
    ];

    /** MCP methods that would bypass human approval — always rejected. */
    public const FORBIDDEN_METHODS = [
        'tools/approve',
        'tools/execute_proposal',
        'proposals/approve',
        'proposals/execute',
        'admin/execute',
    ];

    public static function isEnabled(?callable $settingGet = null): bool
    {
        if ($settingGet !== null) {
            return (string)$settingGet(self::SETTING_KEY, '0') === '1';
        }
        if (function_exists('setting')) {
            return (string)setting(self::SETTING_KEY, '0') === '1';
        }
        return false;
    }

    public static function policySummary(?callable $settingGet = null): array
    {
        return [
            'protocol_version' => self::PROTOCOL_VERSION,
            'facade_version' => self::FACADE_VERSION,
            'enabled' => self::isEnabled($settingGet),
            'enabled_by_default' => false,
            'mutations' => 'proposal_only',
            'human_approval_required' => true,
            'approval_executor' => 'AiRepository::approveProposal → AiToolRegistry::executeProposal',
            'forbidden_tool_classes' => ['shell', 'sql', 'filesystem'],
            'engine' => 'AiToolRegistry_facade_only',
        ];
    }

    /**
     * Project allowlisted MCP tool descriptors from AiToolRegistry.
     * @param list<array<string,mixed>>|null $registryDescriptors
     * @return list<array<string,mixed>>
     */
    public static function allowlistedTools(?array $registryDescriptors = null): array
    {
        $rows = $registryDescriptors;
        if ($rows === null) {
            if (!class_exists('AiToolRegistry')) {
                return [];
            }
            $rows = AiToolRegistry::descriptors();
        }
        $out = [];
        foreach ($rows as $d) {
            if (!is_array($d)) {
                continue;
            }
            $name = strtolower(trim((string)($d['name'] ?? '')));
            if ($name === '' || self::isForbiddenToolName($name)) {
                continue;
            }
            $mode = strtolower(trim((string)($d['mode'] ?? 'read')));
            if (!in_array($mode, ['read', 'proposal'], true)) {
                continue;
            }
            $out[] = [
                'name' => (string)$d['name'],
                'description' => (string)($d['description'] ?? ''),
                'inputSchema' => is_array($d['parameters'] ?? null) ? $d['parameters'] : ['type' => 'object', 'properties' => []],
                'annotations' => [
                    'mode' => $mode,
                    'risk' => (string)($d['risk'] ?? 'low'),
                    'write_path' => $mode === 'proposal' ? 'proposal_only' : 'none',
                    'requires_human_approval' => $mode === 'proposal',
                ],
            ];
        }
        return $out;
    }

    /**
     * Read-only MCP resources (metadata only — no filesystem).
     * @return list<array<string,mixed>>
     */
    public static function allowlistedResources(): array
    {
        return [
            [
                'uri' => 'erp://policy/mcp',
                'name' => 'mcp_policy',
                'description' => 'MCP facade security policy and protocol versions',
                'mimeType' => 'application/json',
            ],
            [
                'uri' => 'erp://policy/ai-tools',
                'name' => 'ai_tool_policy',
                'description' => 'Mutation and approval policy for ERP AI tools',
                'mimeType' => 'application/json',
            ],
        ];
    }

    public static function isForbiddenToolName(string $name): bool
    {
        $n = strtolower(trim($name));
        if (in_array($n, self::FORBIDDEN_TOOL_NAMES, true)) {
            return true;
        }
        foreach (['shell', 'sql', 'filesystem', 'exec', 'bash'] as $needle) {
            if ($n === $needle || str_starts_with($n, $needle . '_') || str_ends_with($n, '_' . $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Authenticate MCP request context. Pure — no DB.
     *
     * Expected $auth:
     *  - ok (bool)
     *  - workspace_id (int)
     *  - allowed_company_ids (list<int>) empty = none allowed
     *  - permissions (list<string>)
     *  - error (string) when ok=false
     *
     * @param array{ok?:bool,workspace_id?:int,allowed_company_ids?:list<int>,permissions?:list<string>,error?:string} $auth
     * @return array{ok:bool,error?:string,code?:string,workspace_id?:int,allowed_company_ids?:list<int>,permissions?:list<string>}
     */
    public static function assertAuth(array $auth): array
    {
        if (empty($auth['ok'])) {
            return [
                'ok' => false,
                'error' => (string)($auth['error'] ?? 'unauthorized'),
                'code' => 'unauthorized',
            ];
        }
        $wid = (int)($auth['workspace_id'] ?? 0);
        if ($wid <= 0) {
            return ['ok' => false, 'error' => 'workspace_required', 'code' => 'unauthorized'];
        }
        $perms = $auth['permissions'] ?? [];
        if (!is_array($perms) || !in_array('ai.use', $perms, true)) {
            return ['ok' => false, 'error' => 'missing_permission_ai.use', 'code' => 'forbidden'];
        }
        $companies = $auth['allowed_company_ids'] ?? [];
        if (!is_array($companies)) {
            $companies = [];
        }
        $companies = array_values(array_filter(array_map('intval', $companies), static fn(int $id): bool => $id > 0));
        return [
            'ok' => true,
            'workspace_id' => $wid,
            'allowed_company_ids' => $companies,
            'permissions' => array_values(array_map('strval', $perms)),
        ];
    }

    /**
     * Company scope gate — wrong company fails closed.
     * @param list<int> $allowedCompanyIds
     */
    public static function assertCompanyAllowed(int $companyId, array $allowedCompanyIds): array
    {
        if ($companyId <= 0) {
            return ['ok' => false, 'error' => 'company_context_required', 'code' => 'company_required'];
        }
        if (!in_array($companyId, array_map('intval', $allowedCompanyIds), true)) {
            return ['ok' => false, 'error' => 'company_not_allowed', 'code' => 'wrong_company'];
        }
        return ['ok' => true];
    }

    /**
     * Resolve a tools/call against allowlist. Never executes domain mutations.
     *
     * @param list<array<string,mixed>>|null $registryDescriptors
     * @return array{ok:bool,error?:string,code?:string,tool?:array,mode?:string,action?:string}
     */
    public static function resolveToolCall(string $toolName, array $arguments, ?array $registryDescriptors = null): array
    {
        $toolName = trim($toolName);
        if ($toolName === '' || self::isForbiddenToolName($toolName)) {
            return ['ok' => false, 'error' => 'tool_forbidden', 'code' => 'forged_or_forbidden_tool'];
        }

        // Approval / direct-execute bypass attempts inside arguments.
        foreach (['execute_immediately', 'skip_approval', 'auto_approve', 'force_execute', 'bypass_approval'] as $flag) {
            if (!empty($arguments[$flag])) {
                return ['ok' => false, 'error' => 'approval_bypass_blocked', 'code' => 'approval_bypass'];
            }
        }

        $allowed = self::allowlistedTools($registryDescriptors);
        $match = null;
        foreach ($allowed as $t) {
            if (($t['name'] ?? '') === $toolName) {
                $match = $t;
                break;
            }
        }
        if ($match === null) {
            return ['ok' => false, 'error' => 'tool_not_allowlisted', 'code' => 'forged_or_unknown_tool'];
        }

        $mode = (string)($match['annotations']['mode'] ?? 'read');
        if ($mode === 'proposal') {
            return [
                'ok' => true,
                'tool' => $match,
                'mode' => 'proposal',
                'action' => 'store_proposal_only',
            ];
        }
        return [
            'ok' => true,
            'tool' => $match,
            'mode' => 'read',
            'action' => 'execute_read',
        ];
    }

    /**
     * Handle one JSON-RPC-style MCP request after auth is established.
     *
     * @param array{ok:bool,workspace_id?:int,allowed_company_ids?:list<int>,permissions?:list<string>} $auth
     * @param list<array<string,mixed>>|null $registryDescriptors
     * @param callable|null $readExecutor fn(string $tool, array $args, int $companyId, int $workspaceId): array
     * @param callable|null $proposalStore fn(string $tool, array $args, int $companyId, int $workspaceId): array
     */
    public static function handle(
        array $request,
        array $auth,
        ?callable $settingGet = null,
        ?array $registryDescriptors = null,
        ?callable $readExecutor = null,
        ?callable $proposalStore = null
    ): array {
        if (!self::isEnabled($settingGet)) {
            return self::errorResult($request, -32000, 'mcp_disabled', 'MCP facade is disabled by default');
        }

        $gate = self::assertAuth($auth);
        if (!$gate['ok']) {
            $httpish = ($gate['code'] ?? '') === 'forbidden' ? -32003 : -32001;
            return self::errorResult($request, $httpish, (string)($gate['code'] ?? 'unauthorized'), (string)($gate['error'] ?? 'unauthorized'));
        }

        $method = trim((string)($request['method'] ?? ''));
        $params = $request['params'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }
        $id = $request['id'] ?? null;

        if ($method === '' || in_array($method, self::FORBIDDEN_METHODS, true)) {
            return self::errorResult($request, -32601, 'method_not_allowed', $method === '' ? 'method_required' : 'approval_bypass_method_blocked');
        }

        return match ($method) {
            'initialize' => self::okResult($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'serverInfo' => [
                    'name' => 'erpsmart-mcp-facade',
                    'version' => self::FACADE_VERSION,
                ],
                'capabilities' => [
                    'tools' => new stdClass(),
                    'resources' => new stdClass(),
                ],
                'instructions' => 'Facade over AiToolRegistry. Writes create proposals only; human approval required in ERP UI.',
            ]),
            'ping' => self::okResult($id, ['ok' => true]),
            'tools/list' => self::okResult($id, ['tools' => self::allowlistedTools($registryDescriptors)]),
            'resources/list' => self::okResult($id, ['resources' => self::allowlistedResources()]),
            'resources/read' => self::handleResourceRead($id, $params, $settingGet),
            'tools/call' => self::handleToolsCall($id, $params, $gate, $registryDescriptors, $readExecutor, $proposalStore),
            default => self::errorResult($request, -32601, 'method_not_found', 'Unknown MCP method'),
        };
    }

    private static function handleResourceRead($id, array $params, ?callable $settingGet): array
    {
        $uri = trim((string)($params['uri'] ?? ''));
        $allowed = [];
        foreach (self::allowlistedResources() as $r) {
            $allowed[(string)$r['uri']] = $r;
        }
        if ($uri === '' || !isset($allowed[$uri])) {
            return self::errorResult(['id' => $id], -32004, 'resource_not_allowlisted', 'Resource URI not allowlisted');
        }
        $body = match ($uri) {
            'erp://policy/mcp' => self::policySummary($settingGet),
            'erp://policy/ai-tools' => [
                'mutations' => 'proposal_only',
                'human_approval_required' => true,
                'forbidden' => self::FORBIDDEN_TOOL_NAMES,
                'executor' => 'AiToolRegistry after human approval only',
            ],
            default => [],
        };
        return self::okResult($id, [
            'contents' => [[
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]],
        ]);
    }

    /**
     * @param array{workspace_id:int,allowed_company_ids:list<int>,permissions:list<string>} $gate
     */
    private static function handleToolsCall(
        $id,
        array $params,
        array $gate,
        ?array $registryDescriptors,
        ?callable $readExecutor,
        ?callable $proposalStore
    ): array {
        $name = trim((string)($params['name'] ?? ''));
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            $arguments = [];
        }

        $companyId = (int)($arguments['company_id'] ?? $params['company_id'] ?? 0);
        unset($arguments['company_id']);

        $companyGate = self::assertCompanyAllowed($companyId, $gate['allowed_company_ids']);
        if (!$companyGate['ok']) {
            return self::errorResult(['id' => $id], -32002, (string)$companyGate['code'], (string)$companyGate['error']);
        }

        $resolved = self::resolveToolCall($name, $arguments, $registryDescriptors);
        if (!$resolved['ok']) {
            return self::errorResult(['id' => $id], -32005, (string)$resolved['code'], (string)$resolved['error']);
        }

        $wid = (int)$gate['workspace_id'];
        if (($resolved['action'] ?? '') === 'store_proposal_only') {
            if ($proposalStore === null) {
                $payload = [
                    'status' => 'awaiting_human_approval',
                    'tool' => $name,
                    'mode' => 'proposal',
                    'company_id' => $companyId,
                    'note' => 'Proposal path only; MCP cannot approve or execute.',
                ];
            } else {
                $payload = $proposalStore($name, $arguments, $companyId, $wid);
            }
            return self::okResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
                'isError' => false,
                'structuredContent' => $payload,
            ]);
        }

        if ($readExecutor === null) {
            return self::errorResult(['id' => $id], -32006, 'read_executor_unavailable', 'Read executor not bound');
        }
        try {
            $payload = $readExecutor($name, $arguments, $companyId, $wid);
        } catch (Throwable $e) {
            return self::errorResult(['id' => $id], -32007, 'tool_execution_failed', 'tool_execution_failed');
        }
        return self::okResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'isError' => false,
            'structuredContent' => $payload,
        ]);
    }

    private static function okResult($id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function errorResult(array $request, int $code, string $errCode, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $request['id'] ?? null,
            'error' => [
                'code' => $code,
                'message' => $message,
                'data' => ['error_code' => $errCode],
            ],
        ];
    }
}
