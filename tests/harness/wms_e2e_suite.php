<?php
/**
 * Single-container RC1 T2 WMS suite — minimizes Docker churn / OOM kills.
 */
declare(strict_types=1);

require_once __DIR__.'/wms_e2e_bootstrap.php';

function wms_try(callable $fn): array
{
    try {
        $r = $fn();
        return is_array($r) ? $r + ['ok' => $r['ok'] ?? true] : ['ok' => true, 'result' => $r];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function wms_release_all_named_locks(): void
{
    try {
        pdo()->query('SELECT RELEASE_ALL_LOCKS()');
    } catch (Throwable $e) {
        // ignore
    }
}

$results = [];

// --- test_01 rejected GR / accepted once ---
wms_e2e_reset_schema();
$poId = wms_seed_po(1, '10.0000', '1000.00');
$line = (int)pdo()->query('SELECT id FROM acc_purchase_lines WHERE purchase_doc_id='.(int)$poId.' LIMIT 1')->fetchColumn();
$before = wms_on_hand(10, 1, 1, 1);
$rej = wms_try(fn() => InventoryDomain::createReceipt(10, 1, 1, [
    'purchase_doc_id' => $poId,
    'warehouse_id' => 1,
    'receipt_date' => '2026-11-20',
    'lines' => [['purchase_line_id' => $line, 'accepted_qty' => 0, 'rejected_qty' => 4]],
]));
$mid = wms_on_hand(10, 1, 1, 1);
$acc = wms_try(fn() => InventoryDomain::createReceipt(10, 1, 1, [
    'purchase_doc_id' => $poId,
    'warehouse_id' => 1,
    'receipt_date' => '2026-11-20',
    'lines' => [['purchase_line_id' => $line, 'accepted_qty' => 6, 'rejected_qty' => 0]],
]));
$after = wms_on_hand(10, 1, 1, 1);
$grMoves = (int)pdo()->query("SELECT COUNT(*) FROM acc_stock_movements WHERE source_type='inventory_receipt' AND status='posted'")->fetchColumn();
$results['test_01_rejected_gr'] = [
    'ok' => $rej['ok'] && $acc['ok'] && $before === '10.0000' && $mid === '10.0000' && $after === '16.0000' && $grMoves === 1,
    'before' => $before, 'mid' => $mid, 'after' => $after, 'gr_moves' => $grMoves,
    'rej' => $rej, 'acc' => $acc,
];

// --- test_02 concurrent fight ---
wms_e2e_reset_schema();
wms_release_all_named_locks();
$args1 = json_encode([
    'production_order_id' => 101, 'warehouse_id' => 1, 'user_id' => 1, 'company_id' => 1,
    'lines' => [['item_id' => 1, 'quantity' => '10.0000']],
], JSON_UNESCAPED_UNICODE);
$args2 = json_encode([
    'production_order_id' => 102, 'warehouse_id' => 1, 'user_id' => 1, 'company_id' => 1,
    'lines' => [['item_id' => 1, 'quantity' => '10.0000']],
], JSON_UNESCAPED_UNICODE);
$script = __DIR__.'/wms_concurrent_reserve.sh';
$cmd = 'sh '.escapeshellarg($script).' '.escapeshellarg($args1).' '.escapeshellarg($args2);
$raw = shell_exec($cmd);
$conc = json_decode(trim((string)$raw), true);
$r1 = is_array($conc['out1'] ?? null) ? $conc['out1'] : [];
$r2 = is_array($conc['out2'] ?? null) ? $conc['out2'] : [];
$oks = 0;
$fails = 0;
foreach ([$r1, $r2] as $r) {
    if (!empty($r['ok'])) {
        $oks++;
    } else {
        $fails++;
    }
}
$resSum = (float)pdo()->query("SELECT COALESCE(SUM(quantity),0) FROM acc_inventory_reservations WHERE status='active' AND item_id=1 AND source_type='production_order'")->fetchColumn();
$results['test_02_concurrent'] = [
    'ok' => $oks === 1 && $fails === 1 && abs($resSum - 10.0) < 0.0001,
    'oks' => $oks, 'fails' => $fails, 'reserved_sum' => $resSum, 'raw' => $conc, 'shell' => $raw,
];
wms_release_all_named_locks();

// --- test_03 idempotency ---
wms_e2e_reset_schema();
wms_release_all_named_locks();
$r03 = wms_try(fn() => InventoryDomain::reserveForProduction(10, 1, 1, [
    'production_order_id' => 201, 'warehouse_id' => 1,
    'lines' => [['item_id' => 1, 'quantity' => '5.0000']],
]));
$key = str_repeat('a', 32);
$payload = [
    'production_order_id' => 201, 'warehouse_id' => 1, 'movement_date' => '2026-11-20',
    'idempotency_key' => $key,
    'lines' => [['item_id' => 1, 'quantity' => '3.0000', 'unit_cost' => 100]],
];
$first = $r03['ok'] ? wms_try(fn() => InventoryDomain::issueMaterial(10, 1, 1, $payload)) : ['ok' => false, 'error' => 'skip'];
$second = $first['ok'] ? wms_try(fn() => InventoryDomain::issueMaterial(10, 1, 1, $payload)) : ['ok' => false, 'error' => 'skip'];
$bad = $first['ok'] ? wms_try(fn() => InventoryDomain::issueMaterial(10, 1, 1, array_merge($payload, [
    'lines' => [['item_id' => 1, 'quantity' => '1.0000', 'unit_cost' => 100]],
]))) : ['ok' => true, 'error' => 'skip'];
$m = ['on_hand' => wms_on_hand(10, 1, 1, 1), 'reserved' => wms_reserved(10, 1, 1, 1)];
$results['test_03_idempotency'] = [
    'ok' => $r03['ok'] && $first['ok'] && $second['ok'] && !empty($second['existing'])
        && (int)($first['id'] ?? 0) === (int)($second['id'] ?? 0)
        && $m['on_hand'] === '7.0000' && $m['reserved'] === '2.0000' && !$bad['ok'],
    'reserve' => $r03, 'first' => $first, 'second' => $second, 'bad' => $bad, 'metrics' => $m,
];
wms_release_all_named_locks();

// --- test_04 partial issue + FG ---
wms_e2e_reset_schema();
wms_release_all_named_locks();
$r04 = wms_try(fn() => InventoryDomain::reserveForProduction(10, 1, 1, [
    'production_order_id' => 301, 'warehouse_id' => 1,
    'lines' => [['item_id' => 1, 'quantity' => '8.0000']],
]));
$i1 = $r04['ok'] ? wms_try(fn() => InventoryDomain::issueMaterial(10, 1, 1, [
    'production_order_id' => 301, 'warehouse_id' => 1, 'idempotency_key' => str_repeat('b', 32),
    'movement_date' => '2026-11-20',
    'lines' => [['item_id' => 1, 'quantity' => '3.0000', 'unit_cost' => 100]],
])) : ['ok' => false];
$i2 = $i1['ok'] ? wms_try(fn() => InventoryDomain::issueMaterial(10, 1, 1, [
    'production_order_id' => 301, 'warehouse_id' => 1, 'idempotency_key' => str_repeat('c', 32),
    'movement_date' => '2026-11-20',
    'lines' => [['item_id' => 1, 'quantity' => '2.0000', 'unit_cost' => 100]],
])) : ['ok' => false];
$fg1 = wms_try(fn() => InventoryDomain::receiveFinishedGoods(10, 1, 1, [
    'production_order_id' => 301, 'warehouse_id' => 1, 'idempotency_key' => str_repeat('d', 32),
    'movement_date' => '2026-11-20',
    'lines' => [['item_id' => 2, 'quantity' => '1.0000', 'unit_cost' => 500]],
]));
$fg2 = wms_try(fn() => InventoryDomain::receiveFinishedGoods(10, 1, 1, [
    'production_order_id' => 301, 'warehouse_id' => 1, 'idempotency_key' => str_repeat('e', 32),
    'movement_date' => '2026-11-20',
    'lines' => [['item_id' => 2, 'quantity' => '2.0000', 'unit_cost' => 500]],
]));
$over = wms_try(fn() => InventoryDomain::issueMaterial(10, 1, 1, [
    'production_order_id' => 301, 'warehouse_id' => 1, 'idempotency_key' => str_repeat('f', 32),
    'lines' => [['item_id' => 1, 'quantity' => '9.0000']],
]));
$results['test_04_partial'] = [
    'ok' => $r04['ok'] && $i1['ok'] && $i2['ok'] && $fg1['ok'] && $fg2['ok'] && !$over['ok']
        && wms_on_hand(10, 1, 1, 1) === '5.0000' && wms_reserved(10, 1, 1, 1) === '3.0000'
        && wms_on_hand(10, 1, 1, 2) === '3.0000',
    'rm_on_hand' => wms_on_hand(10, 1, 1, 1), 'rm_reserved' => wms_reserved(10, 1, 1, 1),
    'fg_on_hand' => wms_on_hand(10, 1, 1, 2), 'over' => $over, 'reserve' => $r04,
];
wms_release_all_named_locks();

// --- test_05 cross-company ---
wms_e2e_reset_schema();
$x = wms_try(fn() => InventoryDomain::reserveForProduction(10, 2, 1, [
    'production_order_id' => 55, 'warehouse_id' => 1,
    'lines' => [['item_id' => 1, 'quantity' => '1.0000']],
]));
$results['test_05_cross_company'] = [
    'ok' => !$x['ok'],
    'error' => $x['error'] ?? null,
];
wms_release_all_named_locks();

// --- test_06 rollback ---
wms_e2e_reset_schema();
$db = pdo();
$db->beginTransaction();
try {
    InventoryDomain::reserveForProduction(10, 1, 1, [
        'production_order_id' => 9001, 'warehouse_id' => 1,
        'lines' => [['item_id' => 1, 'quantity' => '5.0000']],
    ]);
    throw new RuntimeException('injected_fault');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($e->getMessage() !== 'injected_fault') {
        $results['test_06_rollback'] = ['ok' => false, 'error' => $e->getMessage()];
    }
}
if (!isset($results['test_06_rollback'])) {
    $cnt = (int)pdo()->query("SELECT COUNT(*) FROM acc_inventory_reservations WHERE source_type='production_order'")->fetchColumn();
    $results['test_06_rollback'] = [
        'ok' => $cnt === 0 && wms_on_hand(10, 1, 1, 1) === '10.0000',
        'reservations_after_rollback' => $cnt,
        'on_hand' => wms_on_hand(10, 1, 1, 1),
    ];
}
wms_release_all_named_locks();

// --- test_07 reverse ---
wms_e2e_reset_schema();
wms_release_all_named_locks();
$r07 = wms_try(fn() => InventoryDomain::reserveForProduction(10, 1, 1, [
    'production_order_id' => 401, 'warehouse_id' => 1,
    'lines' => [['item_id' => 1, 'quantity' => '4.0000']],
]));
$issued = $r07['ok'] ? wms_try(fn() => InventoryDomain::issueMaterial(10, 1, 1, [
    'production_order_id' => 401, 'warehouse_id' => 1, 'idempotency_key' => str_repeat('1', 32),
    'movement_date' => '2026-11-20',
    'lines' => [['item_id' => 1, 'quantity' => '4.0000', 'unit_cost' => 100]],
])) : ['ok' => false];
$eventId = (int)($issued['id'] ?? 0);
$afterIssue = wms_on_hand(10, 1, 1, 1);
$rev = $eventId > 0 ? wms_try(fn() => InventoryDomain::reverseMovement(10, 1, 1, [
    'event_id' => $eventId, 'idempotency_key' => str_repeat('2', 32),
])) : ['ok' => false];
$rev2 = $eventId > 0 ? wms_try(fn() => InventoryDomain::reverseMovement(10, 1, 1, [
    'event_id' => $eventId, 'idempotency_key' => str_repeat('2', 32),
])) : ['ok' => false];
$results['test_07_reverse'] = [
    'ok' => $r07['ok'] && $issued['ok'] && $afterIssue === '6.0000' && $rev['ok'] && !empty($rev2['existing'])
        && wms_on_hand(10, 1, 1, 1) === '10.0000' && wms_reserved(10, 1, 1, 1) === '4.0000',
    'after_issue' => $afterIssue,
    'on_hand' => wms_on_hand(10, 1, 1, 1),
    'reserved' => wms_reserved(10, 1, 1, 1),
    'rev' => $rev, 'rev2' => $rev2, 'issued' => $issued,
];

$failed = [];
foreach ($results as $name => $r) {
    if (empty($r['ok'])) {
        $failed[] = $name;
    }
}
wms_json_out([
    'ok' => $failed === [],
    'failed' => $failed,
    'results' => $results,
    'container_hint' => 'erpsmart-p2p-mysql-test',
]);
exit($failed === [] ? 0 : 1);
