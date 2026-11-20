<?php
declare(strict_types=1);
require_once __DIR__.'/ap_e2e_bootstrap.php';

$scenario = $argv[1] ?? '';
$args = [];
if (isset($argv[2]) && $argv[2] !== '') {
    $args = json_decode($argv[2], true) ?: [];
}

function ap_e2e_connect_reuse(): void
{
    global $host, $port, $user, $pass, $dbName;
    DB::connect(['host' => $host, 'port' => $port, 'name' => $dbName, 'user' => $user, 'pass' => $pass]);
    ModuleRegistry::setP2p(true);
}

try {
    if (!in_array($scenario, ['reset', 'seed_po'], true)) {
        ap_e2e_connect_reuse();
    }
    switch ($scenario) {
        case 'reset':
            ap_e2e_reset_schema();
            ApPostingDomain::migrate(pdo());
            ap_json_out(['ok' => true]);
            break;

        case 'seed_po':
            ap_e2e_reset_schema();
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

        case 'reject_bill':
            PurchaseMatchingDomain::reject(10, 1, (int)($args['user_id'] ?? 2), (int)$args['bill_id'], (string)($args['reason'] ?? 'over qty reject reason'));
            ap_json_out(['ok' => true]);
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

        case 'ap_set_map_company2':
            ApPostingDomain::setCompanyMap(10, 2, (int)($args['user_id'] ?? 2), [
                'payable_account_id' => 21,
                'purchase_account_id' => 22,
            ]);
            ap_json_out(['ok' => true]);
            break;

        case 'ar_set_map':
            AccountingPostingDomain::setCompanyMap(10, 1, (int)($args['user_id'] ?? 2), [
                'receivable_account_id' => 1,
                'revenue_account_id' => 2,
                'tax_account_id' => 3,
            ]);
            ap_json_out(['ok' => true]);
            break;

        case 'ap_validate':
            $out = ApPostingDomain::validateInvoice(10, 1, (int)($args['user_id'] ?? 2), (int)$args['invoice_doc_id']);
            ap_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'ap_approve':
            $out = ApPostingDomain::approveInvoice(10, 1, (int)($args['user_id'] ?? 3), (int)$args['invoice_doc_id']);
            ap_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'ap_post':
            $out = ApPostingDomain::postSupplierInvoice(
                10, 1, (int)($args['user_id'] ?? 2), (int)$args['invoice_doc_id'],
                (string)($args['idempotency_key'] ?? '')
            );
            ap_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'ap_reverse':
            $out = ApPostingDomain::reversePostedInvoice(10, 1, (int)($args['user_id'] ?? 2), (int)$args['invoice_doc_id'], (string)($args['reversal_date'] ?? '2026-11-21'));
            ap_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'ap_outstanding':
            ap_json_out(['ok' => true, 'result' => ApPostingDomain::supplierOutstanding(10, 1, (int)($args['party_id'] ?? 1))]);
            break;

        case 'ap_invoice_outstanding':
            ap_json_out(['ok' => true, 'result' => ApPostingDomain::invoiceOutstanding(10, 1, (int)$args['invoice_doc_id'])]);
            break;

        case 'ap_readiness':
            ap_json_out(['ok' => true, 'result' => ApPostingDomain::assessReadiness(10, 1)]);
            break;

        case 'voucher_lines':
            $st = pdo()->prepare('SELECT account_id,party_id,debit,credit FROM acc_voucher_lines WHERE workspace_id=10 AND voucher_id=? ORDER BY line_no');
            $st->execute([(int)$args['voucher_id']]);
            ap_json_out(['ok' => true, 'rows' => $st->fetchAll()]);
            break;

        case 'voucher_balance':
            $st = pdo()->prepare('SELECT total_debit,total_credit,source_type,status FROM acc_vouchers WHERE workspace_id=10 AND company_id=1 AND id=?');
            $st->execute([(int)$args['voucher_id']]);
            $v = $st->fetch();
            $pc = pdo()->prepare('SELECT COUNT(*) FROM erp_ap_postings WHERE workspace_id=10 AND company_id=1 AND voucher_id=?');
            $pc->execute([(int)$args['voucher_id']]);
            ap_json_out(['ok' => true, 'voucher' => $v, 'ap_posting_links' => (int)$pc->fetchColumn()]);
            break;

        case 'stock_and_gl_counts':
            $stock = (int)pdo()->query("SELECT COUNT(*) FROM acc_stock_movements WHERE workspace_id=10 AND company_id=1 AND source_type='inventory_receipt'")->fetchColumn();
            $apV = (int)pdo()->query("SELECT COUNT(*) FROM acc_vouchers WHERE workspace_id=10 AND company_id=1 AND source_type='p2p_ap'")->fetchColumn();
            $grV = (int)pdo()->query("SELECT COUNT(*) FROM acc_vouchers WHERE workspace_id=10 AND company_id=1 AND source_type LIKE '%receipt%'")->fetchColumn();
            ap_json_out(['ok' => true, 'stock_movements' => $stock, 'ap_vouchers' => $apV, 'receipt_gl_vouchers' => $grV]);
            break;

        case 'close_period':
            $fy = pdo()->query("SELECT id FROM acc_fiscal_years WHERE workspace_id=10 AND company_id=1 AND status='open' LIMIT 1")->fetchColumn();
            $period = (string)($args['period_key'] ?? '2026-11');
            pdo()->prepare("INSERT INTO acc_period_closes (workspace_id,company_id,fiscal_year_id,period_key,status,closed_by,closed_at,created_at,updated_at)
                VALUES (10,1,?,?,'closed',2,NOW(),NOW(),NOW()) ON DUPLICATE KEY UPDATE status='closed'")
                ->execute([(int)$fy, $period]);
            ap_json_out(['ok' => true]);
            break;

        case 'tamper_invoice_line':
            pdo()->prepare('UPDATE acc_purchase_lines SET unit_price=unit_price+1, line_total=line_total+1 WHERE workspace_id=10 AND purchase_doc_id=? LIMIT 1')
                ->execute([(int)$args['invoice_doc_id']]);
            pdo()->prepare('UPDATE acc_purchase_docs SET net_total=net_total+1, total_before_discount=total_before_discount+1 WHERE id=? AND workspace_id=10')
                ->execute([(int)$args['invoice_doc_id']]);
            ap_json_out(['ok' => true]);
            break;

        case 'fail_inject_post':
            // Simulate mid-transaction failure by using invalid account after approve path setup — call domain with wrong company.
            try {
                ApPostingDomain::postSupplierInvoice(10, 2, 2, (int)$args['invoice_doc_id'], 'ff'.str_repeat('0', 30));
                ap_json_out(['ok' => false, 'error' => 'expected_failure']);
            } catch (Throwable $e) {
                $pc = (int)pdo()->query('SELECT COUNT(*) FROM erp_ap_postings')->fetchColumn();
                $vc = (int)pdo()->query("SELECT COUNT(*) FROM acc_vouchers WHERE source_type='p2p_ap'")->fetchColumn();
                ap_json_out(['ok' => true, 'caught' => $e->getMessage(), 'ap_postings' => $pc, 'ap_vouchers' => $vc]);
            }
            break;

        case 'query':
            $sql = (string)($args['sql'] ?? '');
            if (!preg_match('/^\s*SELECT\b/i', $sql)) {
                throw new RuntimeException('select_only');
            }
            ap_json_out(['ok' => true, 'rows' => pdo()->query($sql)->fetchAll()]);
            break;

        case 'concurrent_post':
            // Sequential lock simulation: first post wins; second recovers as existing.
            // Poster must not be invoice created_by (P2P reviewer=2) or AP approver(1).
            $inv = (int)$args['invoice_doc_id'];
            $a = ApPostingDomain::postSupplierInvoice(10, 1, 3, $inv, 'aa'.str_repeat('1', 30));
            $b = ApPostingDomain::postSupplierInvoice(10, 1, 3, $inv, 'aa'.str_repeat('1', 30));
            ap_json_out(['ok' => true, 'first' => $a, 'second' => $b]);
            break;

        default:
            throw new RuntimeException('unknown_scenario:'.$scenario);
    }
} catch (Throwable $e) {
    ap_json_out(['ok' => false, 'error' => $e->getMessage(), 'type' => get_class($e)]);
    exit(0);
}
