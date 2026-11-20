<?php
/**
 * RC1 TR-2 full scenario in one PHP process.
 * SO 10×100 IRR (=1000) → reserve → deliver → AR post → receipts 400+600 → settle 0.
 */
declare(strict_types=1);

require_once __DIR__ . '/o2c_e2e_bootstrap.php';

function rc1_tr2_out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
}

try {
    o2c_e2e_reset_schema();

    $so = o2c_e2e_create_draft_order(1, '10.0000', '100.00');
    Auth::setUser(['id' => 2, 'is_platform_admin' => 1]);
    OrderToCashDomain::confirmOrder(10, 1, 2, $so);

    $st = pdo()->prepare('SELECT id FROM acc_sales_lines WHERE workspace_id=10 AND sales_doc_id=? ORDER BY id LIMIT 1');
    $st->execute([$so]);
    $line = (int)$st->fetchColumn();

    SalesDomain::reserveStock(10, 1, 2, [
        'sales_doc_id' => $so,
        'warehouse_id' => 1,
        'lines' => [['sales_line_id' => $line, 'quantity' => '10.0000']],
    ]);

    $deliv = SalesDomain::deliverStock(10, 1, 2, [
        'sales_doc_id' => $so,
        'warehouse_id' => 1,
        'delivery_date' => '2026-11-20',
        'idempotency_key' => str_repeat('a', 32),
        'lines' => [['sales_line_id' => $line, 'quantity' => '10.0000']],
    ]);
    $deliveryId = (int)$deliv['id'];

    Auth::setUser(['id' => 1, 'is_platform_admin' => 1]);
    $inv = OrderToCashDomain::createInvoiceFromDelivery(
        10,
        1,
        1,
        $so,
        $deliveryId,
        [(string)$line => ['quantity' => '10.0000']],
        str_repeat('b', 32)
    );
    $invId = (int)$inv['id'];

    AccountingPostingDomain::setCompanyMap(10, 1, 2, [
        'receivable_account_id' => 1,
        'revenue_account_id' => 2,
        'tax_account_id' => 3,
    ]);
    ArSettlementDomain::setCashAccountGl(10, 1, 2, 1, 6);
    AccountingPostingDomain::postCustomerInvoice(10, 1, 2, $invId, str_repeat('c', 32));

    $recv = function (string $amount, string $key): int {
        $draft = ArSettlementDomain::createDraftReceipt(10, 1, 1, [
            'party_id' => 1,
            'cash_account_id' => 1,
            'receipt_date' => '2026-11-20',
            'amount' => $amount,
            'idempotency_key' => $key,
        ]);
        $rid = (int)$draft['id'];
        ArSettlementDomain::approveReceipt(10, 1, 2, $rid);
        ArSettlementDomain::postReceipt(10, 1, 2, $rid);
        return $rid;
    };

    $r1 = $recv('400.00', str_repeat('d', 32));
    $a1 = ArSettlementDomain::allocate(10, 1, 2, $r1, $invId, '400.00', str_repeat('e', 32));
    if (($a1['invoice']['open_amount'] ?? '') !== '600.00') {
        throw new RuntimeException('expected_open_600 got=' . json_encode($a1));
    }

    $r2 = $recv('600.00', str_repeat('f', 32));
    $a2 = ArSettlementDomain::allocate(10, 1, 2, $r2, $invId, '600.00', str_repeat('11', 16));
    if (($a2['invoice']['status'] ?? '') !== 'SETTLED' || ($a2['invoice']['open_amount'] ?? '') !== '0.00') {
        throw new RuntimeException('expected_settled got=' . json_encode($a2));
    }

    $out = AccountingPostingDomain::customerOutstanding(10, 1, 1);
    if (($out['outstanding'] ?? '') !== '0.00') {
        throw new RuntimeException('expected_ar_0 got=' . json_encode($out));
    }

    rc1_tr2_out([
        'ok' => true,
        'contract' => 'TR-2',
        'status' => 'PASS',
        'sales_doc_id' => $so,
        'invoice_doc_id' => $invId,
        'invoice_amount_irr' => '1000.00',
        'receipts' => ['400.00', '600.00'],
        'outstanding' => $out['outstanding'],
        'currency' => 'IRR',
    ]);
} catch (Throwable $e) {
    rc1_tr2_out(['ok' => false, 'contract' => 'TR-2', 'status' => 'FAIL', 'error' => $e->getMessage(), 'type' => get_class($e)]);
    exit(1);
}
