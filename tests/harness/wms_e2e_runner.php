<?php
/**
 * CLI runner for RC1 T2 warehouse stock E2E scenarios.
 * Usage: php wms_e2e_runner.php <scenario> [json_args]
 */
declare(strict_types=1);

require_once __DIR__.'/wms_e2e_bootstrap.php';

$scenario = $argv[1] ?? '';
$args = [];
if (isset($argv[2]) && $argv[2] !== '') {
    $decoded = json_decode($argv[2], true);
    if (!is_array($decoded)) {
        wms_json_out(['ok' => false, 'error' => 'invalid_json_args']);
        exit(1);
    }
    $args = $decoded;
}

function wms_e2e_connect_reuse(): void
{
    global $host, $port, $user, $pass, $dbName;
    DB::connect(['host' => $host, 'port' => $port, 'name' => $dbName, 'user' => $user, 'pass' => $pass]);
}

try {
    if (!in_array($scenario, ['reset', 'seed_stock', 'rollback_reserve', 'cross_company_reserve_fail', 'rejected_gr_smoke'], true)) {
        wms_e2e_connect_reuse();
    }
    switch ($scenario) {
        case 'reset':
            wms_e2e_reset_schema();
            wms_json_out(['ok' => true, 'reset' => true]);
            break;

        case 'seed_stock':
            wms_e2e_reset_schema();
            $qty = (string)($args['qty'] ?? '10.0000');
            $item = (int)($args['item_id'] ?? 1);
            $wh = (int)($args['warehouse_id'] ?? 1);
            $cid = (int)($args['company_id'] ?? 1);
            // Replace seed movement quantity if custom.
            pdo()->prepare("DELETE FROM acc_stock_movements WHERE workspace_id=10 AND company_id=? AND source_type='seed'")->execute([$cid]);
            pdo()->prepare("INSERT INTO acc_stock_movements (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,source_line_id,reference_no,status,created_by,created_at)
                VALUES (10,?,CURDATE(),'adjustment','in',?,?,?,100.00,'seed',1,1,'SEED','posted',1,NOW())")
                ->execute([$cid, $wh, $item, $qty]);
            wms_json_out(['ok' => true, 'on_hand' => wms_on_hand(10, $cid, $wh, $item)]);
            break;

        case 'position':
            $cid = (int)($args['company_id'] ?? 1);
            $r = InventoryDomain::inventoryPosition(10, $cid, [
                'warehouse_id' => (int)($args['warehouse_id'] ?? 1),
                'item_id' => (int)($args['item_id'] ?? 0),
                'limit' => 50,
            ]);
            wms_json_out(['ok' => true, 'result' => $r]);
            break;

        case 'reserve':
            $cid = (int)($args['company_id'] ?? 1);
            $uid = (int)($args['user_id'] ?? 1);
            $r = InventoryDomain::reserveForProduction(10, $cid, $uid, $args);
            wms_json_out(['ok' => true, 'result' => $r]);
            break;

        case 'issue':
            $cid = (int)($args['company_id'] ?? 1);
            $uid = (int)($args['user_id'] ?? 1);
            $r = InventoryDomain::issueMaterial(10, $cid, $uid, $args);
            wms_json_out(['ok' => true, 'result' => $r]);
            break;

        case 'fg':
            $cid = (int)($args['company_id'] ?? 1);
            $uid = (int)($args['user_id'] ?? 1);
            $r = InventoryDomain::receiveFinishedGoods(10, $cid, $uid, $args);
            wms_json_out(['ok' => true, 'result' => $r]);
            break;

        case 'reverse':
            $cid = (int)($args['company_id'] ?? 1);
            $uid = (int)($args['user_id'] ?? 1);
            $r = InventoryDomain::reverseMovement(10, $cid, $uid, $args);
            wms_json_out(['ok' => true, 'result' => $r]);
            break;

        case 'create_receipt':
            $cid = (int)($args['company_id'] ?? 1);
            $uid = (int)($args['user_id'] ?? 1);
            $r = InventoryDomain::createReceipt(10, $cid, $uid, $args);
            wms_json_out(['ok' => true, 'result' => $r]);
            break;

        case 'seed_po':
            $id = wms_seed_po((int)($args['created_by'] ?? 1), (string)($args['qty'] ?? '10.0000'), (string)($args['price'] ?? '1000.00'));
            $line = (int)pdo()->query('SELECT id FROM acc_purchase_lines WHERE purchase_doc_id='.(int)$id.' LIMIT 1')->fetchColumn();
            wms_json_out(['ok' => true, 'po_id' => $id, 'purchase_line_id' => $line]);
            break;

        case 'metrics':
            $cid = (int)($args['company_id'] ?? 1);
            $wh = (int)($args['warehouse_id'] ?? 1);
            $item = (int)($args['item_id'] ?? 1);
            wms_json_out([
                'ok' => true,
                'on_hand' => wms_on_hand(10, $cid, $wh, $item),
                'reserved' => wms_reserved(10, $cid, $wh, $item),
                'movements' => (int)pdo()->query("SELECT COUNT(*) FROM acc_stock_movements WHERE workspace_id=10 AND company_id={$cid} AND status='posted' AND source_type<>'seed'")->fetchColumn(),
                'events' => (int)pdo()->query("SELECT COUNT(*) FROM erp_wms_stock_events WHERE workspace_id=10 AND company_id={$cid}")->fetchColumn(),
                'audit_count' => count(Audit::$logs),
            ]);
            break;

        case 'query':
            $sql = (string)($args['sql'] ?? '');
            if ($sql === '' || !preg_match('/^\s*SELECT\b/i', $sql)) {
                throw new RuntimeException('only_select_allowed');
            }
            $rows = pdo()->query($sql)->fetchAll();
            wms_json_out(['ok' => true, 'rows' => $rows]);
            break;

        case 'rollback_reserve':
            // Fault injection: reserve inside txn then rollback — must leave zero production reservations.
            wms_e2e_reset_schema();
            $db = pdo();
            $db->beginTransaction();
            try {
                InventoryDomain::reserveForProduction(10, 1, 1, [
                    'production_order_id' => 9001,
                    'warehouse_id' => 1,
                    'lines' => [['item_id' => 1, 'quantity' => '5.0000']],
                ]);
                throw new RuntimeException('injected_fault');
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($e->getMessage() !== 'injected_fault') {
                    throw $e;
                }
            }
            $cnt = (int)pdo()->query("SELECT COUNT(*) FROM acc_inventory_reservations WHERE source_type='production_order'")->fetchColumn();
            wms_json_out(['ok' => true, 'reservations_after_rollback' => $cnt, 'on_hand' => wms_on_hand(10, 1, 1, 1)]);
            break;

        case 'cross_company_reserve_fail':
            wms_e2e_reset_schema();
            // Company 2 trying to reserve company 1 warehouse/item should fail ownership.
            try {
                InventoryDomain::reserveForProduction(10, 2, 1, [
                    'production_order_id' => 55,
                    'warehouse_id' => 1,
                    'lines' => [['item_id' => 1, 'quantity' => '1.0000']],
                ]);
                wms_json_out(['ok' => false, 'error' => 'expected_fail']);
            } catch (Throwable $e) {
                wms_json_out(['ok' => true, 'blocked' => true, 'error' => $e->getMessage()]);
            }
            break;

        case 'rejected_gr_smoke':
            wms_e2e_reset_schema();
            $before = wms_on_hand(10, 1, 1, 1);
            $po = wms_seed_po(1, '5.0000', '1000.00');
            $line = (int)pdo()->query('SELECT id FROM acc_purchase_lines WHERE purchase_doc_id='.(int)$po.' LIMIT 1')->fetchColumn();
            InventoryDomain::createReceipt(10, 1, 1, [
                'purchase_doc_id' => $po,
                'warehouse_id' => 1,
                'receipt_date' => '2026-11-20',
                'lines' => [['purchase_line_id' => $line, 'accepted_qty' => 0, 'rejected_qty' => 5]],
            ]);
            $after = wms_on_hand(10, 1, 1, 1);
            $deltaUnits = OrderToCashDomain::scaledQty($after) - OrderToCashDomain::scaledQty($before);
            wms_json_out([
                'ok' => true,
                'result' => [
                    'before' => $before,
                    'after' => $after,
                    'on_hand_delta' => OrderToCashDomain::fixedQty($deltaUnits),
                ],
            ]);
            break;

        default:
            wms_json_out(['ok' => false, 'error' => 'unknown_scenario', 'scenario' => $scenario]);
            exit(1);
    }
} catch (Throwable $e) {
    wms_json_out(['ok' => false, 'error' => $e->getMessage()]);
    exit(0);
}
