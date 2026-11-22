<?php
/**
 * MCP facade security suite (pure PHP, no DB).
 * Covers: disabled-by-default, bad creds, wrong company, forged tools, approval bypass.
 */
require __DIR__ . '/../app/Core/McpFacade.php';

function mcp_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

$descriptors = [
    ['name' => 'company_snapshot', 'mode' => 'read', 'risk' => 'low', 'description' => 'snap', 'parameters' => ['type' => 'object', 'properties' => []]],
    ['name' => 'create_voucher_draft', 'mode' => 'proposal', 'risk' => 'high', 'description' => 'voucher', 'parameters' => ['type' => 'object', 'properties' => []]],
    ['name' => 'shell', 'mode' => 'read', 'risk' => 'high', 'description' => 'should never appear', 'parameters' => ['type' => 'object', 'properties' => []]],
];

$off = static fn(string $k, $d = '') => '0';
$on = static fn(string $k, $d = '') => $k === 'mcp_enabled' ? '1' : '0';

// 1) Disabled by default
mcp_assert(McpFacade::isEnabled($off) === false, 'mcp disabled when setting=0');
mcp_assert(McpFacade::isEnabled(static fn(string $k, $d = '') => $d) === false, 'mcp disabled when setting missing');
mcp_assert(McpFacade::policySummary($off)['enabled_by_default'] === false, 'enabled_by_default false');
// Adversarial: only exact string "1" enables — truthy lookalikes stay fail-closed
foreach (['true', 'TRUE', 'yes', 'on', '1 ', ' 1', '01', '2'] as $lookalike) {
    $getter = static fn(string $k, $d = '') => $k === 'mcp_enabled' ? $lookalike : '0';
    mcp_assert(McpFacade::isEnabled($getter) === false, "mcp disabled for lookalike enabled flag={$lookalike}");
}
mcp_assert(McpFacade::isEnabled($on) === true, 'mcp enabled only when setting exactly 1');
$disabled = McpFacade::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], [
    'ok' => true, 'workspace_id' => 1, 'allowed_company_ids' => [10], 'permissions' => ['ai.use'],
], $off, $descriptors);
mcp_assert(($disabled['error']['data']['error_code'] ?? '') === 'mcp_disabled', 'handle rejects when disabled');

// 2) Bad credentials
$badAuth = McpFacade::handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], [
    'ok' => false, 'error' => 'unauthorized',
], $on, $descriptors);
mcp_assert(($badAuth['error']['data']['error_code'] ?? '') === 'unauthorized', 'bad creds rejected');

$noPerm = McpFacade::handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], [
    'ok' => true, 'workspace_id' => 1, 'allowed_company_ids' => [10], 'permissions' => ['other'],
], $on, $descriptors);
mcp_assert(($noPerm['error']['data']['error_code'] ?? '') === 'forbidden', 'missing ai.use rejected');

$gate = McpFacade::assertAuth(['ok' => false]);
mcp_assert($gate['ok'] === false && ($gate['code'] ?? '') === 'unauthorized', 'assertAuth bad creds');

// 3) Wrong company
$auth = ['ok' => true, 'workspace_id' => 1, 'allowed_company_ids' => [10], 'permissions' => ['ai.use']];
$wrongCo = McpFacade::handle([
    'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
    'params' => ['name' => 'company_snapshot', 'arguments' => ['company_id' => 99]],
], $auth, $on, $descriptors, static fn() => ['ok' => true], null);
mcp_assert(($wrongCo['error']['data']['error_code'] ?? '') === 'wrong_company', 'wrong company rejected');

$coGate = McpFacade::assertCompanyAllowed(99, [10]);
mcp_assert($coGate['ok'] === false && ($coGate['code'] ?? '') === 'wrong_company', 'assertCompanyAllowed');

// 4) Forged / forbidden tools
$forged = McpFacade::resolveToolCall('drop_database', [], $descriptors);
mcp_assert($forged['ok'] === false && ($forged['code'] ?? '') === 'forged_or_unknown_tool', 'forged tool rejected');

$shell = McpFacade::resolveToolCall('shell', [], $descriptors);
mcp_assert($shell['ok'] === false && ($shell['code'] ?? '') === 'forged_or_forbidden_tool', 'shell tool forbidden');

$sql = McpFacade::resolveToolCall('raw_sql', [], $descriptors);
mcp_assert($sql['ok'] === false, 'sql tool forbidden');

$fs = McpFacade::resolveToolCall('read_file', [], $descriptors);
mcp_assert($fs['ok'] === false, 'filesystem tool forbidden');

$tools = McpFacade::allowlistedTools($descriptors);
$names = array_column($tools, 'name');
mcp_assert(in_array('company_snapshot', $names, true), 'allowlist includes read tool');
mcp_assert(in_array('create_voucher_draft', $names, true), 'allowlist includes proposal tool');
mcp_assert(!in_array('shell', $names, true), 'allowlist excludes shell');

$forgedCall = McpFacade::handle([
    'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
    'params' => ['name' => 'invented_tool', 'arguments' => ['company_id' => 10]],
], $auth, $on, $descriptors);
mcp_assert(($forgedCall['error']['data']['error_code'] ?? '') === 'forged_or_unknown_tool', 'forged tools/call rejected');

// 5) Approval bypass attempts
$bypassArgs = McpFacade::resolveToolCall('create_voucher_draft', ['execute_immediately' => true], $descriptors);
mcp_assert($bypassArgs['ok'] === false && ($bypassArgs['code'] ?? '') === 'approval_bypass', 'execute_immediately blocked');

$bypass2 = McpFacade::resolveToolCall('create_voucher_draft', ['skip_approval' => 1], $descriptors);
mcp_assert($bypass2['ok'] === false, 'skip_approval blocked');

$approveMethod = McpFacade::handle([
    'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/approve',
    'params' => ['proposal_id' => 1],
], $auth, $on, $descriptors);
mcp_assert(($approveMethod['error']['data']['error_code'] ?? '') === 'method_not_allowed', 'tools/approve blocked');

$execMethod = McpFacade::handle([
    'jsonrpc' => '2.0', 'id' => 7, 'method' => 'proposals/execute',
    'params' => [],
], $auth, $on, $descriptors);
mcp_assert(($execMethod['error']['data']['error_code'] ?? '') === 'method_not_allowed', 'proposals/execute blocked');

$proposalStored = null;
$proposalCall = McpFacade::handle([
    'jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call',
    'params' => ['name' => 'create_voucher_draft', 'arguments' => ['company_id' => 10, 'lines' => []]],
], $auth, $on, $descriptors, null, static function ($tool, $args, $cid, $wid) use (&$proposalStored) {
    $proposalStored = ['tool' => $tool, 'company_id' => $cid, 'status' => 'awaiting_human_approval'];
    return $proposalStored;
});
mcp_assert(isset($proposalCall['result']), 'proposal tools/call succeeds as proposal');
mcp_assert(($proposalStored['status'] ?? '') === 'awaiting_human_approval', 'write path is proposal only');
mcp_assert(($proposalCall['result']['structuredContent']['status'] ?? '') === 'awaiting_human_approval', 'structured proposal status');

// Protocol version documented
mcp_assert(McpFacade::PROTOCOL_VERSION === '2024-11-05', 'protocol version pinned');
mcp_assert(str_starts_with(McpFacade::FACADE_VERSION, 'erp-mcp-facade-'), 'facade version present');

// Happy path initialize when enabled
$init = McpFacade::handle(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'initialize'], $auth, $on, $descriptors);
mcp_assert(($init['result']['protocolVersion'] ?? '') === McpFacade::PROTOCOL_VERSION, 'initialize returns protocol');

$readCall = McpFacade::handle([
    'jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call',
    'params' => ['name' => 'company_snapshot', 'arguments' => ['company_id' => 10]],
], $auth, $on, $descriptors, static fn($t, $a, $c, $w) => ['company_id' => $c, 'ok' => true], null);
mcp_assert(($readCall['result']['structuredContent']['ok'] ?? false) === true, 'read tool executes');

// 6) Company membership intersection — client hints never expand scope
$full = McpFacade::resolveAllowedCompanyIds([10, 20, 30], []);
mcp_assert($full === [10, 20, 30], 'empty client hint keeps membership');
$narrow = McpFacade::resolveAllowedCompanyIds([10, 20, 30], [20]);
mcp_assert($narrow === [20], 'client hint may narrow');
$spoof = McpFacade::resolveAllowedCompanyIds([10, 20], [99, 10]);
mcp_assert($spoof === [10], 'spoofed company ids dropped');
$emptyMem = McpFacade::resolveAllowedCompanyIds([], [10, 99]);
mcp_assert($emptyMem === [], 'empty membership fail-closed');
$crossTenant = McpFacade::resolveAllowedCompanyIds([10], [20]);
mcp_assert($crossTenant === [], 'cross-tenant header alone yields empty');

$spoofAuth = [
    'ok' => true,
    'workspace_id' => 1,
    'allowed_company_ids' => McpFacade::resolveAllowedCompanyIds([10], [99]),
    'permissions' => ['ai.use'],
];
$spoofCall = McpFacade::handle([
    'jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call',
    'params' => ['name' => 'company_snapshot', 'arguments' => ['company_id' => 99]],
], $spoofAuth, $on, $descriptors, static fn() => ['ok' => true], null);
mcp_assert(($spoofCall['error']['data']['error_code'] ?? '') === 'wrong_company', 'header-spoof tools/call rejected');

echo "MCP_SECURITY_PASS\n";
