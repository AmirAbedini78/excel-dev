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
    if (!in_array($scenario, ['reset', 'seed_po', 'trading_tr1_full'], true)) {
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

        case 'payment_draft':
            $out = ApSettlementDomain::createDraftPayment(10, 1, (int)($args['user_id'] ?? 1), [
                'party_id' => (int)($args['party_id'] ?? 1),
                'cash_account_id' => (int)($args['cash_account_id'] ?? 1),
                'payment_method' => (string)($args['payment_method'] ?? 'bank_transfer'),
                'payment_date' => (string)($args['payment_date'] ?? '2026-11-20'),
                'amount' => (string)$args['amount'],
                'idempotency_key' => (string)($args['idempotency_key'] ?? ''),
                'description' => (string)($args['description'] ?? ''),
            ]);
            ap_json_out(['ok' => true, 'result' => $out]);
            break;

        case 'payment_approve':
            ap_json_out(['ok' => true, 'result' => ApSettlementDomain::approvePayment(10, 1, (int)($args['user_id'] ?? 2), (int)$args['payment_id'])]);
            break;

        case 'payment_post':
            ap_json_out(['ok' => true, 'result' => ApSettlementDomain::postPayment(10, 1, (int)($args['user_id'] ?? 2), (int)$args['payment_id'])]);
            break;

        case 'ap_allocate':
            ap_json_out(['ok' => true, 'result' => ApSettlementDomain::allocate(
                10, 1, (int)($args['user_id'] ?? 1), (int)$args['payment_id'], (int)$args['invoice_doc_id'],
                (string)$args['amount'], (string)($args['idempotency_key'] ?? '')
            )]);
            break;

        case 'trading_cockpit':
            $life = TradingCockpitDomain::poLifecycle(10, 1, (int)$args['po_id']);
            ap_json_out(['ok' => true, 'result' => $life]);
            break;

        case 'trading_search':
            ap_json_out(['ok' => true, 'result' => TradingCockpitDomain::searchDomestic(10, 1, (string)($args['query'] ?? ''), (int)($args['limit'] ?? 50))]);
            break;

        case 'trading_dup_invoice':
            ap_json_out(['ok' => true, 'result' => TradingCockpitDomain::findSupplierInvoiceDuplicates(
                10, 1, (int)($args['supplier_id'] ?? 1), (string)($args['invoice_no'] ?? '')
            )]);
            break;

        case 'trading_foreign_assessment':
            ap_json_out(['ok' => true, 'result' => TradingCockpitDomain::foreignTradeAssessment(10, 1, isset($args['case_id']) ? (int)$args['case_id'] : null)]);
            break;

        case 'trading_tr1_full':
            // Single-container TR-1 proof: PO10@1000 → GR6 → inv4+2 → AP6000 → pay2500+3500 → 0.
            // Connect first for advisory lock, then rebuild schema under lock (shared sandbox).
            global $host, $port, $user, $pass, $dbName;
            DB::connect(['host' => $host, 'port' => $port, 'name' => $dbName, 'user' => $user, 'pass' => $pass]);
            $lock = pdo()->query("SELECT GET_LOCK('erpsmart_t3_tr1', 90)")->fetchColumn();
            if ((int)$lock !== 1) {
                throw new RuntimeException('could_not_acquire_tr1_test_lock');
            }
            try {
                ap_e2e_reset_schema();
                // reset_schema reconnects (new PDO); re-acquire lock on the active connection.
                $lock2 = pdo()->query("SELECT GET_LOCK('erpsmart_t3_tr1', 90)")->fetchColumn();
                if ((int)$lock2 !== 1) {
                    throw new RuntimeException('could_not_reacquire_tr1_test_lock');
                }
                $po = ap_seed_po(1, '10.0000', '1000.00');
                PurchaseMatchingDomain::approveOrder(10, 1, 2, $po);
                $receiptId = ap_post_receipt($po, '6.0000', 1);
                $st = pdo()->prepare('SELECT id FROM acc_purchase_lines WHERE workspace_id=10 AND purchase_doc_id=? ORDER BY id LIMIT 1');
                $st->execute([$po]);
                $line = (int)$st->fetchColumn();

                $b1 = PurchaseMatchingDomain::submit(10, 1, 1, $po, 'RC1-T-INV-4', '2026-11-20', [
                    (string)$line => ['quantity' => '4.0000', 'unit_price' => '1000.00'],
                ]);
                $dupError = null;
                $dupErrorCode = null;
                try {
                    PurchaseMatchingDomain::submit(10, 1, 1, $po, 'RC1-T-INV-4', '2026-11-20', [
                        (string)$line => ['quantity' => '1.0000', 'unit_price' => '1000.00'],
                    ]);
                    $dupError = 'expected_duplicate_failure';
                } catch (Throwable $e) {
                    $dupError = $e->getMessage();
                    if (preg_match('/^([A-Z][A-Z0-9_]+):/u', $dupError, $m)) {
                        $dupErrorCode = $m[1];
                    }
                }
                $inv4 = (int)PurchaseMatchingDomain::approve(10, 1, 2, (int)$b1['id'])['id'];
                $b2 = PurchaseMatchingDomain::submit(10, 1, 1, $po, 'RC1-T-INV-2', '2026-11-20', [
                    (string)$line => ['quantity' => '2.0000', 'unit_price' => '1000.00'],
                ]);
                $inv2 = (int)PurchaseMatchingDomain::approve(10, 1, 2, (int)$b2['id'])['id'];

                ApPostingDomain::setCompanyMap(10, 1, 2, [
                    'payable_account_id' => 8,
                    'purchase_account_id' => 9,
                    'input_vat_account_id' => 10,
                    'clearing_account_id' => 0,
                    'ppv_account_id' => 0,
                ]);
                ApPostingDomain::validateInvoice(10, 1, 3, $inv4);
                ApPostingDomain::approveInvoice(10, 1, 1, $inv4);
                ApPostingDomain::postSupplierInvoice(10, 1, 3, $inv4, str_repeat('a', 32));
                ApPostingDomain::validateInvoice(10, 1, 3, $inv2);
                ApPostingDomain::approveInvoice(10, 1, 1, $inv2);
                ApPostingDomain::postSupplierInvoice(10, 1, 3, $inv2, str_repeat('b', 32));
                $outAfterAp = ApPostingDomain::supplierOutstanding(10, 1, 1);

                $pay1 = ApSettlementDomain::createDraftPayment(10, 1, 1, [
                    'party_id' => 1,
                    'cash_account_id' => 1,
                    'payment_method' => 'bank_transfer',
                    'payment_date' => '2026-11-20',
                    'amount' => '2500.00',
                    'idempotency_key' => str_repeat('c', 32),
                ]);
                ApSettlementDomain::approvePayment(10, 1, 2, (int)$pay1['id']);
                ApSettlementDomain::postPayment(10, 1, 2, (int)$pay1['id']);
                ApSettlementDomain::allocate(10, 1, 1, (int)$pay1['id'], $inv4, '2500.00', str_repeat('d', 32));
                $outAfterPay1 = ApPostingDomain::supplierOutstanding(10, 1, 1);
                $inv4AfterPay1 = ApPostingDomain::invoiceOutstanding(10, 1, $inv4);

                $pay2 = ApSettlementDomain::createDraftPayment(10, 1, 1, [
                    'party_id' => 1,
                    'cash_account_id' => 1,
                    'payment_method' => 'bank_transfer',
                    'payment_date' => '2026-11-20',
                    'amount' => '3500.00',
                    'idempotency_key' => str_repeat('e', 32),
                ]);
                ApSettlementDomain::approvePayment(10, 1, 2, (int)$pay2['id']);
                ApSettlementDomain::postPayment(10, 1, 2, (int)$pay2['id']);
                ApSettlementDomain::allocate(10, 1, 1, (int)$pay2['id'], $inv4, '1500.00', str_repeat('f', 32));
                ApSettlementDomain::allocate(10, 1, 1, (int)$pay2['id'], $inv2, '2000.00', str_repeat('1', 32));

                $outFinal = ApPostingDomain::supplierOutstanding(10, 1, 1);
                $inv4Final = ApPostingDomain::invoiceOutstanding(10, 1, $inv4);
                $inv2Final = ApPostingDomain::invoiceOutstanding(10, 1, $inv2);
                $stock = (int)pdo()->query("SELECT COUNT(*) FROM acc_stock_movements WHERE workspace_id=10 AND company_id=1 AND source_type='inventory_receipt'")->fetchColumn();
                $grV = (int)pdo()->query("SELECT COUNT(*) FROM acc_vouchers WHERE workspace_id=10 AND company_id=1 AND source_type LIKE '%receipt%'")->fetchColumn();
                $life = TradingCockpitDomain::poLifecycle(10, 1, $po);
                $search = TradingCockpitDomain::searchDomestic(10, 1, 'RC1-T-INV', 50);
                $dup = TradingCockpitDomain::findSupplierInvoiceDuplicates(10, 1, 1, 'RC1-T-INV-4');
                $foreign = TradingCockpitDomain::foreignTradeAssessment(10, 1, null);

                ap_json_out([
                    'ok' => true,
                    'po_id' => $po,
                    'receipt_id' => $receiptId,
                    'inv4' => $inv4,
                    'inv2' => $inv2,
                    'duplicate_error' => $dupError,
                    'duplicate_error_code' => $dupErrorCode,
                    'outstanding_after_ap' => $outAfterAp,
                    'outstanding_after_pay1' => $outAfterPay1,
                    'inv4_after_pay1' => $inv4AfterPay1,
                    'outstanding_final' => $outFinal,
                    'inv4_final' => $inv4Final,
                    'inv2_final' => $inv2Final,
                    'stock_movements' => $stock,
                    'receipt_gl_vouchers' => $grV,
                    'cockpit' => $life,
                    'search' => $search,
                    'dup' => $dup,
                    'foreign' => $foreign,
                ]);
            } finally {
                try {
                    pdo()->query("SELECT RELEASE_LOCK('erpsmart_t3_tr1')");
                } catch (Throwable $ignored) {
                }
            }
            break;

        default:
            throw new RuntimeException('unknown_scenario:'.$scenario);
    }
} catch (Throwable $e) {
    ap_json_out(['ok' => false, 'error' => $e->getMessage(), 'type' => get_class($e)]);
    exit(0);
}
