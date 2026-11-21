<?php
/**
 * Pure PHP (no DB): AiToolRegistry contractDescriptors() v0.1 + MF tool presence.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/app/Core/AiToolRegistry.php';

function t3_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

$rows = AiToolRegistry::contractDescriptors();
t3_assert(count($rows) >= 40, 'enough tools in catalog');

$required = ['id', 'version', 'mode', 'risk', 'required_permissions', 'company_scope', 'input_schema', 'output_schema', 'max_rows', 'timeout_ms', 'idempotency', 'evidence_policy', 'audit_policy'];
foreach ($rows as $d) {
    foreach ($required as $k) {
        t3_assert(array_key_exists($k, $d), "missing $k on " . ($d['id'] ?? '?'));
    }
    t3_assert(in_array($d['mode'], ['read', 'proposal'], true), 'mode read|proposal for ' . $d['id']);
    t3_assert(is_array($d['required_permissions']), 'permissions array');
}

$mf = ['manufacturing_bom_read', 'manufacturing_order_read', 'manufacturing_material_readiness'];
foreach ($mf as $id) {
    $c = AiToolRegistry::contractDescriptor($id);
    t3_assert($c !== null, "mf tool $id present");
    t3_assert($c['mode'] === 'read', "$id read");
    t3_assert(in_array('production.view', $c['required_permissions'], true), "$id production.view");
    t3_assert(($c['idempotency'] ?? '') === 'not_applicable', "$id idempotency");
}

$proposal = AiToolRegistry::contractDescriptor('create_voucher_draft');
t3_assert($proposal !== null && $proposal['mode'] === 'proposal', 'voucher remains proposal');
t3_assert(($proposal['idempotency'] ?? '') === 'required', 'proposal idempotency required');

// No autonomous manufacturing mutate tools invented
foreach (AiToolRegistry::descriptors() as $d) {
    $n = (string)$d['name'];
    if (str_starts_with($n, 'manufacturing_')) {
        t3_assert(($d['mode'] ?? '') === 'read', "MF tool $n must be read-only in Wave2");
    }
}

echo "T3_CONTRACT_DESCRIPTORS_PASS\n";
