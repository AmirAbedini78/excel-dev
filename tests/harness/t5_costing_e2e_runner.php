<?php
declare(strict_types=1);
/**
 * T5 costing/valuation E2E runner — reuses AP disposable schema + CostingAccountingDomain.
 */
require_once __DIR__ . '/ap_e2e_bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Core/CostingAccountingDomain.php';

$scenario = $argv[1] ?? '';
$args = [];
if (isset($argv[2]) && $argv[2] !== '') {
    $args = json_decode($argv[2], true) ?: [];
}

function t5_connect(): void
{
    global $host, $port, $user, $pass, $dbName;
    DB::connect(['host' => $host, 'port' => $port, 'name' => $dbName, 'user' => $user, 'pass' => $pass]);
    ModuleRegistry::setP2p(true);
}

function t5_seed_issue(int $productionOrderId, int $itemId, string $qty, string $unitCost = '0.00'): int
{
    $db = pdo();
    $db->prepare("INSERT INTO acc_stock_movements
        (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,source_line_id,reference_no,status,created_by,created_at)
        VALUES (10,1,'2026-11-20','production_issue','out',1,?,?,?,'production_order',?,NULL,?,?,1,NOW())")
        ->execute([$itemId, $qty, $unitCost, $productionOrderId, 'ISSUE-' . $productionOrderId, 'posted']);
    return (int)$db->lastInsertId();
}

function t5_seed_fg(int $productionOrderId, int $itemId, string $qty): int
{
    $db = pdo();
    $db->prepare("INSERT INTO acc_stock_movements
        (workspace_id,company_id,movement_date,movement_type,direction,warehouse_id,item_id,quantity,unit_cost,source_type,source_id,source_line_id,reference_no,status,created_by,created_at)
        VALUES (10,1,'2026-11-20','production_fg_receipt','in',1,?,?,0,'production_order',?,NULL,?,?,1,NOW())")
        ->execute([$itemId, $qty, $productionOrderId, 'FG-' . $productionOrderId, 'posted']);
    return (int)$db->lastInsertId();
}

try {
    if (!in_array($scenario, ['reset', 'seed_po'], true)) {
        t5_connect();
    }
    switch ($scenario) {
        case 'reset':
            ap_e2e_reset_schema();
            try {
                pdo()->exec('DROP TABLE IF EXISTS `erp_costing_company_policy`');
            } catch (Throwable $e) { /* ignore */ }
            CostingAccountingDomain::migrate(pdo());
            ap_json_out([
                'ok' => true,
                'mysql_container' => 'erpsmart-p2p-mysql-test',
                'database' => getenv('O2C_DB_NAME') ?: 'erpsmart_t5_test',
            ]);
            break;

        case 'seed_po':
            ap_e2e_reset_schema();
            try {
                pdo()->exec('DROP TABLE IF EXISTS `erp_costing_company_policy`');
            } catch (Throwable $e) { /* ignore */ }
            CostingAccountingDomain::migrate(pdo());
            $po = ap_seed_po((int)($args['created_by'] ?? 1), (string)($args['qty'] ?? '10.0000'), (string)($args['price'] ?? '1000.00'));
            ap_json_out(['ok' => true, 'po_id' => $po]);
            break;

        case 'approve_po':
            PurchaseMatchingDomain::approveOrder(10, 1, (int)($args['user_id'] ?? 2), (int)$args['po_id']);
            ap_json_out(['ok' => true]);
            break;

        case 'receipt':
            $rid = ap_post_receipt((int)$args['po_id'], (string)($args['qty'] ?? '6.0000'), (int)($args['user_id'] ?? 1));
            ap_json_out(['ok' => true, 'receipt_id' => $rid]);
            break;

        case 'line_id':
            $st = pdo()->prepare('SELECT id FROM acc_purchase_lines WHERE workspace_id=10 AND purchase_doc_id=? ORDER BY id LIMIT 1');
            $st->execute([(int)$args['po_id']]);
            ap_json_out(['ok' => true, 'purchase_line_id' => (int)$st->fetchColumn()]);
            break;

        case 'submit_bill':
            $out = PurchaseMatchingDomain::submit(
                10, 1, (int)($args['user_id'] ?? 1), (int)$args['po_id'],
                (string)$args['invoice_no'], (string)($args['invoice_date'] ?? '2026-11-20'),
                (array)$args['lines']
            );
            ap_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'approve_bill':
            $out = PurchaseMatchingDomain::approve(10, 1, (int)($args['user_id'] ?? 2), (int)$args['bill_id']);
            ap_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'ap_set_map':
            ApPostingDomain::setCompanyMap(10, 1, (int)($args['user_id'] ?? 2), [
                'payable_account_id' => (int)($args['payable_account_id'] ?? 8),
                'purchase_account_id' => (int)($args['purchase_account_id'] ?? 9),
                'input_vat_account_id' => (int)($args['input_vat_account_id'] ?? 10),
                'clearing_account_id' => (int)($args['clearing_account_id'] ?? 0),
                'ppv_account_id' => (int)($args['ppv_account_id'] ?? 0),
            ]);
            ap_json_out(['ok' => true]);
            break;

        case 'ap_validate':
            $out = ApPostingDomain::validateInvoice(10, 1, (int)($args['user_id'] ?? 3), (int)$args['invoice_doc_id']);
            ap_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'ap_approve':
            $out = ApPostingDomain::approveInvoice(10, 1, (int)($args['user_id'] ?? 1), (int)$args['invoice_doc_id']);
            ap_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'ap_post':
            $out = ApPostingDomain::postSupplierInvoice(
                10, 1, (int)($args['user_id'] ?? 3), (int)$args['invoice_doc_id'],
                (string)($args['idempotency_key'] ?? '')
            );
            ap_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'stock_and_gl_counts':
            $stock = (int)pdo()->query("SELECT COUNT(*) FROM acc_stock_movements WHERE workspace_id=10 AND company_id=1 AND source_type='inventory_receipt'")->fetchColumn();
            $apV = (int)pdo()->query("SELECT COUNT(*) FROM acc_vouchers WHERE workspace_id=10 AND company_id=1 AND source_type='p2p_ap'")->fetchColumn();
            $grV = (int)pdo()->query("SELECT COUNT(*) FROM acc_vouchers WHERE workspace_id=10 AND company_id=1 AND (source_type LIKE '%receipt%' OR source_type='grni')")->fetchColumn();
            $apLinks = (int)pdo()->query('SELECT COUNT(*) FROM erp_ap_postings WHERE workspace_id=10 AND company_id=1')->fetchColumn();
            ap_json_out([
                'ok' => true,
                'stock_movements' => $stock,
                'ap_vouchers' => $apV,
                'receipt_gl_vouchers' => $grV,
                'ap_postings' => $apLinks,
            ]);
            break;

        case 'wip_fg_policy':
            ap_json_out(['ok' => true, 'result' => CostingAccountingDomain::wipFgGlPolicy(10, 1)]);
            break;

        case 'attempt_wip_fg_gl':
            ap_json_out(['ok' => true, 'result' => CostingAccountingDomain::attemptWipFgGlPosting(
                10, 1, (int)($args['user_id'] ?? 1),
                ['production_order_id' => (int)($args['production_order_id'] ?? 9001)]
            )]);
            break;

        case 'seed_unvalued_issue':
            // No AP invoice, zero unit_cost, no PO for item 99 → UNVALUED
            pdo()->exec("INSERT INTO acc_items (id,workspace_id,company_id,code,name) VALUES (99,10,1,'RAW-X','Unpriced')
                ON DUPLICATE KEY UPDATE name=VALUES(name)");
            $mid = t5_seed_issue((int)($args['production_order_id'] ?? 9001), 99, (string)($args['qty'] ?? '2.0000'), '0.00');
            $resolved = CostingAccountingDomain::resolveMovementValuation(10, 1, $mid);
            $rollup = CostingAccountingDomain::rollupProductionMaterialCost(10, 1, (int)($args['production_order_id'] ?? 9001));
            ap_json_out(['ok' => true, 'movement_id' => $mid, 'resolved' => $resolved, 'rollup' => $rollup]);
            break;

        case 'seed_actual_issue_flow':
            // Expect caller already posted AP for item 1; seed production issue + FG
            $poId = (int)($args['production_order_id'] ?? 9100);
            $mid = t5_seed_issue($poId, 1, (string)($args['qty'] ?? '2.0000'), '0.00');
            $fg = t5_seed_fg($poId, 1, (string)($args['fg_qty'] ?? '1.0000'));
            $resolved = CostingAccountingDomain::resolveMovementValuation(10, 1, $mid);
            $rollup = CostingAccountingDomain::rollupProductionMaterialCost(10, 1, $poId);
            $blockedFg = CostingAccountingDomain::resolveMovementValuation(10, 1, $fg);
            ap_json_out([
                'ok' => true,
                'issue_movement_id' => $mid,
                'fg_movement_id' => $fg,
                'resolved' => $resolved,
                'fg_resolved' => $blockedFg,
                'rollup' => $rollup,
            ]);
            break;

        case 'reconcile':
            ap_json_out(['ok' => true, 'result' => CostingAccountingDomain::reconcileSubledgerVsGl(10, 1, [
                'party_id' => (int)($args['party_id'] ?? 0),
            ])]);
            break;

        case 'duplicate_keys':
            ap_json_out(['ok' => true, 'result' => CostingAccountingDomain::detectDuplicatePostingKeys(10, 1)]);
            break;

        case 'idempotent_repost':
            $inv = (int)$args['invoice_doc_id'];
            $key = (string)($args['idempotency_key'] ?? str_repeat('f', 32));
            $a = ApPostingDomain::postSupplierInvoice(10, 1, 3, $inv, $key);
            $b = ApPostingDomain::postSupplierInvoice(10, 1, 3, $inv, $key);
            $dups = CostingAccountingDomain::detectDuplicatePostingKeys(10, 1);
            ap_json_out(['ok' => true, 'first' => $a, 'second' => $b, 'duplicates' => $dups]);
            break;

        default:
            throw new RuntimeException('unknown_scenario:' . $scenario);
    }
} catch (Throwable $e) {
    ap_json_out(['ok' => false, 'error' => $e->getMessage(), 'type' => get_class($e)]);
    exit(0);
}
