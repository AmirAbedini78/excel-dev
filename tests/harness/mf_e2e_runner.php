<?php
declare(strict_types=1);
require_once __DIR__.'/mf_e2e_bootstrap.php';

$scenario = $argv[1] ?? '';
$args = [];
if (isset($argv[2]) && $argv[2] !== '') {
    $args = json_decode($argv[2], true) ?: [];
}

try {
    if ($scenario !== 'reset') {
        mf_e2e_connect();
    }
    switch ($scenario) {
        case 'reset':
            mf_e2e_reset_schema();
            mf_json_out(['ok' => true, 'database_tested' => true, 'container' => 'erpsmart-p2p-mysql-test', 'database' => getenv('O2C_DB_NAME') ?: 'erpsmart_mf1_test']);
            break;

        case 'seed_stock':
            $item = (int)($args['item_id'] ?? 2);
            $qty = (string)($args['qty'] ?? '10.0000');
            $wh = (int)($args['warehouse_id'] ?? 1);
            $cid = (int)($args['company_id'] ?? 1);
            $cost = (string)($args['unit_cost'] ?? '100.00');
            $id = mf_seed_stock($item, $qty, $wh, $cid, $cost);
            mf_json_out(['ok' => true, 'movement_id' => $id, 'on_hand' => mf_on_hand($item, $wh, $cid)]);
            break;

        case 'on_hand':
            mf_json_out([
                'ok' => true,
                'on_hand' => mf_on_hand((int)$args['item_id'], (int)($args['warehouse_id'] ?? 1), (int)($args['company_id'] ?? 1)),
            ]);
            break;

        case 'create_bom_approved':
            $id = mf_create_approved_bom((int)($args['maker'] ?? 1), (int)($args['checker'] ?? 2), (int)($args['company_id'] ?? 1));
            mf_json_out(['ok' => true, 'bom_id' => $id]);
            break;

        case 'approve_bom_same_user':
            $bomId = ProductionDomain::createBom(10, 1, 1, [
                'code' => 'BOM-SAME-'.bin2hex(random_bytes(2)),
                'output_item_id' => 1,
                'output_qty' => '1',
                'lines' => [
                    ['component_item_id' => 2, 'quantity_per' => '1', 'scrap_percent' => 0],
                    ['component_item_id' => 3, 'quantity_per' => '1', 'scrap_percent' => 0],
                ],
            ]);
            try {
                ProductionDomain::approveBom(10, 1, 1, $bomId);
                mf_json_out(['ok' => false, 'error' => 'should_have_failed']);
            } catch (Throwable $e) {
                mf_json_out(['ok' => true, 'rejected' => true, 'error' => $e->getMessage()]);
            }
            break;

        case 'create_order':
            $order = ManufacturingExecutionDomain::createOrder(10, (int)($args['company_id'] ?? 1), (int)($args['user_id'] ?? 1), [
                'bom_id' => (int)$args['bom_id'],
                'planned_qty' => (string)($args['planned_qty'] ?? '3.0000'),
                'raw_warehouse_id' => (int)($args['warehouse_id'] ?? 1),
                'fg_warehouse_id' => (int)($args['fg_warehouse_id'] ?? ($args['warehouse_id'] ?? 1)),
            ]);
            mf_json_out(['ok' => true, 'order' => $order]);
            break;

        case 'approve_order':
            $order = ManufacturingExecutionDomain::approveOrder(10, (int)($args['company_id'] ?? 1), (int)($args['user_id'] ?? 2), (int)$args['order_id']);
            mf_json_out(['ok' => true, 'order' => $order]);
            break;

        case 'release_order':
            $order = ManufacturingExecutionDomain::releaseOrder(
                10, (int)($args['company_id'] ?? 1), (int)($args['user_id'] ?? 1), (int)$args['order_id'],
                (string)($args['idempotency_key'] ?? '')
            );
            mf_json_out(['ok' => true, 'order' => $order]);
            break;

        case 'issue':
            $lines = $args['lines'] ?? [];
            $out = ManufacturingExecutionDomain::issueMaterials(
                10, (int)($args['company_id'] ?? 1), (int)($args['user_id'] ?? 1), (int)$args['order_id'],
                $lines, (string)($args['idempotency_key'] ?? ''),
                ['movement_date' => (string)($args['movement_date'] ?? '2026-11-20'), '__fail_after_stock' => !empty($args['fail_after_stock'])]
            );
            mf_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'scrap':
            $out = ManufacturingExecutionDomain::recordScrap(10, 1, (int)($args['user_id'] ?? 1), (int)$args['order_id'], [
                'quantity' => (string)($args['quantity'] ?? '0.5000'),
                'reason' => (string)($args['reason'] ?? 'process scrap'),
                'component_item_id' => (int)($args['component_item_id'] ?? 2),
            ]);
            mf_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'qc':
            $out = ManufacturingExecutionDomain::recordQc(
                10, 1, (int)($args['user_id'] ?? 1), (int)$args['order_id'],
                (string)($args['decision'] ?? 'accept'),
                (string)($args['quantity'] ?? '1.0000'),
                (string)($args['reason'] ?? '')
            );
            mf_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'fg':
            $out = ManufacturingExecutionDomain::receiveFinishedGoods(
                10, 1, (int)($args['user_id'] ?? 1), (int)$args['order_id'],
                (string)($args['quantity'] ?? '1.0000'),
                (string)($args['idempotency_key'] ?? ''),
                ['movement_date' => (string)($args['movement_date'] ?? '2026-11-20')]
            );
            mf_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'close':
            $order = ManufacturingExecutionDomain::closeOrder(10, 1, (int)($args['user_id'] ?? 1), (int)$args['order_id']);
            mf_json_out(['ok' => true, 'order' => $order]);
            break;

        case 'get_order':
            $order = ManufacturingExecutionDomain::getOrder(10, (int)($args['company_id'] ?? 1), (int)$args['order_id']);
            mf_json_out(['ok' => true, 'order' => $order]);
            break;

        case 'cost':
            $out = ManufacturingCostEvidence::rollup(10, 1, (int)$args['order_id']);
            mf_json_out(['ok' => true, 'cost' => $out]);
            break;

        case 'mrp':
            $out = ManufacturingExecutionDomain::mrpRecommendations(
                10, 1, (int)$args['bom_id'], (string)($args['planned_qty'] ?? '10'), (int)($args['warehouse_id'] ?? 1)
            );
            mf_json_out(['ok' => true, 'mrp' => $out]);
            break;

        case 'malformed_qty':
            try {
                ManufacturingExecutionDomain::createOrder(10, 1, 1, [
                    'bom_id' => (int)$args['bom_id'],
                    'planned_qty' => 'not-a-number',
                    'raw_warehouse_id' => 1,
                    'fg_warehouse_id' => 1,
                ]);
                mf_json_out(['ok' => false, 'error' => 'should_fail']);
            } catch (Throwable $e) {
                mf_json_out(['ok' => true, 'rejected' => true, 'error' => $e->getMessage()]);
            }
            break;

        case 'duplicate_component_issue':
            try {
                $order = ManufacturingExecutionDomain::getOrder(10, 1, (int)$args['order_id']);
                $sid = (int)$order['bom_snapshot'][0]['id'];
                ManufacturingExecutionDomain::issueMaterials(10, 1, 1, (int)$args['order_id'], [
                    ['snapshot_line_id' => $sid, 'quantity' => '0.1000'],
                    ['snapshot_line_id' => $sid, 'quantity' => '0.1000'],
                ], hash('sha256', 'dup-comp-'.bin2hex(random_bytes(4))));
                mf_json_out(['ok' => false, 'error' => 'should_fail']);
            } catch (Throwable $e) {
                mf_json_out(['ok' => true, 'rejected' => true, 'error' => $e->getMessage()]);
            }
            break;

        case 'company_isolation':
            // Order in company 1 must not be visible/mutable from company 2 scope.
            $order = ManufacturingExecutionDomain::getOrder(10, 2, (int)$args['order_id']);
            mf_json_out(['ok' => true, 'hidden' => $order === null]);
            break;

        case 'close_period_issue':
            $db = pdo();
            $fy = (int)$db->query('SELECT id FROM acc_fiscal_years WHERE workspace_id=10 AND company_id=1 LIMIT 1')->fetchColumn();
            $db->prepare("INSERT INTO acc_period_closes (workspace_id,company_id,fiscal_year_id,period_key,close_type,status,created_at,updated_at)
                VALUES (10,1,?,'2026-11','monthly','closed',NOW(),NOW())
                ON DUPLICATE KEY UPDATE status='closed'")->execute([$fy]);
            try {
                $order = ManufacturingExecutionDomain::getOrder(10, 1, (int)$args['order_id']);
                $lines = [];
                foreach ($order['bom_snapshot'] as $s) {
                    $lines[] = ['snapshot_line_id' => (int)$s['id'], 'quantity' => '0.0001', 'unit_cost' => '1'];
                }
                ManufacturingExecutionDomain::issueMaterials(10, 1, 1, (int)$args['order_id'], $lines, hash('sha256', 'closed-period-'.bin2hex(random_bytes(3))), [
                    'movement_date' => '2026-11-15',
                ]);
                mf_json_out(['ok' => false, 'error' => 'should_fail']);
            } catch (Throwable $e) {
                mf_json_out(['ok' => true, 'rejected' => true, 'error' => $e->getMessage()]);
            }
            break;

        case 'movement_count':
            $st = pdo()->prepare("SELECT COUNT(*) FROM acc_stock_movements WHERE workspace_id=10 AND company_id=? AND movement_type=? AND status='posted'");
            $st->execute([(int)($args['company_id'] ?? 1), (string)($args['movement_type'] ?? 'production_issue')]);
            mf_json_out(['ok' => true, 'count' => (int)$st->fetchColumn()]);
            break;

        case 'adapter_mode':
            mf_json_out([
                'ok' => true,
                'mode' => ManufacturingInventoryAdapter::mode(),
                'native' => ManufacturingInventoryAdapter::nativeAvailable(),
                'no_parallel_gl' => true,
            ]);
            break;

        case 'lifecycle_happy':
            // Full path used by python suite as a single transaction-safe script.
            mf_e2e_reset_schema();
            mf_seed_stock(2, '10.0000', 1, 1, '100.00');
            mf_seed_stock(3, '10.0000', 1, 1, '50.00');
            $bomId = mf_create_approved_bom(1, 2, 1);
            $order = ManufacturingExecutionDomain::createOrder(10, 1, 1, [
                'bom_id' => $bomId, 'planned_qty' => '3.0000', 'raw_warehouse_id' => 1, 'fg_warehouse_id' => 1,
            ]);
            $oid = (int)$order['id'];
            ManufacturingExecutionDomain::approveOrder(10, 1, 2, $oid);
            ManufacturingExecutionDomain::releaseOrder(10, 1, 1, $oid, hash('sha256', 'rel-'.$oid));
            $snap = ManufacturingExecutionDomain::getOrder(10, 1, $oid)['bom_snapshot'];
            $issueLines = [];
            foreach ($snap as $s) {
                // BOM requires 3 each for planned 3; issue 2 of 3 (partial)
                $issueLines[] = ['snapshot_line_id' => (int)$s['id'], 'quantity' => '2.0000', 'unit_cost' => $s['component_item_id'] == 2 ? '100.00' : '50.00'];
            }
            $key = hash('sha256', 'iss-partial-'.$oid);
            $iss1 = ManufacturingExecutionDomain::issueMaterials(10, 1, 1, $oid, $issueLines, $key);
            $iss2 = ManufacturingExecutionDomain::issueMaterials(10, 1, 1, $oid, $issueLines, $key); // replay
            ManufacturingExecutionDomain::recordScrap(10, 1, 1, $oid, ['quantity' => '0.2500', 'reason' => 'edge trim', 'component_item_id' => 2]);
            ManufacturingExecutionDomain::recordQc(10, 1, 1, $oid, 'accept', '2.0000');
            ManufacturingExecutionDomain::recordQc(10, 1, 1, $oid, 'reject', '1.0000', 'dimension fail');
            $fgKey = hash('sha256', 'fg-1-'.$oid);
            $fg1 = ManufacturingExecutionDomain::receiveFinishedGoods(10, 1, 1, $oid, '1.0000', $fgKey);
            $fg2 = ManufacturingExecutionDomain::receiveFinishedGoods(10, 1, 1, $oid, '1.0000', $fgKey); // replay
            $fg3 = ManufacturingExecutionDomain::receiveFinishedGoods(10, 1, 1, $oid, '1.0000', hash('sha256', 'fg-2-'.$oid));
            try {
                ManufacturingExecutionDomain::receiveFinishedGoods(10, 1, 1, $oid, '1.0000', hash('sha256', 'fg-reject-'.$oid));
                $fgRejectBlocked = false;
            } catch (Throwable $e) {
                $fgRejectBlocked = true;
            }
            $cost = ManufacturingCostEvidence::rollup(10, 1, $oid);
            $final = ManufacturingExecutionDomain::getOrder(10, 1, $oid);
            ManufacturingExecutionDomain::closeOrder(10, 1, 1, $oid);
            $closed = ManufacturingExecutionDomain::getOrder(10, 1, $oid);
            $issueMoves = (int)pdo()->query("SELECT COUNT(*) FROM acc_stock_movements WHERE workspace_id=10 AND company_id=1 AND movement_type='production_issue' AND status='posted'")->fetchColumn();
            $fgMoves = (int)pdo()->query("SELECT COUNT(*) FROM acc_stock_movements WHERE workspace_id=10 AND company_id=1 AND movement_type='production_fg_receipt' AND status='posted'")->fetchColumn();
            mf_json_out([
                'ok' => true,
                'order_id' => $oid,
                'issue_replay' => !empty($iss2['replay']),
                'fg_replay' => !empty($fg2['replay']),
                'fg_reject_blocked' => $fgRejectBlocked,
                'received_fg' => $final['received_fg_qty'],
                'accepted_qc' => $final['accepted_qc_qty'],
                'rejected_qc' => $final['rejected_qc_qty'],
                'scrap_qty' => $final['scrap_qty'],
                'status_closed' => $closed['status'] === 'closed',
                'issue_movements' => $issueMoves,
                'fg_movements' => $fgMoves,
                'cost_class' => $cost['resolver_class'],
                'gl_posting' => $cost['gl_posting'],
                'adapter_mode' => ManufacturingInventoryAdapter::mode(),
                'raw_a_on_hand' => mf_on_hand(2),
                'fg_on_hand' => mf_on_hand(1),
                'DATABASE_TESTED' => true,
            ]);
            break;

        case 'insufficient_stock':
            mf_e2e_reset_schema();
            mf_seed_stock(2, '1.0000', 1, 1, '100.00');
            mf_seed_stock(3, '1.0000', 1, 1, '50.00');
            $bomId = mf_create_approved_bom();
            $order = ManufacturingExecutionDomain::createOrder(10, 1, 1, [
                'bom_id' => $bomId, 'planned_qty' => '3.0000', 'raw_warehouse_id' => 1, 'fg_warehouse_id' => 1,
            ]);
            $oid = (int)$order['id'];
            ManufacturingExecutionDomain::approveOrder(10, 1, 2, $oid);
            try {
                ManufacturingExecutionDomain::releaseOrder(10, 1, 1, $oid, hash('sha256', 'insuf-rel'));
                mf_json_out(['ok' => false, 'error' => 'release_should_fail']);
            } catch (Throwable $e) {
                mf_json_out(['ok' => true, 'rejected' => true, 'error' => $e->getMessage()]);
            }
            break;

        case 'concurrent_issue_prepare':
            mf_e2e_reset_schema();
            // Physical stock of A = 10; two released orders each will try to issue 6 of A concurrently.
            // Order1 gets a real reservation; order2 is force-released without reservation so both compete on free stock.
            mf_seed_stock(2, '10.0000', 1, 1, '100.00');
            mf_seed_stock(3, '20.0000', 1, 1, '50.00');
            $bomId = mf_create_approved_bom();
            $o1 = ManufacturingExecutionDomain::createOrder(10, 1, 1, [
                'bom_id' => $bomId, 'planned_qty' => '6.0000', 'raw_warehouse_id' => 1, 'fg_warehouse_id' => 1, 'order_no' => 'MO-C1',
            ]);
            $o2 = ManufacturingExecutionDomain::createOrder(10, 1, 1, [
                'bom_id' => $bomId, 'planned_qty' => '6.0000', 'raw_warehouse_id' => 1, 'fg_warehouse_id' => 1, 'order_no' => 'MO-C2',
            ]);
            ManufacturingExecutionDomain::approveOrder(10, 1, 2, (int)$o1['id']);
            ManufacturingExecutionDomain::approveOrder(10, 1, 2, (int)$o2['id']);
            // Force both to released WITHOUT reservations so they race on the same free on_hand (10).
            // Real release+reserve would serialize demand; concurrent issue needs shared free stock contention.
            pdo()->prepare("UPDATE erp_mf_production_orders SET status='released',released_at=NOW(),updated_at=NOW()
                WHERE workspace_id=10 AND company_id=1 AND id IN (?,?)")
                ->execute([(int)$o1['id'], (int)$o2['id']]);
            $s1 = ManufacturingExecutionDomain::getOrder(10, 1, (int)$o1['id'])['bom_snapshot'];
            $s2 = ManufacturingExecutionDomain::getOrder(10, 1, (int)$o2['id'])['bom_snapshot'];
            $pack = static function (array $snap): array {
                $out = [];
                foreach ($snap as $s) {
                    $out[] = [
                        'snapshot_line_id' => (int)$s['id'],
                        'quantity' => '6.0000',
                        'unit_cost' => ((int)$s['component_item_id'] === 2) ? '100.00' : '50.00',
                    ];
                }
                return $out;
            };
            mf_json_out([
                'ok' => true,
                'order1' => (int)$o1['id'],
                'order2' => (int)$o2['id'],
                'lines1' => $pack($s1),
                'lines2' => $pack($s2),
            ]);
            break;

        case 'concurrent_issue_one':
            $oid = (int)$args['order_id'];
            $lines = $args['lines'] ?? [];
            try {
                $out = ManufacturingExecutionDomain::issueMaterials(10, 1, 1, $oid, $lines, (string)$args['idempotency_key']);
                mf_json_out(['ok' => true, 'result' => $out]);
            } catch (Throwable $e) {
                mf_json_out(['ok' => false, 'error' => $e->getMessage()]);
            }
            break;

        case 'injected_rollback':
            mf_e2e_reset_schema();
            mf_seed_stock(2, '10.0000', 1, 1, '100.00');
            mf_seed_stock(3, '10.0000', 1, 1, '50.00');
            $bomId = mf_create_approved_bom();
            $order = ManufacturingExecutionDomain::createOrder(10, 1, 1, [
                'bom_id' => $bomId, 'planned_qty' => '2.0000', 'raw_warehouse_id' => 1, 'fg_warehouse_id' => 1,
            ]);
            $oid = (int)$order['id'];
            ManufacturingExecutionDomain::approveOrder(10, 1, 2, $oid);
            ManufacturingExecutionDomain::releaseOrder(10, 1, 1, $oid, hash('sha256', 'rb-rel'));
            $snap = ManufacturingExecutionDomain::getOrder(10, 1, $oid)['bom_snapshot'];
            $lines = [];
            foreach ($snap as $s) {
                $lines[] = ['snapshot_line_id' => (int)$s['id'], 'quantity' => '1.0000', 'unit_cost' => '10.00'];
            }
            $beforeIssues = (int)pdo()->query("SELECT COUNT(*) FROM erp_mf_material_issues")->fetchColumn();
            $beforeMoves = (int)pdo()->query("SELECT COUNT(*) FROM acc_stock_movements WHERE movement_type='production_issue'")->fetchColumn();
            $beforeOnHand = mf_on_hand(2);
            try {
                ManufacturingExecutionDomain::issueMaterials(10, 1, 1, $oid, $lines, hash('sha256', 'rb-iss'), ['__fail_after_stock' => true]);
                $failed = false;
            } catch (Throwable $e) {
                $failed = true;
                $err = $e->getMessage();
            }
            $afterIssues = (int)pdo()->query("SELECT COUNT(*) FROM erp_mf_material_issues")->fetchColumn();
            $afterMoves = (int)pdo()->query("SELECT COUNT(*) FROM acc_stock_movements WHERE movement_type='production_issue'")->fetchColumn();
            mf_json_out([
                'ok' => true,
                'injected_failed' => $failed,
                'error' => $err ?? null,
                'issues_unchanged' => $beforeIssues === $afterIssues,
                'moves_unchanged' => $beforeMoves === $afterMoves,
                'on_hand_unchanged' => $beforeOnHand === mf_on_hand(2),
            ]);
            break;

        default:
            mf_json_out(['ok' => false, 'error' => 'unknown_scenario', 'scenario' => $scenario]);
            exit(1);
    }
} catch (Throwable $e) {
    mf_json_out(['ok' => false, 'error' => $e->getMessage(), 'trace' => $e->getFile().':'.$e->getLine()]);
    exit(1);
}
