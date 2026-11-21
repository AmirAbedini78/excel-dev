<?php
/**
 * HTTP entry for the ERPSMART MCP facade.
 *
 * Disabled by default (setting mcp_enabled must be explicitly "1").
 * Authenticates via Bearer API token + ai.use permission; company scope is
 * enforced per tools/call. Never exposes shell/SQL/filesystem tools.
 */
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-MCP-Protocol-Version: ' . McpFacade::PROTOCOL_VERSION);
header('X-ERP-MCP-Facade-Version: ' . McpFacade::FACADE_VERSION);

function mcp_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mcp_body(): array
{
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > 1024 * 1024) {
        mcp_json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'payload_too_large']], 413);
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        mcp_json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'invalid_json']], 400);
    }
    return $data;
}

function mcp_bearer_token(): string
{
    $auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        return trim($m[1]);
    }
    return '';
}

function mcp_ensure_workspace_token_schema(): void
{
    pdo()->exec("CREATE TABLE IF NOT EXISTS workspace_api_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,workspace_id INT NOT NULL,user_id INT NULL,name VARCHAR(120) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,token_prefix VARCHAR(32) NOT NULL,scopes VARCHAR(500) NOT NULL,
        expires_at DATETIME NULL,last_used_at DATETIME NULL,last_ip VARCHAR(80) NULL,revoked_at DATETIME NULL,created_at DATETIME NULL,
        INDEX idx_wapi_workspace(workspace_id,revoked_at),INDEX idx_wapi_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Non-exiting auth using workspace API tokens (acct_ws_*). Bad credentials return ok=false.
 */
function mcp_auth_context(): array
{
    $plain = mcp_bearer_token();
    if ($plain === '' || !str_starts_with($plain, 'acct_ws_')) {
        return ['ok' => false, 'error' => 'unauthorized', 'code' => 'unauthorized'];
    }

    try {
        mcp_ensure_workspace_token_schema();
        $hash = hash('sha256', $plain);
        $st = pdo()->prepare(
            "SELECT t.*,u.status user_status
             FROM workspace_api_tokens t LEFT JOIN users u ON u.id=t.user_id
             WHERE t.token_hash=? LIMIT 1"
        );
        $st->execute([$hash]);
        $token = $st->fetch();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'unauthorized', 'code' => 'unauthorized'];
    }

    if (!$token || $token['revoked_at']) {
        return ['ok' => false, 'error' => 'unauthorized', 'code' => 'unauthorized'];
    }
    if ($token['expires_at'] && strtotime((string)$token['expires_at']) < time()) {
        return ['ok' => false, 'error' => 'unauthorized', 'code' => 'unauthorized'];
    }
    if ($token['user_id'] && ($token['user_status'] ?? '') !== 'active') {
        return ['ok' => false, 'error' => 'unauthorized', 'code' => 'unauthorized'];
    }

    $scopes = json_decode((string)($token['scopes'] ?? '[]'), true);
    if (!is_array($scopes) || !in_array('read', $scopes, true)) {
        return ['ok' => false, 'error' => 'missing_permission_ai.use', 'code' => 'forbidden'];
    }

    $workspaceId = (int)($token['workspace_id'] ?? 0);
    if ($workspaceId <= 0) {
        return ['ok' => false, 'error' => 'workspace_required', 'code' => 'unauthorized'];
    }

    $_SESSION['workspace_id'] = $workspaceId;
    if (!empty($token['user_id'])) {
        $_SESSION['user_id'] = (int)$token['user_id'];
    }
    try {
        Tenant::boot();
    } catch (Throwable $e) {
        // Company list may still come from header below.
    }

    $companyHeader = trim((string)($_SERVER['HTTP_X_ERP_COMPANY_IDS'] ?? ''));
    $allowed = [];
    if ($companyHeader !== '') {
        foreach (preg_split('/\s*,\s*/', $companyHeader) as $part) {
            $id = (int)$part;
            if ($id > 0) {
                $allowed[] = $id;
            }
        }
    } elseif (class_exists('AccountingRepository') && method_exists('AccountingRepository', 'companies')) {
        try {
            foreach (AccountingRepository::companies() as $c) {
                $id = (int)($c['id'] ?? 0);
                if ($id > 0) {
                    $allowed[] = $id;
                }
            }
        } catch (Throwable $e) {
            $allowed = [];
        }
    }

    return [
        'ok' => true,
        'workspace_id' => $workspaceId,
        'allowed_company_ids' => array_values(array_unique($allowed)),
        'permissions' => ['ai.use'],
        'user_id' => (int)($token['user_id'] ?? 0),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    mcp_json([
        'ok' => true,
        'protocol_version' => McpFacade::PROTOCOL_VERSION,
        'facade_version' => McpFacade::FACADE_VERSION,
        'enabled' => McpFacade::isEnabled(),
        'enabled_by_default' => false,
        'policy' => McpFacade::policySummary(),
    ], McpFacade::isEnabled() ? 200 : 503);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mcp_json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'method_not_allowed']], 405);
}

$request = mcp_body();
$auth = mcp_auth_context();

$readExecutor = static function (string $tool, array $args, int $companyId, int $workspaceId): array {
    $job = [
        'id' => 0,
        'workspace_id' => $workspaceId,
        'company_id' => $companyId,
    ];
    return AiToolRegistry::executeForWorker($job, $tool, $args, 'mcp_' . bin2hex(random_bytes(8)));
};

$proposalStore = static function (string $tool, array $args, int $companyId, int $workspaceId): array {
    $job = [
        'id' => 0,
        'workspace_id' => $workspaceId,
        'company_id' => $companyId,
    ];
    // executeForWorker already stores proposals for proposal-mode tools.
    return AiToolRegistry::executeForWorker($job, $tool, $args, 'mcp_prop_' . bin2hex(random_bytes(8)));
};

$result = McpFacade::handle(
    $request,
    $auth,
    null,
    null,
    $auth['ok'] ? $readExecutor : null,
    $auth['ok'] ? $proposalStore : null
);

$status = 200;
if (isset($result['error']['data']['error_code'])) {
    $code = (string)$result['error']['data']['error_code'];
    $status = match ($code) {
        'unauthorized' => 401,
        'forbidden', 'wrong_company', 'company_required' => 403,
        'mcp_disabled' => 503,
        default => 400,
    };
}
mcp_json($result, $status);
